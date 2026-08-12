<?php

declare(strict_types=1);

namespace Catch\Repositories;

use Catch\Core\Id;
use Catch\Services\BrowserInfo;
use Catch\Services\SecretBox;
use PDO;

final class DeviceRepository
{
    public const PAIRING_CODE_DIGITS = 10;
    public const PAIRING_CODE_TTL_MINUTES = 15;
    public const EXTENSION_PAIRING_TTL_MINUTES = 10;

    public function __construct(private readonly PDO $db, private readonly SecretBox $secrets)
    {
    }

    public function all(string $userId): array
    {
        $query = $this->db->prepare(<<<'SQL'
            SELECT d.*,
                t.last_used_at,
                (SELECT COUNT(*) FROM catch_captures c WHERE c.device_id = d.id) AS capture_count,
                (SELECT MAX(c.created_at) FROM catch_captures c WHERE c.device_id = d.id) AS capture_last_used_at
            FROM catch_devices d
            LEFT JOIN catch_device_tokens t ON t.device_id = d.id
            WHERE d.user_id = :user
                AND d.status <> 'revoked'
            ORDER BY COALESCE(t.last_used_at, d.last_seen_at, d.created_at) DESC,
                d.created_at DESC
            SQL);
        $query->execute(['user' => $userId]);

        return $query->fetchAll();
    }

    public function find(string $deviceId, string $userId): ?array
    {
        $this->deleteExpiredPairingCode($deviceId, $userId);
        $sql = 'SELECT d.*,p.code_encrypted,CASE WHEN p.created_at >= UTC_TIMESTAMP(6) - INTERVAL ' . self::PAIRING_CODE_TTL_MINUTES . ' MINUTE THEN DATE_FORMAT(DATE_ADD(p.created_at,INTERVAL ' . self::PAIRING_CODE_TTL_MINUTES . ' MINUTE),\'%Y-%m-%dT%H:%i:%sZ\') ELSE NULL END pairing_code_expires_at,t.last_used_at,(SELECT COUNT(*) FROM catch_captures c WHERE c.device_id=d.id) capture_count,(SELECT MAX(c.created_at) FROM catch_captures c WHERE c.device_id=d.id) capture_last_used_at FROM catch_devices d LEFT JOIN catch_device_pairing_codes p ON p.device_id=d.id LEFT JOIN catch_device_tokens t ON t.device_id=d.id WHERE d.id=:id AND d.user_id=:user LIMIT 1';
        $query = $this->db->prepare($sql);
        $query->execute(['id' => $deviceId,'user' => $userId]);
        $device = $query->fetch() ?: null;
        if ($device && $device['code_encrypted'] && $device['pairing_code_expires_at']) {
            $code = $this->secrets->decrypt($device['code_encrypted']);
            if (preg_match('/^\d{5} \d{5}$/', $code)) {
                $device['pairing_code'] = $code;
            } else {
                $device['pairing_code_expires_at'] = null;
            }
        }
        if ($device) {
            unset($device['code_encrypted']);
        }
        return $device;
    }

    public function create(
        string $userId,
        string $name,
        string $kind,
        string $platform,
        string $clientType = 'shortcut',
        ?string $userAgent = null,
        ?string $deviceType = null,
    ): array {
        $id = Id::uuid();
        $name = mb_substr(trim($name), 0, 120);
        $deviceType = $this->deviceType($deviceType, $kind, $platform, $userAgent);
        $sql = <<<'SQL'
            INSERT INTO catch_devices (
                id, user_id, name, kind, device_type, client_type,
                platform, user_agent, status, created_at
            ) VALUES (
                :id, :user, :name, :kind, :device_type, :client_type,
                :platform, :user_agent, 'setup', UTC_TIMESTAMP(6)
            )
            SQL;
        $query = $this->db->prepare($sql);
        $query->execute([
            'id' => $id,
            'user' => $userId,
            'name' => $name,
            'kind' => $kind,
            'device_type' => $deviceType,
            'client_type' => $clientType,
            'platform' => $platform,
            'user_agent' => $userAgent,
        ]);

        return [
            'id' => $id,
            'name' => $name,
            'kind' => $kind,
            'device_type' => $deviceType,
            'client_type' => $clientType,
            'platform' => $platform,
            'user_agent' => $userAgent,
            'status' => 'setup',
        ];
    }

