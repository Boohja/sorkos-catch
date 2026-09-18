<?php

declare(strict_types=1);

namespace Catch\Repositories;

use Catch\Core\Id;
use PDO;

final class CliAuthRepository
{
    public const AUTH_TTL_MINUTES = 10;

    public function __construct(private readonly PDO $db)
    {
    }

    public function start(string $deviceName, string $platform, string $challenge): array
    {
        $this->deleteExpired();
        $loginId = bin2hex(random_bytes(24));
        $deviceName = $this->suggestedDeviceName($deviceName);
        $platform = mb_substr(trim($platform), 0, 32);
        $query = $this->db->prepare('INSERT INTO catch_cli_auth_requests (login_id,code_challenge,device_name,platform,status,expires_at,created_at) VALUES (:login,:challenge,:name,:platform,\'pending\',DATE_ADD(UTC_TIMESTAMP(6),INTERVAL ' . self::AUTH_TTL_MINUTES . ' MINUTE),UTC_TIMESTAMP(6))');
        $query->execute(['login' => $loginId, 'challenge' => $challenge, 'name' => $deviceName, 'platform' => $platform]);

        return ['login_id' => $loginId, 'device_name' => $deviceName, 'platform' => $platform, 'expires_at' => gmdate(DATE_ATOM, time() + self::AUTH_TTL_MINUTES * 60)];
    }

    public function find(string $loginId): ?array
    {
        $this->deleteExpired();
        if (!$this->validId($loginId)) {
            return null;
        }
        $query = $this->db->prepare('SELECT login_id,device_name,platform,status,DATE_FORMAT(expires_at,\'%Y-%m-%dT%H:%i:%sZ\') expires_at FROM catch_cli_auth_requests WHERE login_id=:login LIMIT 1');
        $query->execute(['login' => $loginId]);

        return $query->fetch() ?: null;
    }

    public function approve(string $loginId, string $userId, string $deviceId): ?array
    {
        if (!$this->validId($loginId)) {
            return null;
        }
        $this->db->beginTransaction();
        try {
            $query = $this->db->prepare('SELECT * FROM catch_cli_auth_requests WHERE login_id=:login AND expires_at>=UTC_TIMESTAMP(6) LIMIT 1 FOR UPDATE');
            $query->execute(['login' => $loginId]);
            $request = $query->fetch() ?: null;
            if (!$request || $request['status'] !== 'pending') {
                $this->db->commit();
                return null;
            }
            $device = $this->db->prepare('SELECT id FROM catch_devices WHERE id=:device AND user_id=:user LIMIT 1 FOR UPDATE');
            $device->execute(['device' => $deviceId, 'user' => $userId]);
            if (!$device->fetchColumn()) {
                $this->db->commit();
                return null;
            }
            $clientId = Id::uuid();
            $this->db->prepare('INSERT INTO catch_clients (id,user_id,device_id,name,kind,device_type,client_type,platform,os,client_icon,status,created_at,connected_at) VALUES (:id,:user,:device,:name,\'desktop\',\'cli\',\'cli\',:platform,:os,\'cli\',\'connected\',UTC_TIMESTAMP(6),UTC_TIMESTAMP(6))')->execute([
                'id' => $clientId,
                'user' => $userId,
                'device' => $deviceId,
                'name' => $this->suggestedDeviceName((string) $request['device_name']),
                'platform' => $request['platform'],
                'os' => $request['platform'],
            ]);
            $this->db->prepare('UPDATE catch_cli_auth_requests SET status=\'approved\',user_id=:user,client_id=:client,approved_at=UTC_TIMESTAMP(6) WHERE login_id=:login')->execute(['user' => $userId, 'client' => $clientId, 'login' => $loginId]);
            $this->db->commit();

            return ['client_id' => $clientId, 'device_id' => $deviceId, 'device_name' => $request['device_name']];
        } catch (\Throwable $error) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $error;
        }
    }

    public function exchange(string $loginId, string $verifier): array
    {
        if (!$this->validId($loginId) || !preg_match('/^[A-Za-z0-9_-]{43}$/', $verifier)) {
            return ['status' => 'invalid'];
        }
        $this->db->beginTransaction();
        try {
            $query = $this->db->prepare('SELECT *,expires_at<UTC_TIMESTAMP(6) expired FROM catch_cli_auth_requests WHERE login_id=:login LIMIT 1 FOR UPDATE');
            $query->execute(['login' => $loginId]);
            $request = $query->fetch() ?: null;
            if (!$request) {
                $this->db->commit();
                return ['status' => 'invalid'];
            }
            if ((int) $request['expired'] === 1) {
                $this->deleteRequestAndClient($loginId, $request['client_id']);
                $this->db->commit();
                return ['status' => 'expired'];
            }
            $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
            if (!hash_equals((string) $request['code_challenge'], $challenge)) {
                $this->db->commit();
                return ['status' => 'invalid_verifier'];
            }
            if ($request['status'] === 'pending') {
                $this->db->commit();
                return ['status' => 'pending'];
            }
            if (!$request['client_id']) {
                $this->db->commit();
                return ['status' => 'invalid'];
            }
            $token = 'catch_cli_' . rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
            $this->db->prepare('INSERT INTO catch_client_tokens (id,client_id,token_hash,token_scope,created_at) VALUES (:id,:client,:hash,\'capture:read\',UTC_TIMESTAMP(6))')->execute(['id' => Id::uuid(), 'client' => $request['client_id'], 'hash' => hash('sha256', $token)]);
            $this->db->prepare('DELETE FROM catch_cli_auth_requests WHERE login_id=:login')->execute(['login' => $loginId]);
            $this->db->commit();

            return ['status' => 'connected', 'device_token' => $token, 'client_id' => $request['client_id'], 'device_name' => $request['device_name']];
        } catch (\Throwable $error) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $error;
        }
    }

    private function validId(string $loginId): bool
    {
        return preg_match('/^[0-9a-f]{48}$/', $loginId) === 1;
    }

    private function suggestedDeviceName(string $name): string
    {
        $name = trim($name);
        if ($name === '') {
            return 'Catch CLI';
        }
        if (preg_match('/\bcli\b/i', $name) === 1) {
            return mb_substr($name, 0, 120);
        }

        return mb_substr('Catch CLI on ' . $name, 0, 120);
    }

    private function deleteExpired(): void
    {
        $this->db->exec('DELETE d FROM catch_clients d JOIN catch_cli_auth_requests r ON r.client_id=d.id WHERE r.expires_at<UTC_TIMESTAMP(6)');
        $this->db->exec('DELETE FROM catch_cli_auth_requests WHERE expires_at<UTC_TIMESTAMP(6)');
    }

    private function deleteRequestAndClient(string $loginId, mixed $clientId): void
    {
        if ($clientId) {
            $this->db->prepare('DELETE FROM catch_clients WHERE id=:client')->execute(['client' => $clientId]);
        } else {
            $this->db->prepare('DELETE FROM catch_cli_auth_requests WHERE login_id=:login')->execute(['login' => $loginId]);
        }
    }
}
