<?php

declare(strict_types=1);

namespace Catch\Core;

use PDO;
use SessionHandlerInterface;
use SessionUpdateTimestampHandlerInterface;

final class DatabaseSessionHandler implements SessionHandlerInterface, SessionUpdateTimestampHandlerInterface
{
    public const LIFETIME_SECONDS = 7 * 24 * 60 * 60;

    public function __construct(
        private readonly PDO $db,
        private readonly string $ipAddress,
        private readonly string $userAgent,
    ) {
    }

    public function open(string $path, string $name): bool
    {
        return true;
    }

    public function close(): bool
    {
        return true;
    }

    public function read(string $id): string|false
    {
        $query = $this->db->prepare(
            'SELECT payload FROM catch_sessions WHERE id=:id AND expires_at>UTC_TIMESTAMP(6) LIMIT 1',
        );
        $query->execute(['id' => $id]);
        $payload = $query->fetchColumn();

        if ($payload === false) {
            $this->destroy($id);

            return '';
        }

        return (string) $payload;
    }

    public function write(string $id, string $data): bool
    {
        $expiresAt = $this->expiresAt();
        $userId = $this->sessionUuid('user_id');
        $clientId = $this->sessionUuid('catch_web_client_id');
        $query = $this->db->prepare(<<<'SQL'
            INSERT INTO catch_sessions (
                id,user_id,client_id,payload,ip_address,user_agent,created_at,updated_at,expires_at
            ) VALUES (
                :id,:user,:client,:payload,:ip,:user_agent,UTC_TIMESTAMP(6),UTC_TIMESTAMP(6),:expires
            )
            ON DUPLICATE KEY UPDATE
                user_id=VALUES(user_id),client_id=VALUES(client_id),payload=VALUES(payload),
                ip_address=VALUES(ip_address),user_agent=VALUES(user_agent),
                updated_at=UTC_TIMESTAMP(6),expires_at=VALUES(expires_at)
            SQL);

        return $query->execute([
            'id' => $id,
            'user' => $userId,
            'client' => $clientId,
            'payload' => $data,
            'ip' => mb_substr($this->ipAddress, 0, 45) ?: null,
            'user_agent' => mb_substr($this->userAgent, 0, 500) ?: null,
            'expires' => $expiresAt,
        ]);
    }

    public function destroy(string $id): bool
    {
        $query = $this->db->prepare('DELETE FROM catch_sessions WHERE id=:id');

        return $query->execute(['id' => $id]);
    }

    public function gc(int $max_lifetime): int|false
    {
        $deleted = $this->db->exec('DELETE FROM catch_sessions WHERE expires_at<=UTC_TIMESTAMP(6)');

        return $deleted === false ? false : $deleted;
    }

    public function validateId(string $id): bool
    {
        $query = $this->db->prepare(
            'SELECT 1 FROM catch_sessions WHERE id=:id AND expires_at>UTC_TIMESTAMP(6) LIMIT 1',
        );
        $query->execute(['id' => $id]);

        return $query->fetchColumn() !== false;
    }

    public function updateTimestamp(string $id, string $data): bool
    {
        $query = $this->db->prepare(<<<'SQL'
            UPDATE catch_sessions
            SET updated_at=UTC_TIMESTAMP(6),expires_at=:expires,ip_address=:ip,user_agent=:user_agent
            WHERE id=:id AND expires_at>UTC_TIMESTAMP(6)
            SQL);

        $query->execute([
            'id' => $id,
            'expires' => $this->expiresAt(),
            'ip' => mb_substr($this->ipAddress, 0, 45) ?: null,
            'user_agent' => mb_substr($this->userAgent, 0, 500) ?: null,
        ]);

        return $query->rowCount() === 1;
    }

    private function expiresAt(): string
    {
        return gmdate('Y-m-d H:i:s', time() + self::LIFETIME_SECONDS);
    }

    private function sessionUuid(string $key): ?string
    {
        $value = (string) ($_SESSION[$key] ?? '');

        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $value)
            ? $value
            : null;
    }
}
