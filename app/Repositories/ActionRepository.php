<?php

declare(strict_types=1);

namespace Catch\Repositories;

use Catch\Core\Id;
use InvalidArgumentException;
use PDO;
use RuntimeException;

final class ActionRepository
{
    private const TYPES = [
        'send_capture_to_target',
        'add_tag',
        'archive_capture',
        'delete_capture',
    ];

    public function __construct(private readonly PDO $db)
    {
    }

    public function all(string $userId): array
    {
        $query = $this->db->prepare(
            'SELECT * FROM catch_actions WHERE user_id=:user ORDER BY name, created_at',
        );
        $query->execute(['user' => $userId]);
        $actions = $query->fetchAll();

        return array_map(fn (array $action): array => $this->withSteps($action), $actions);
    }

    public function find(string $id, string $userId): ?array
    {
        $query = $this->db->prepare(
            'SELECT * FROM catch_actions WHERE id=:id AND user_id=:user LIMIT 1',
        );
        $query->execute(['id' => $id, 'user' => $userId]);
        $action = $query->fetch();

        return $action ? $this->withSteps($action) : null;
    }

    public function create(string $userId, string $name, array $steps): array
    {
        $name = $this->validate($name, $steps);

        $id = Id::uuid();
        $this->db->beginTransaction();
        try {
            $query = $this->db->prepare(<<<'SQL'
                INSERT INTO catch_actions (id,user_id,name,created_at,updated_at)
                VALUES (:id,:user,:name,UTC_TIMESTAMP(6),UTC_TIMESTAMP(6))
                SQL);
            $query->execute(['id' => $id, 'user' => $userId, 'name' => $name]);

            $this->insertSteps($id, $steps);
            $this->db->commit();
        } catch (\Throwable $error) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $error;
        }

        return $this->find($id, $userId) ?? throw new RuntimeException('The action could not be created.');
    }

    public function update(string $id, string $userId, string $name, array $steps): ?array
    {
        $name = $this->validate($name, $steps);
        if (!$this->find($id, $userId)) {
            return null;
        }

        $this->db->beginTransaction();
        try {
            $query = $this->db->prepare(
                'UPDATE catch_actions SET name=:name,updated_at=UTC_TIMESTAMP(6) WHERE id=:id AND user_id=:user',
            );
            $query->execute(['name' => $name, 'id' => $id, 'user' => $userId]);
            $delete = $this->db->prepare('DELETE FROM catch_action_steps WHERE action_id=:action');
            $delete->execute(['action' => $id]);
            $this->insertSteps($id, $steps);
            $this->db->commit();
        } catch (\Throwable $error) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $error;
        }

        return $this->find($id, $userId);
    }

    public function delete(string $id, string $userId): bool
    {
        $query = $this->db->prepare('DELETE FROM catch_actions WHERE id=:id AND user_id=:user');
        $query->execute(['id' => $id, 'user' => $userId]);

        return $query->rowCount() > 0;
    }

    public function usesTarget(string $targetId, string $userId): bool
    {
        foreach ($this->all($userId) as $action) {
            foreach ($action['steps'] as $step) {
                if (
                    $step['type'] === 'send_capture_to_target'
                    && ($step['config']['target_id'] ?? null) === $targetId
                ) {
                    return true;
                }
            }
        }

        return false;
    }

    private function withSteps(array $action): array
    {
        $query = $this->db->prepare(
            'SELECT * FROM catch_action_steps WHERE action_id=:action ORDER BY position',
        );
        $query->execute(['action' => $action['id']]);
        $steps = [];
        foreach ($query->fetchAll() as $step) {
            $config = json_decode((string) ($step['config_json'] ?? '{}'), true);
            $step['config'] = is_array($config) ? $config : [];
            $step['label'] = match ((string) $step['type']) {
                'send_capture_to_target' => 'Send capture to target',
                'add_tag' => 'Add tag “' . ($step['config']['name'] ?? '') . '”',
                'archive_capture' => 'Archive capture',
                'delete_capture' => 'Move capture to Trash',
                default => 'Unknown step',
            };
            unset($step['config_json']);
            $steps[] = $step;
        }
        $action['steps'] = $steps;

        return $action;
    }

    private function validate(string $name, array $steps): string
    {
        $name = trim((string) preg_replace('/\s+/u', ' ', $name));
        if ($name === '' || mb_strlen($name) > 120) {
            throw new InvalidArgumentException('Enter an action name of up to 120 characters.');
        }
        if (!$steps) {
            throw new InvalidArgumentException('Add at least one step to the action.');
        }
        foreach ($steps as $step) {
            if (!in_array((string) ($step['type'] ?? ''), self::TYPES, true)) {
                throw new InvalidArgumentException('Unknown action step type.');
            }
        }

        return $name;
    }

    private function insertSteps(string $actionId, array $steps): void
    {
        $query = $this->db->prepare(<<<'SQL'
            INSERT INTO catch_action_steps (id,action_id,position,type,config_json)
            VALUES (:id,:action,:position,:type,:config)
            SQL);
        foreach (array_values($steps) as $position => $step) {
            $query->execute([
                'id' => Id::uuid(),
                'action' => $actionId,
                'position' => $position + 1,
                'type' => (string) $step['type'],
                'config' => json_encode(
                    is_array($step['config'] ?? null) ? $step['config'] : [],
                    JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
                ),
            ]);
        }
    }
}
