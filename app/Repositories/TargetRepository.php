<?php

declare(strict_types=1);

namespace Catch\Repositories;

use Catch\Core\Id;
use Catch\Services\SecretBox;
use InvalidArgumentException;
use PDO;
use RuntimeException;

final class TargetRepository
{
    public function __construct(
        private readonly PDO $db,
        private readonly SecretBox $secrets,
    ) {
    }

    public function all(string $userId): array
    {
        $query = $this->db->prepare(
            'SELECT * FROM catch_targets WHERE user_id=:user ORDER BY name, created_at',
        );
        $query->execute(['user' => $userId]);

        return array_map([$this, 'hydrate'], $query->fetchAll());
    }

    public function find(string $id, string $userId, bool $withSecrets = false): ?array
    {
        $query = $this->db->prepare(
            'SELECT * FROM catch_targets WHERE id=:id AND user_id=:user LIMIT 1',
        );
        $query->execute(['id' => $id, 'user' => $userId]);
        $target = $query->fetch();
        if (!$target) {
            return null;
        }

        $target = $this->hydrate($target);
        if ($withSecrets) {
            $encrypted = (string) ($target['config']['token_encrypted'] ?? '');
            if ($encrypted === '') {
                throw new RuntimeException('The target token is missing.');
            }
            $target['config']['token'] = $this->secrets->decrypt($encrypted);
        }

        return $target;
    }

    public function createPrsmTask(string $userId, string $token): array
    {
        $token = trim($token);
        if ($token === '' || mb_strlen($token) > 4096) {
            throw new InvalidArgumentException('Enter a valid Prsm API token.');
        }

        $id = Id::uuid();
        $config = json_encode(
            ['token_encrypted' => $this->secrets->encrypt($token)],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
        );
        $query = $this->db->prepare(<<<'SQL'
            INSERT INTO catch_targets (id,user_id,name,type,config_json,created_at,updated_at)
            VALUES (:id,:user,:name,'prsm_task',:config,UTC_TIMESTAMP(6),UTC_TIMESTAMP(6))
            SQL);
        $query->execute(['id' => $id, 'user' => $userId, 'name' => 'Prsm', 'config' => $config]);

        return $this->find($id, $userId) ?? throw new RuntimeException('The target could not be created.');
    }

    public function delete(string $id, string $userId): bool
    {
        $query = $this->db->prepare('DELETE FROM catch_targets WHERE id=:id AND user_id=:user');
        $query->execute(['id' => $id, 'user' => $userId]);

        return $query->rowCount() > 0;
    }

    private function hydrate(array $target): array
    {
        $config = json_decode((string) ($target['config_json'] ?? '{}'), true);
        $target['config'] = is_array($config) ? $config : [];
        $target['type_label'] = match ((string) $target['type']) {
            'prsm_task' => 'Prsm Task',
            default => 'Unknown target',
        };
        $target['configured'] = !empty($target['config']['token_encrypted']);
        unset($target['config_json']);

        return $target;
    }
}
