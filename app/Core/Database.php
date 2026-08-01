<?php

declare(strict_types=1);

namespace Catch\Core;

use PDO;
use RuntimeException;

final class Database
{
    private ?PDO $pdo = null;

    public function __construct(private readonly Config $config) {}

    public function connection(): PDO
    {
        if ($this->pdo instanceof PDO) {
            return $this->pdo;
        }
        if (!$this->config->databaseConfigured()) {
            throw new RuntimeException('Database credentials have not been configured.');
        }
        $driver = (string) $this->config->get('database.driver', 'mysql');
        if ($driver === 'sqlite') {
            $dsn = 'sqlite:' . (string) $this->config->get('database.path');
        } else {
            $dsn = sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=%s',
                $this->config->get('database.host', '127.0.0.1'),
                (int) $this->config->get('database.port', 3306),
                $this->config->get('database.name'),
                $this->config->get('database.charset', 'utf8mb4')
            );
        }
        $this->pdo = new PDO($dsn, (string) $this->config->get('database.user', ''), (string) $this->config->get('database.password', ''), [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        return $this->pdo;
    }

    public function available(): bool
    {
        try {
            $this->connection()->query('SELECT 1');
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    public function transaction(callable $callback): mixed
    {
        $pdo = $this->connection();
        $pdo->beginTransaction();
        try {
            $result = $callback($pdo);
            $pdo->commit();
            return $result;
        } catch (\Throwable $error) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $error;
        }
    }
}