    public function createPairingCode(string $deviceId, string $userId): ?string
    {
        $device = $this->find($deviceId, $userId);
        if (!$device || $device['status'] !== 'setup') {
            return null;
        }
        [$plain,$display] = $this->newCode();
        $this->db->beginTransaction();
        try {
            $this->db->prepare('DELETE FROM catch_device_pairing_codes WHERE device_id=:device')->execute(['device' => $deviceId]);
            $this->db->prepare('INSERT INTO catch_device_pairing_codes (device_id,code_hash,code_encrypted,created_at) VALUES (:device,:hash,:encrypted,UTC_TIMESTAMP(6))')->execute(['device' => $deviceId,'hash' => hash('sha256', $plain),'encrypted' => $this->secrets->encrypt($display)]);
            $this->db->commit();
            return $display;
        } catch (\Throwable $error) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }throw $error;
        }
    }

    public function delete(string $deviceId, string $userId): bool
    {
        $this->db->beginTransaction();
        try {
            $owned = $this->db->prepare('SELECT id FROM catch_devices WHERE id=:id AND user_id=:user AND status<>\'revoked\' FOR UPDATE');
            $owned->execute(['id' => $deviceId,'user' => $userId]);
            if (!$owned->fetchColumn()) {
                $this->db->commit();
                return false;
            }
            $this->db->prepare('DELETE FROM catch_device_tokens WHERE device_id=:device')->execute(['device' => $deviceId]);
            $this->db->prepare('DELETE FROM catch_device_pairing_codes WHERE device_id=:device')->execute(['device' => $deviceId]);
            $this->db->prepare('UPDATE catch_devices SET status=\'revoked\' WHERE id=:device')->execute(['device' => $deviceId]);
            $this->db->commit();
            return true;
        } catch (\Throwable $error) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }throw $error;
        }
    }

    public function status(string $deviceId, string $userId): ?array
    {
        $this->deleteExpiredPairingCode($deviceId, $userId);
        $query = $this->db->prepare('SELECT d.status,d.connected_at,d.last_seen_at,EXISTS(SELECT 1 FROM catch_device_pairing_codes p WHERE p.device_id=d.id) pairing_code_active FROM catch_devices d WHERE d.id=:id AND d.user_id=:user LIMIT 1');
        $query->execute(['id' => $deviceId,'user' => $userId]);
        return $query->fetch() ?: null;
    }

    public function pair(string $code): ?array
    {
        $normalized = $this->normalizeCode($code);
        if ($normalized === null) {
            return null;
        }
        $this->db->beginTransaction();
        try {
            $query = $this->db->prepare('SELECT d.id,d.user_id FROM catch_device_pairing_codes p JOIN catch_devices d ON d.id=p.device_id WHERE p.code_hash=:hash AND p.created_at >= UTC_TIMESTAMP(6) - INTERVAL ' . self::PAIRING_CODE_TTL_MINUTES . ' MINUTE AND d.status=\'setup\' LIMIT 1 FOR UPDATE');
            $query->execute(['hash' => hash('sha256', $normalized)]);
            $device = $query->fetch() ?: null;
            if (!$device) {
                $this->db->prepare('DELETE FROM catch_device_pairing_codes WHERE code_hash=:hash AND created_at < UTC_TIMESTAMP(6) - INTERVAL ' . self::PAIRING_CODE_TTL_MINUTES . ' MINUTE')->execute(['hash' => hash('sha256', $normalized)]);
                $this->db->commit();
                return null;
            }
            $token = 'catch_device_' . rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
            $tokenId = Id::uuid();
            $this->db->prepare('INSERT INTO catch_device_tokens (id,device_id,token_hash,token_scope,created_at) VALUES (:id,:device,:hash,\'capture:write\',UTC_TIMESTAMP(6))')->execute(['id' => $tokenId,'device' => $device['id'],'hash' => hash('sha256', $token)]);
            $this->db->prepare('DELETE FROM catch_device_pairing_codes WHERE device_id=:device')->execute(['device' => $device['id']]);
            $this->db->prepare('UPDATE catch_devices SET status=\'connected\',connected_at=UTC_TIMESTAMP(6) WHERE id=:device')->execute(['device' => $device['id']]);
            $this->db->commit();
            return ['device_token' => $token,'device_id' => $device['id']];
        } catch (\Throwable $error) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }throw $error;
        }
    }

    public function createExtensionPairingRequest(string $name, string $platform, string $challenge, ?string $userAgent = null): array
    {
        $this->deleteExpiredExtensionPairingRequests();
        $requestId = bin2hex(random_bytes(24));
        $name = mb_substr(trim($name), 0, 120);
        $platform = mb_substr(trim($platform), 0, 32);
        $query = $this->db->prepare('INSERT INTO catch_extension_pairing_requests (request_id,code_challenge,device_name,platform,user_agent,status,expires_at,created_at) VALUES (:request,:challenge,:name,:platform,:user_agent,\'pending\',DATE_ADD(UTC_TIMESTAMP(6),INTERVAL ' . self::EXTENSION_PAIRING_TTL_MINUTES . ' MINUTE),UTC_TIMESTAMP(6))');
        $query->execute(['request' => $requestId,'challenge' => $challenge,'name' => $name,'platform' => $platform,'user_agent' => $userAgent]);
        return ['request_id' => $requestId,'device_name' => $name,'platform' => $platform,'expires_at' => gmdate(DATE_ATOM, time() + self::EXTENSION_PAIRING_TTL_MINUTES * 60)];
    }

    public function extensionPairingRequest(string $requestId): ?array
    {
        $this->deleteExpiredExtensionPairingRequests();
        if (!preg_match('/^[0-9a-f]{48}$/', $requestId)) {
            return null;
        }
        $query = $this->db->prepare('SELECT request_id,device_name,platform,status,DATE_FORMAT(expires_at,\'%Y-%m-%dT%H:%i:%sZ\') expires_at FROM catch_extension_pairing_requests WHERE request_id=:request LIMIT 1');
        $query->execute(['request' => $requestId]);
        return $query->fetch() ?: null;
    }

    public function approveExtensionPairingRequest(string $requestId, string $userId, ?string $userAgent = null): ?array
    {
        if (!preg_match('/^[0-9a-f]{48}$/', $requestId)) {
            return null;
        }
        $this->db->beginTransaction();
        try {
            $query = $this->db->prepare('SELECT * FROM catch_extension_pairing_requests WHERE request_id=:request AND expires_at>=UTC_TIMESTAMP(6) LIMIT 1 FOR UPDATE');
            $query->execute(['request' => $requestId]);
            $pairing = $query->fetch() ?: null;
            if (!$pairing || $pairing['status'] !== 'pending') {
                $this->db->commit();
                return null;
            }
            $deviceId = Id::uuid();
            $tokenId = Id::uuid();
            $token = 'catch_device_' . rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
            $this->db->prepare('INSERT INTO catch_devices (id,user_id,name,kind,device_type,client_type,platform,user_agent,status,created_at,connected_at) VALUES (:id,:user,:name,\'desktop\',\'pc\',\'extension\',:platform,:user_agent,\'connected\',UTC_TIMESTAMP(6),UTC_TIMESTAMP(6))')->execute(['id' => $deviceId,'user' => $userId,'name' => $pairing['device_name'],'platform' => $pairing['platform'],'user_agent' => $userAgent ?: $pairing['user_agent']]);
            $this->db->prepare('INSERT INTO catch_device_tokens (id,device_id,token_hash,token_scope,created_at) VALUES (:id,:device,:hash,\'capture:write\',UTC_TIMESTAMP(6))')->execute(['id' => $tokenId,'device' => $deviceId,'hash' => hash('sha256', $token)]);
            $this->db->prepare('UPDATE catch_extension_pairing_requests SET status=\'approved\',user_id=:user,device_id=:device,token_encrypted=:token,approved_at=UTC_TIMESTAMP(6) WHERE request_id=:request')->execute(['user' => $userId,'device' => $deviceId,'token' => $this->secrets->encrypt($token),'request' => $requestId]);
            $this->db->commit();
            return ['device_id' => $deviceId,'device_name' => $pairing['device_name'],'status' => 'approved'];
        } catch (\Throwable $error) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }throw $error;
        }
    }

    public function exchangeExtensionPairingRequest(string $requestId, string $verifier): array
    {
        if (!preg_match('/^[0-9a-f]{48}$/', $requestId) || !preg_match('/^[A-Za-z0-9_-]{43}$/', $verifier)) {
            return ['status' => 'invalid'];
        }
        $this->db->beginTransaction();
        try {
            $query = $this->db->prepare('SELECT *,expires_at<UTC_TIMESTAMP(6) expired FROM catch_extension_pairing_requests WHERE request_id=:request LIMIT 1 FOR UPDATE');
            $query->execute(['request' => $requestId]);
            $pairing = $query->fetch() ?: null;
            if (!$pairing) {
                $this->db->commit();
                return ['status' => 'invalid'];
            }
            if ((int)$pairing['expired'] === 1) {
                if ($pairing['device_id']) {
                    $this->db->prepare('DELETE FROM catch_devices WHERE id=:device')->execute(['device' => $pairing['device_id']]);
                } else {
                    $this->db->prepare('DELETE FROM catch_extension_pairing_requests WHERE request_id=:request')->execute(['request' => $requestId]);
                }
                $this->db->commit();
                return ['status' => 'expired'];
            }
            $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
            if (!hash_equals((string)$pairing['code_challenge'], $challenge)) {
                $this->db->commit();
                return ['status' => 'invalid_verifier'];
            }
            if ($pairing['status'] === 'pending') {
                $this->db->commit();
                return ['status' => 'pending'];
            }
            if (!$pairing['token_encrypted'] || !$pairing['device_id']) {
                $this->db->commit();
                return ['status' => 'invalid'];
            }
            $token = $this->secrets->decrypt((string)$pairing['token_encrypted']);
            $this->db->prepare('DELETE FROM catch_extension_pairing_requests WHERE request_id=:request')->execute(['request' => $requestId]);
            $this->db->commit();
            return ['status' => 'connected','device_token' => $token,'device_id' => $pairing['device_id'],'device_name' => $pairing['device_name']];
        } catch (\Throwable $error) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }throw $error;
        }
    }

    public function revokeForToken(string $token): bool
    {
        if (!str_starts_with($token, 'catch_device_')) {
            return false;
        }
        $query = $this->db->prepare('SELECT device_id FROM catch_device_tokens WHERE token_hash=:hash LIMIT 1');
        $query->execute(['hash' => hash('sha256', $token)]);
        $deviceId = $query->fetchColumn();
        if (!$deviceId) {
            return false;
        }
        $this->db->prepare('DELETE FROM catch_device_tokens WHERE device_id=:device')->execute(['device' => $deviceId]);
        $this->db->prepare('UPDATE catch_devices SET status=\'revoked\' WHERE id=:device')->execute(['device' => $deviceId]);
        return true;
    }

    public function userForToken(string $token, string $requiredScope = 'capture:write'): ?array
    {
        if (!str_starts_with($token, 'catch_device_')) {
            return null;
        }
        $query = $this->db->prepare('SELECT u.id,u.email,u.display_name,d.id device_id,d.name device_name,d.platform,d.client_type,t.id token_id,t.token_scope FROM catch_device_tokens t JOIN catch_devices d ON d.id=t.device_id AND d.status=\'connected\' JOIN catch_users u ON u.id=d.user_id WHERE t.token_hash=:hash LIMIT 1');
        $query->execute(['hash' => hash('sha256', $token)]);
        $user = $query->fetch() ?: null;
        if ($user && $requiredScope === 'full' && $user['token_scope'] !== 'full') {
            return null;
        }
        if ($user) {
            $this->db->prepare('UPDATE catch_device_tokens SET last_used_at=UTC_TIMESTAMP(6) WHERE id=:token')->execute(['token' => $user['token_id']]);
            $this->db->prepare('UPDATE catch_devices SET last_seen_at=UTC_TIMESTAMP(6) WHERE id=:device')->execute(['device' => $user['device_id']]);
        }
        return $user;
    }

    public function ensureWebDevice(string $userId, ?string $deviceId, string $userAgent): array
    {
        if ($deviceId) {
            $device = $this->find($deviceId, $userId);
            if ($device && $device['status'] === 'connected' && $device['client_type'] === 'web') {
                $this->db->prepare(
                    'UPDATE catch_devices SET last_seen_at = UTC_TIMESTAMP(6) WHERE id = :id',
                )->execute(['id' => $deviceId]);
                $device['last_seen_at'] = gmdate('Y-m-d H:i:s');

                return $device;
            }
        }

        $info = BrowserInfo::fromUserAgent($userAgent);
        $id = Id::uuid();
        $deviceType = $this->deviceType(null, 'desktop', '', $userAgent);
        $sql = <<<'SQL'
            INSERT INTO catch_devices (
                id, user_id, name, kind, device_type, client_type, platform,
                user_agent, status, created_at, connected_at, last_seen_at
            ) VALUES (
                :id, :user, :name, 'desktop', :device_type, 'web', :platform,
                :user_agent, 'connected', UTC_TIMESTAMP(6), UTC_TIMESTAMP(6), UTC_TIMESTAMP(6)
            )
            SQL;
        $query = $this->db->prepare($sql);
        $query->execute([
            'id' => $id,
            'user' => $userId,
            'name' => $info['label'],
            'device_type' => $deviceType,
            'platform' => strtolower(str_replace(' ', '-', $info['browser'])),
            'user_agent' => mb_substr($userAgent, 0, 500),
        ]);

        return $this->find($id, $userId)
            ?? throw new \RuntimeException('The web device could not be registered.');
    }

    public function rename(string $deviceId, string $userId, string $name, string $deviceType): bool
    {
        $name = mb_substr(trim($name), 0, 120);
        if ($name === '' || !in_array($deviceType, ['laptop', 'phone', 'pc', 'tablet'], true)) {
            return false;
        }

        $query = $this->db->prepare(
            'UPDATE catch_devices SET name = :name, device_type = :device_type '
            . "WHERE id = :id AND user_id = :user AND status <> 'revoked'",
        );
        $query->execute([
            'name' => $name,
            'device_type' => $deviceType,
            'id' => $deviceId,
            'user' => $userId,
        ]);

        return $query->rowCount() === 1;
    }

    public function refreshExtensionInfo(string $deviceId, string $name, string $userAgent): void
    {
        $query = $this->db->prepare("UPDATE catch_devices SET user_agent=:user_agent,name=CASE WHEN name IN ('Firefox extension','Chrome extension','Chromium browser extension','Browser extension') THEN :name ELSE name END WHERE id=:id AND client_type='extension'");
        $query->execute(['user_agent' => mb_substr($userAgent, 0, 500),'name' => mb_substr(trim($name), 0, 120),'id' => $deviceId]);
    }

    private function newCode(): array
    {
        $plain = (string)random_int(1, 9);
        for ($i = 1;$i < self::PAIRING_CODE_DIGITS;$i++) {
            $plain .= (string)random_int(0, 9);
        }
        return [$plain,substr($plain, 0, 5) . ' ' . substr($plain, 5)];
    }

    private function normalizeCode(string $code): ?string
    {
        if (preg_match('/[^\d\s-]/', $code)) {
            return null;
        }
        $normalized = preg_replace('/\D/', '', $code) ?? '';
        return strlen($normalized) === self::PAIRING_CODE_DIGITS && $normalized[0] !== '0' ? $normalized : null;
    }

    private function deviceType(
        ?string $requested,
        string $kind,
        string $platform,
        ?string $userAgent,
    ): string {
        if (in_array($requested, ['laptop', 'phone', 'pc', 'tablet'], true)) {
            return $requested;
        }

        $haystack = mb_strtolower($platform . ' ' . ($userAgent ?? ''));
        if (str_contains($haystack, 'ipad') || str_contains($haystack, 'tablet')) {
            return 'tablet';
        }

        if (
            $kind === 'mobile'
            || str_contains($haystack, 'iphone')
            || str_contains($haystack, 'android')
        ) {
            return 'phone';
        }

        return 'pc';
    }

    private function deleteExpiredPairingCode(string $deviceId, string $userId): void
    {
        $query = $this->db->prepare('DELETE p FROM catch_device_pairing_codes p JOIN catch_devices d ON d.id=p.device_id WHERE p.device_id=:device AND d.user_id=:user AND p.created_at < UTC_TIMESTAMP(6) - INTERVAL ' . self::PAIRING_CODE_TTL_MINUTES . ' MINUTE');
        $query->execute(['device' => $deviceId,'user' => $userId]);
    }

    private function deleteExpiredExtensionPairingRequests(): void
    {
        $this->db->exec('DELETE d FROM catch_devices d JOIN catch_extension_pairing_requests p ON p.device_id=d.id WHERE p.expires_at<UTC_TIMESTAMP(6)');
        $this->db->exec('DELETE FROM catch_extension_pairing_requests WHERE expires_at<UTC_TIMESTAMP(6)');
    }
}
