<?php

declare(strict_types=1);

namespace Catch\Repositories;

use Catch\Core\Id;
use Catch\Services\GenericWebhookClient;
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

        return array_map(fn (array $target): array => $this->hydrate($target), $query->fetchAll());
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

        return $this->hydrate($target, $withSecrets);
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

    public function updatePrsmTask(string $id, string $userId, string $token): ?array
    {
        $target = $this->find($id, $userId, true);
        if (!$target || $target['type'] !== 'prsm_task') {
            return null;
        }
        $token = trim($token);
        if ($token === '') {
            $token = (string) ($target['config']['token'] ?? '');
        }
        if ($token === '' || mb_strlen($token) > 4096) {
            throw new InvalidArgumentException('Enter a valid Prsm API token.');
        }
        $this->updateRecord($id, $userId, 'Prsm', [
            'token_encrypted' => $this->secrets->encrypt($token),
        ]);

        return $this->find($id, $userId);
    }

    public function createGenericWebhook(
        string $userId,
        string $name,
        string $url,
        string $method,
        string $authType,
        string $secret = '',
        string $authHeader = 'X-API-Key',
    ): array {
        [$name, $config] = $this->genericWebhookConfig(
            $name,
            $url,
            $method,
            $authType,
            $secret,
            $authHeader,
        );

        $id = Id::uuid();
        $query = $this->db->prepare(<<<'SQL'
            INSERT INTO catch_targets (id,user_id,name,type,config_json,created_at,updated_at)
            VALUES (:id,:user,:name,'generic_webhook',:config,UTC_TIMESTAMP(6),UTC_TIMESTAMP(6))
            SQL);
        $query->execute([
            'id' => $id,
            'user' => $userId,
            'name' => $name,
            'config' => json_encode(
                $config,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
            ),
        ]);

        return $this->find($id, $userId) ?? throw new RuntimeException('The endpoint target could not be created.');
    }

    public function updateGenericWebhook(
        string $id,
        string $userId,
        string $name,
        string $url,
        string $method,
        string $authType,
        string $secret = '',
        string $authHeader = 'X-API-Key',
    ): ?array {
        $target = $this->find($id, $userId, true);
        if (!$target || $target['type'] !== 'generic_webhook') {
            return null;
        }
        [$name, $config] = $this->genericWebhookConfig(
            $name,
            $url,
            $method,
            $authType,
            $secret,
            $authHeader,
            (string) ($target['config']['secret'] ?? ''),
        );
        $this->updateRecord($id, $userId, $name, $config);

        return $this->find($id, $userId);
    }

    public function delete(string $id, string $userId): bool
    {
        $query = $this->db->prepare('DELETE FROM catch_targets WHERE id=:id AND user_id=:user');
        $query->execute(['id' => $id, 'user' => $userId]);

        return $query->rowCount() > 0;
    }

    private function hydrate(array $target, bool $withSecrets = false): array
    {
        $config = json_decode((string) ($target['config_json'] ?? '{}'), true);
        $target['config'] = is_array($config) ? $config : [];
        $target['type_label'] = match ((string) $target['type']) {
            'prsm_task' => 'Prsm Task',
            'generic_webhook' => 'Generic Endpoint',
            default => 'Unknown target',
        };
        $target['configured'] = match ((string) $target['type']) {
            'prsm_task' => !empty($target['config']['token_encrypted']),
            'generic_webhook' => ($target['config']['auth']['type'] ?? 'none') === 'none'
                || !empty($target['config']['secret_encrypted']),
            default => false,
        };
        $target['summary'] = ((string) $target['type']) === 'generic_webhook'
            ? trim((string) ($target['config']['method'] ?? 'POST')) . ' ' . trim((string) ($target['config']['url'] ?? ''))
            : '';
        if ($withSecrets) {
            $encrypted = (string) (
                $target['config']['token_encrypted']
                ?? $target['config']['secret_encrypted']
                ?? ''
            );
            if ($encrypted !== '') {
                $key = ((string) $target['type']) === 'prsm_task' ? 'token' : 'secret';
                $target['config'][$key] = $this->secrets->decrypt($encrypted);
            }
        }
        unset($target['config']['token_encrypted'], $target['config']['secret_encrypted']);
        unset($target['config_json']);

        return $target;
    }

    private function genericWebhookConfig(
        string $name,
        string $url,
        string $method,
        string $authType,
        string $secret,
        string $authHeader,
        string $existingSecret = '',
    ): array {
        $name = trim((string) preg_replace('/\s+/u', ' ', $name));
        if ($name === '' || mb_strlen($name) > 120) {
            throw new InvalidArgumentException('Enter an endpoint name of up to 120 characters.');
        }
        $url = GenericWebhookClient::validateEndpoint($url);
        $method = GenericWebhookClient::validateMethod($method);
        $authType = strtolower(trim($authType));
        if (!in_array($authType, ['none', 'bearer', 'api_key'], true)) {
            throw new InvalidArgumentException('Choose a supported endpoint authentication type.');
        }
        $secret = trim($secret) ?: $existingSecret;
        if (
            $authType !== 'none'
            && ($secret === '' || mb_strlen($secret) > 4096 || str_contains($secret, "\r") || str_contains($secret, "\n"))
        ) {
            throw new InvalidArgumentException('Enter an authentication secret of up to 4096 characters.');
        }
        $auth = ['type' => $authType];
        if ($authType === 'api_key') {
            $auth['header'] = GenericWebhookClient::validateHeaderName($authHeader);
        }
        $config = ['url' => $url, 'method' => $method, 'auth' => $auth];
        if ($authType !== 'none') {
            $config['secret_encrypted'] = $this->secrets->encrypt($secret);
        }

        return [$name, $config];
    }

    private function updateRecord(string $id, string $userId, string $name, array $config): void
    {
        $query = $this->db->prepare(<<<'SQL'
            UPDATE catch_targets
            SET name=:name,config_json=:config,updated_at=UTC_TIMESTAMP(6)
            WHERE id=:id AND user_id=:user
            SQL);
        $query->execute([
            'name' => $name,
            'config' => json_encode($config, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'id' => $id,
            'user' => $userId,
        ]);
    }
}
