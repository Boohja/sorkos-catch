<?php

declare(strict_types=1);

namespace Catch\Repositories;

use Catch\Core\Id;
use PDO;

final class UserRepository
{
    public function __construct(private readonly PDO $db)
    {
    }
    public function upsertFromSorkos(array $identity): array
    {
        $query = $this->db->prepare('SELECT * FROM catch_users WHERE sorkos_user_id=:external LIMIT 1');
        $query->execute(['external' => $identity['id']]);
        $existing = $query->fetch() ?: null;
        $data = ['external' => $identity['id'],'email' => isset($identity['email']) ? mb_strtolower((string)$identity['email']) : null,'verified' => !empty($identity['email_verified']) ? 1 : 0,'name' => trim((string)($identity['display_name'] ?? '')) ?: 'Catch User','avatar' => $identity['avatar_url'] ?? null,'language' => $identity['preferred_language'] ?? null];
        if ($existing) {
            $this->db->prepare('UPDATE catch_users SET email=:email,email_verified=:verified,display_name=:name,avatar_url=:avatar,preferred_language=:language,updated_at=UTC_TIMESTAMP(6) WHERE sorkos_user_id=:external')->execute($data);
            return $this->find($existing['id']);
        }
        $data['id'] = Id::uuid();
        $this->db->prepare('INSERT INTO catch_users (id,sorkos_user_id,email,email_verified,display_name,avatar_url,preferred_language,created_at,updated_at) VALUES (:id,:external,:email,:verified,:name,:avatar,:language,UTC_TIMESTAMP(6),UTC_TIMESTAMP(6))')->execute($data);
        return $this->find($data['id']);
    }
    public function find(string $id): ?array
    {
        $query = $this->db->prepare('SELECT id,sorkos_user_id,email,email_verified,display_name,avatar_url,preferred_language,created_at FROM catch_users WHERE id=:id');
        $query->execute(['id' => $id]);
        return $query->fetch() ?: null;
    }
}
