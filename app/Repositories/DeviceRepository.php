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

    public function physicalDevices(string $userId): array
    {
        $query = $this->db->prepare(
            'SELECT * FROM catch_devices WHERE user_id=:user ORDER BY updated_at DESC,name',
        );
        $query->execute(['user' => $userId]);
        $devices = $query->fetchAll();

        $clients = $this->all($userId);
        foreach ($devices as &$device) {
            $device['clients'] = array_values(array_filter(
                $clients,
                static fn (array $client): bool => $client['device_id'] === $device['id'],
            ));
            $device['client_count'] = count($device['clients']);
            $device['capture_count'] = array_sum(array_map(
                static fn (array $client): int => (int) $client['capture_count'],
                $device['clients'],
            ));
            $lastSeen = array_values(array_filter(array_map(
                static fn (array $client): ?string => $client['last_used_at'] ?: $client['last_seen_at'] ?: null,
                $device['clients'],
            )));
            $device['last_seen_at'] = $lastSeen ? max($lastSeen) : null;
        }
        unset($device);

        return $devices;
    }

    public function unassignedClients(string $userId): array
    {
        return array_values(array_filter(
            $this->all($userId),
            static fn (array $client): bool => empty($client['device_id']),
        ));
    }

    public function findPhysicalDevice(string $deviceId, string $userId): ?array
    {
        $query = $this->db->prepare('SELECT * FROM catch_devices WHERE id=:id AND user_id=:user LIMIT 1');
        $query->execute(['id' => $deviceId, 'user' => $userId]);

        return $query->fetch() ?: null;
    }

    public function createPhysicalDevice(string $userId, string $name, string $deviceType): array
    {
        $name = mb_substr(trim($name), 0, 120);
        if ($name === '' || !in_array($deviceType, ['laptop', 'phone', 'pc', 'tablet'], true)) {
            throw new \InvalidArgumentException('Enter a device name and choose a supported device type.');
        }

        $id = Id::uuid();
        $query = $this->db->prepare(<<<'SQL'
            INSERT INTO catch_devices (id,user_id,name,device_type,created_at,updated_at)
            VALUES (:id,:user,:name,:type,UTC_TIMESTAMP(6),UTC_TIMESTAMP(6))
            SQL);
        $query->execute(['id' => $id, 'user' => $userId, 'name' => $name, 'type' => $deviceType]);

        return $this->findPhysicalDevice($id, $userId)
            ?? throw new \RuntimeException('The device could not be created.');
    }

    public function assignClient(string $clientId, string $userId, ?string $deviceId): bool
    {
        $client = $this->find($clientId, $userId);
        if (!$client || $client['status'] === 'revoked'
            || ($deviceId !== null && !$this->findPhysicalDevice($deviceId, $userId))) {
            return false;
        }
        $query = $this->db->prepare(
            'UPDATE catch_clients SET device_id=:device WHERE id=:client AND user_id=:user',
        );
        $query->execute(['device' => $deviceId, 'client' => $clientId, 'user' => $userId]);

        return true;
    }

    public function all(string $userId): array
    {
        $query = $this->db->prepare(<<<'SQL'
            SELECT d.*,
                t.last_used_at,
                (SELECT COUNT(*) FROM catch_captures c WHERE c.client_id = d.id) AS capture_count,
                (SELECT MAX(c.created_at) FROM catch_captures c WHERE c.client_id = d.id) AS capture_last_used_at
            FROM catch_clients d
            LEFT JOIN catch_client_tokens t ON t.client_id = d.id
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
        $sql = 'SELECT d.*,physical.device_type physical_device_type,physical.name physical_device_name,p.code_encrypted,CASE WHEN p.created_at >= UTC_TIMESTAMP(6) - INTERVAL ' . self::PAIRING_CODE_TTL_MINUTES . ' MINUTE THEN DATE_FORMAT(DATE_ADD(p.created_at,INTERVAL ' . self::PAIRING_CODE_TTL_MINUTES . ' MINUTE),\'%Y-%m-%dT%H:%i:%sZ\') ELSE NULL END pairing_code_expires_at,t.last_used_at,(SELECT COUNT(*) FROM catch_captures c WHERE c.client_id=d.id) capture_count,(SELECT COUNT(*) FROM catch_sessions s WHERE s.client_id=d.id AND s.expires_at>UTC_TIMESTAMP(6)) session_count,(SELECT MAX(c.created_at) FROM catch_captures c WHERE c.client_id=d.id) capture_last_used_at FROM catch_clients d LEFT JOIN catch_devices physical ON physical.id=d.device_id LEFT JOIN catch_client_pairing_codes p ON p.client_id=d.id LEFT JOIN catch_client_tokens t ON t.client_id=d.id WHERE d.id=:id AND d.user_id=:user LIMIT 1';
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
        ?string $physicalDeviceId = null,
    ): array {
        if ($physicalDeviceId !== null && !$this->findPhysicalDevice($physicalDeviceId, $userId)) {
            throw new \InvalidArgumentException('Choose a device that belongs to this account.');
        }
        $id = Id::uuid();
        $name = mb_substr(trim($name), 0, 120);
        $deviceType = $this->deviceType($deviceType, $kind, $platform, $userAgent);
        $identity = $this->clientIdentity($clientType, $platform, $userAgent);
        $sql = <<<'SQL'
            INSERT INTO catch_clients (
                id, user_id, device_id, name, kind, device_type, client_type,
                platform, os, client_icon, user_agent, status, created_at
            ) VALUES (
                :id, :user, :device, :name, :kind, :device_type, :client_type,
                :platform, :os, :client_icon, :user_agent, 'setup', UTC_TIMESTAMP(6)
            )
            SQL;
        $query = $this->db->prepare($sql);
        $query->execute([
            'id' => $id,
            'user' => $userId,
            'device' => $physicalDeviceId,
            'name' => $name,
            'kind' => $kind,
            'device_type' => $deviceType,
            'client_type' => $clientType,
            'platform' => $platform,
            'os' => $identity['os'],
            'client_icon' => $identity['client_app'],
            'user_agent' => $userAgent,
        ]);

        return [
            'id' => $id,
            'device_id' => $physicalDeviceId,
            'name' => $name,
            'kind' => $kind,
            'device_type' => $deviceType,
            'client_type' => $clientType,
            'platform' => $platform,
            'os' => $identity['os'],
            'client_icon' => $identity['client_app'],
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
            $this->db->prepare('DELETE FROM catch_client_pairing_codes WHERE client_id=:client')->execute(['client' => $deviceId]);
            $this->db->prepare('INSERT INTO catch_client_pairing_codes (client_id,code_hash,code_encrypted,created_at) VALUES (:client,:hash,:encrypted,UTC_TIMESTAMP(6))')->execute(['client' => $deviceId,'hash' => hash('sha256', $plain),'encrypted' => $this->secrets->encrypt($display)]);
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
            $owned = $this->db->prepare('SELECT id FROM catch_clients WHERE id=:id AND user_id=:user AND status<>\'revoked\' FOR UPDATE');
            $owned->execute(['id' => $deviceId,'user' => $userId]);
            if (!$owned->fetchColumn()) {
                $this->db->commit();
                return false;
            }
            $this->db->prepare('DELETE FROM catch_client_tokens WHERE client_id=:client')->execute(['client' => $deviceId]);
            $this->db->prepare('DELETE FROM catch_client_pairing_codes WHERE client_id=:client')->execute(['client' => $deviceId]);
            $this->db->prepare('UPDATE catch_clients SET status=\'revoked\' WHERE id=:client')->execute(['client' => $deviceId]);
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
        $query = $this->db->prepare('SELECT d.status,d.connected_at,d.last_seen_at,EXISTS(SELECT 1 FROM catch_client_pairing_codes p WHERE p.client_id=d.id) pairing_code_active FROM catch_clients d WHERE d.id=:id AND d.user_id=:user LIMIT 1');
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
            $query = $this->db->prepare('SELECT d.id,d.user_id,d.device_id FROM catch_client_pairing_codes p JOIN catch_clients d ON d.id=p.client_id WHERE p.code_hash=:hash AND p.created_at >= UTC_TIMESTAMP(6) - INTERVAL ' . self::PAIRING_CODE_TTL_MINUTES . ' MINUTE AND d.status=\'setup\' LIMIT 1 FOR UPDATE');
            $query->execute(['hash' => hash('sha256', $normalized)]);
            $device = $query->fetch() ?: null;
            if (!$device) {
                $this->db->prepare('DELETE FROM catch_client_pairing_codes WHERE code_hash=:hash AND created_at < UTC_TIMESTAMP(6) - INTERVAL ' . self::PAIRING_CODE_TTL_MINUTES . ' MINUTE')->execute(['hash' => hash('sha256', $normalized)]);
                $this->db->commit();
                return null;
            }
            $token = 'catch_device_' . rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
            $tokenId = Id::uuid();
            $this->db->prepare('INSERT INTO catch_client_tokens (id,client_id,token_hash,token_scope,created_at) VALUES (:id,:client,:hash,\'capture:write\',UTC_TIMESTAMP(6))')->execute(['id' => $tokenId,'client' => $device['id'],'hash' => hash('sha256', $token)]);
            $this->db->prepare('DELETE FROM catch_client_pairing_codes WHERE client_id=:client')->execute(['client' => $device['id']]);
            $this->db->prepare('UPDATE catch_clients SET status=\'connected\',connected_at=UTC_TIMESTAMP(6) WHERE id=:client')->execute(['client' => $device['id']]);
            $this->db->commit();
            return ['device_token' => $token,'client_id' => $device['id'],'device_id' => $device['device_id']];
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
        $name = $this->suggestedClientName($name, 'extension');
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

    public function approveExtensionPairingRequest(
        string $requestId,
        string $userId,
        string $deviceId,
        ?string $userAgent = null,
    ): ?array {
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
            if (!$this->findPhysicalDevice($deviceId, $userId)) {
                $this->db->commit();
                return null;
            }
            $clientId = Id::uuid();
            $tokenId = Id::uuid();
            $token = 'catch_device_' . rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
            $identity = $this->clientIdentity(
                'extension',
                (string) $pairing['platform'],
                $userAgent ?: $pairing['user_agent'],
            );
            $this->db->prepare('INSERT INTO catch_clients (id,user_id,device_id,name,kind,device_type,client_type,platform,os,client_icon,user_agent,status,created_at,connected_at) VALUES (:id,:user,:device,:name,\'desktop\',\'extension\',\'extension\',:platform,:os,:client_icon,:user_agent,\'connected\',UTC_TIMESTAMP(6),UTC_TIMESTAMP(6))')->execute([
                'id' => $clientId,
                'user' => $userId,
                'device' => $deviceId,
                'name' => $this->suggestedClientName((string) $pairing['device_name'], 'extension'),
                'platform' => $pairing['platform'],
                'os' => $identity['os'],
                'client_icon' => $identity['client_app'],
                'user_agent' => $userAgent ?: $pairing['user_agent'],
            ]);
            $this->db->prepare('INSERT INTO catch_client_tokens (id,client_id,token_hash,token_scope,created_at) VALUES (:id,:client,:hash,\'capture:write\',UTC_TIMESTAMP(6))')->execute(['id' => $tokenId,'client' => $clientId,'hash' => hash('sha256', $token)]);
            $this->db->prepare('UPDATE catch_extension_pairing_requests SET status=\'approved\',user_id=:user,client_id=:client,token_encrypted=:token,approved_at=UTC_TIMESTAMP(6) WHERE request_id=:request')->execute(['user' => $userId,'client' => $clientId,'token' => $this->secrets->encrypt($token),'request' => $requestId]);
            $this->db->commit();
            return ['client_id' => $clientId,'device_id' => $deviceId,'device_name' => $pairing['device_name'],'status' => 'approved'];
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
                if ($pairing['client_id']) {
                    $this->db->prepare('DELETE FROM catch_clients WHERE id=:client')->execute(['client' => $pairing['client_id']]);
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
            if (!$pairing['token_encrypted'] || !$pairing['client_id']) {
                $this->db->commit();
                return ['status' => 'invalid'];
            }
            $token = $this->secrets->decrypt((string)$pairing['token_encrypted']);
            $identity = $this->db->prepare('SELECT c.id client_id,c.name client_name,c.os,c.client_icon,c.device_id,d.name physical_device_name FROM catch_clients c LEFT JOIN catch_devices d ON d.id=c.device_id WHERE c.id=:client LIMIT 1');
            $identity->execute(['client' => $pairing['client_id']]);
            $client = $identity->fetch() ?: null;
            if (!$client) {
                $this->db->commit();
                return ['status' => 'invalid'];
            }
            $this->db->prepare('DELETE FROM catch_extension_pairing_requests WHERE request_id=:request')->execute(['request' => $requestId]);
            $this->db->commit();
            return ['status' => 'connected','device_token' => $token] + $client;
        } catch (\Throwable $error) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }throw $error;
        }
    }

    public function revokeForToken(string $token, ?string $clientType = null): bool
    {
        if (!str_starts_with($token, 'catch_device_') && !str_starts_with($token, 'catch_cli_')) {
            return false;
        }
        $sql = 'SELECT t.client_id FROM catch_client_tokens t JOIN catch_clients d ON d.id=t.client_id WHERE t.token_hash=:hash AND t.revoked_at IS NULL';
        if ($clientType !== null) {
            $sql .= ' AND d.client_type=:client_type';
        }
        $sql .= ' LIMIT 1';
        $query = $this->db->prepare($sql);
        $parameters = ['hash' => hash('sha256', $token)];
        if ($clientType !== null) {
            $parameters['client_type'] = $clientType;
        }
        $query->execute($parameters);
        $clientId = $query->fetchColumn();
        if (!$clientId) {
            return false;
        }
        $this->db->prepare('UPDATE catch_client_tokens SET revoked_at=UTC_TIMESTAMP(6) WHERE client_id=:client')->execute(['client' => $clientId]);
        $this->db->prepare('UPDATE catch_clients SET status=\'revoked\' WHERE id=:client')->execute(['client' => $clientId]);
        return true;
    }

    public function userForToken(string $token, string $requiredScope = 'capture:write'): ?array
    {
        if (!str_starts_with($token, 'catch_device_') && !str_starts_with($token, 'catch_cli_')) {
            return null;
        }
        $query = $this->db->prepare('SELECT u.id,u.email,u.display_name,d.id client_id,d.device_id,d.name client_name,d.name device_name,d.platform,d.os,d.client_icon,d.client_type,p.name physical_device_name,t.id token_id,t.token_scope FROM catch_client_tokens t JOIN catch_clients d ON d.id=t.client_id AND d.status=\'connected\' JOIN catch_users u ON u.id=d.user_id LEFT JOIN catch_devices p ON p.id=d.device_id WHERE t.token_hash=:hash AND t.revoked_at IS NULL AND (t.expires_at IS NULL OR t.expires_at>UTC_TIMESTAMP(6)) LIMIT 1');
        $query->execute(['hash' => hash('sha256', $token)]);
        $user = $query->fetch() ?: null;
        if ($user && !$this->scopeAllows((string) $user['token_scope'], $requiredScope)) {
            return null;
        }
        if ($user) {
            $this->db->prepare('UPDATE catch_client_tokens SET last_used_at=UTC_TIMESTAMP(6) WHERE id=:token')->execute(['token' => $user['token_id']]);
            $this->db->prepare('UPDATE catch_clients SET last_seen_at=UTC_TIMESTAMP(6) WHERE id=:client')->execute(['client' => $user['client_id']]);
        }
        return $user;
    }

    private function scopeAllows(string $granted, string $required): bool
    {
        if ($granted === 'full') {
            return true;
        }
        $scopes = preg_split('/[\s,]+/', trim($granted)) ?: [];

        return in_array($required, $scopes, true);
    }

    public function ensureWebClient(string $userId, ?string $clientId, string $userAgent): array
    {
        if ($clientId) {
            $client = $this->find($clientId, $userId);
            if ($client && $client['status'] === 'connected' && $client['client_type'] === 'web') {
                $this->db->prepare(
                    'UPDATE catch_clients SET last_seen_at = UTC_TIMESTAMP(6) WHERE id = :id',
                )->execute(['id' => $clientId]);
                $client['last_seen_at'] = gmdate('Y-m-d H:i:s');

                return $client;
            }
        }

        $info = BrowserInfo::fromUserAgent($userAgent);
        $id = Id::uuid();
        $deviceType = $this->deviceType(null, 'desktop', '', $userAgent);
        $sql = <<<'SQL'
            INSERT INTO catch_clients (
                id, user_id, device_id, name, kind, device_type, client_type, platform,
                os, client_icon, user_agent, status, created_at, connected_at, last_seen_at
            ) VALUES (
                :id, :user, NULL, :name, 'desktop', :device_type, 'web', :platform,
                :os, :client_icon, :user_agent, 'connected', UTC_TIMESTAMP(6), UTC_TIMESTAMP(6), UTC_TIMESTAMP(6)
            )
            SQL;
        $query = $this->db->prepare($sql);
        $query->execute([
            'id' => $id,
            'user' => $userId,
            'name' => $info['label'],
            'device_type' => $deviceType,
            'platform' => strtolower(str_replace(' ', '-', $info['browser'])),
            'os' => $info['osKey'],
            'client_icon' => $info['clientApp'],
            'user_agent' => mb_substr($userAgent, 0, 500),
        ]);

        return $this->find($id, $userId)
            ?? throw new \RuntimeException('The web client could not be registered.');
    }

    public function rename(string $clientId, string $userId, string $name, string $clientApp, string $os): bool
    {
        $name = mb_substr(trim($name), 0, 120);
        if (
            $name === ''
            || !in_array($clientApp, ['chrome', 'firefox', 'edge', 'safari', 'chrome-extension', 'firefox-addon', 'browser-extension', 'installed-web-app', 'web-app', 'shortcut', 'cli', 'api'], true)
            || !in_array($os, ['windows', 'macos', 'linux', 'ios', 'ipados', 'android', 'unknown'], true)
        ) {
            return false;
        }
        $client = $this->find($clientId, $userId);
        if (!$client || $client['status'] === 'revoked') {
            return false;
        }

        $query = $this->db->prepare(
            'UPDATE catch_clients SET name = :name, client_icon = :client_icon, os = :os '
            . "WHERE id = :id AND user_id = :user AND status <> 'revoked'",
        );
        $query->execute([
            'name' => $name,
            'client_icon' => $clientApp,
            'os' => $os,
            'id' => $clientId,
            'user' => $userId,
        ]);

        return true;
    }

    public function refreshExtensionInfo(string $clientId, string $name, string $userAgent): void
    {
        $info = BrowserInfo::fromUserAgent($userAgent);
        $query = $this->db->prepare("UPDATE catch_clients SET user_agent=:user_agent,os=:os,client_icon=:client_icon,name=CASE WHEN name IN ('Firefox extension','Chrome extension','Chromium browser extension','Browser extension') THEN :name ELSE name END WHERE id=:id AND client_type='extension'");
        $query->execute([
            'user_agent' => mb_substr($userAgent, 0, 500),
            'os' => $info['osKey'],
            'client_icon' => match ($info['clientApp']) {
                'firefox' => 'firefox-addon',
                'chrome' => 'chrome-extension',
                default => 'browser-extension',
            },
            'name' => $this->suggestedClientName($name, 'extension'),
            'id' => $clientId,
        ]);
    }

    /** @return array{os:string,client_app:string} */
    private function clientIdentity(string $clientType, string $platform, ?string $userAgent): array
    {
        $info = BrowserInfo::fromUserAgent((string) $userAgent);
        $os = $info['osKey'];
        if ($os === 'unknown') {
            $os = match (strtolower($platform)) {
                'windows', 'linux', 'android', 'ios', 'ipados', 'macos' => strtolower($platform),
                default => 'unknown',
            };
        }

        $clientApp = match (true) {
            $clientType === 'cli' => 'cli',
            $clientType === 'shortcut' => 'shortcut',
            $clientType === 'api' => 'api',
            $clientType === 'extension' && strtolower($platform) === 'firefox' => 'firefox-addon',
            $clientType === 'extension' && in_array(strtolower($platform), ['chrome', 'chromium'], true) => 'chrome-extension',
            $clientType === 'extension' => 'browser-extension',
            default => $info['clientApp'],
        };

        return ['os' => $os, 'client_app' => $clientApp];
    }

    public function sessionsForClient(string $clientId, string $userId): array
    {
        $query = $this->db->prepare(<<<'SQL'
            SELECT id,ip_address,user_agent,created_at,updated_at,expires_at
            FROM catch_sessions
            WHERE client_id=:client AND user_id=:user AND expires_at>UTC_TIMESTAMP(6)
            ORDER BY updated_at DESC
            SQL);
        $query->execute(['client' => $clientId, 'user' => $userId]);

        return $query->fetchAll();
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
        if (in_array($requested, ['laptop', 'phone', 'pc', 'tablet', 'extension', 'cli'], true)) {
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

    private function suggestedClientName(string $name, string $clientType): string
    {
        $name = trim($name);
        if ($name === '') {
            return $clientType === 'cli' ? 'Catch CLI' : 'Browser Extension';
        }

        if (preg_match('/\b' . preg_quote($clientType, '/') . '\b/i', $name) === 1) {
            return mb_substr($name, 0, 120);
        }

        $suggested = $clientType === 'cli'
            ? 'Catch CLI on ' . $name
            : $name . ' Extension';

        return mb_substr($suggested, 0, 120);
    }

    private function deleteExpiredPairingCode(string $deviceId, string $userId): void
    {
        $query = $this->db->prepare('DELETE p FROM catch_client_pairing_codes p JOIN catch_clients d ON d.id=p.client_id WHERE p.client_id=:client AND d.user_id=:user AND p.created_at < UTC_TIMESTAMP(6) - INTERVAL ' . self::PAIRING_CODE_TTL_MINUTES . ' MINUTE');
        $query->execute(['client' => $deviceId,'user' => $userId]);
    }

    private function deleteExpiredExtensionPairingRequests(): void
    {
        $this->db->exec('DELETE d FROM catch_clients d JOIN catch_extension_pairing_requests p ON p.client_id=d.id WHERE p.expires_at<UTC_TIMESTAMP(6)');
        $this->db->exec('DELETE FROM catch_extension_pairing_requests WHERE expires_at<UTC_TIMESTAMP(6)');
    }
}
