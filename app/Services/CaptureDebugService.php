<?php

declare(strict_types=1);

namespace Catch\Services;

use Catch\Core\Config;
use Catch\Core\Id;
use PDO;

final class CaptureDebugService
{
    private const MAX_DEPTH = 6;
    private const MAX_ITEMS = 250;
    private const MAX_STRING_LENGTH = 20000;

    public function __construct(
        private readonly PDO $db,
        private readonly Config $config,
    ) {
    }

    public function enabled(): bool
    {
        return $this->config->bool('app.debug');
    }

    public function begin(array $user, string $endpoint, array $parameters, array $files): ?string
    {
        if (!$this->enabled()) {
            return null;
        }

        $id = Id::uuid();

        try {
            $query = $this->db->prepare(<<<'SQL'
                INSERT INTO catch_capture_debug_requests (
                    id, user_id, device_id, token_id, token_scope, endpoint, method,
                    remote_ip, user_agent, content_type, content_length, idempotency_key,
                    parameters_json, files_json, created_at
                ) VALUES (
                    :id, :user, :device, :token, :scope, :endpoint, :method,
                    :remote_ip, :user_agent, :content_type, :content_length, :idempotency_key,
                    :parameters, :files, UTC_TIMESTAMP(6)
                )
                SQL);
            $query->execute([
                'id' => $id,
                'user' => $user['id'],
                'device' => $user['device_id'],
                'token' => $user['token_id'],
                'scope' => $user['token_scope'],
                'endpoint' => mb_substr($endpoint, 0, 120),
                'method' => mb_substr((string) ($_SERVER['REQUEST_METHOD'] ?? 'POST'), 0, 10),
                'remote_ip' => $this->nullableServerValue('REMOTE_ADDR', 45),
                'user_agent' => $this->nullableServerValue('HTTP_USER_AGENT', 500),
                'content_type' => $this->nullableServerValue('CONTENT_TYPE', 160),
                'content_length' => $this->contentLength(),
                'idempotency_key' => $this->nullableServerValue('HTTP_IDEMPOTENCY_KEY', 255),
                'parameters' => $this->json($this->sanitize($parameters)),
                'files' => $files === [] ? null : $this->json($this->sanitizeFiles($files)),
            ]);

            return $id;
        } catch (\Throwable $error) {
            $this->reportFailure('store', $error);

            return null;
        }
    }

    public function finish(
        ?string $requestId,
        string $verdict,
        int $httpStatus,
        ?string $captureId = null,
        ?string $errorMessage = null,
    ): void {
        if ($requestId === null) {
            return;
        }

        try {
            $query = $this->db->prepare(<<<'SQL'
                UPDATE catch_capture_debug_requests
                SET verdict = :verdict,
                    http_status = :http_status,
                    capture_id = :capture,
                    error_message = :error,
                    completed_at = UTC_TIMESTAMP(6)
                WHERE id = :id
                SQL);
            $query->execute([
                'verdict' => mb_substr($verdict, 0, 40),
                'http_status' => $httpStatus,
                'capture' => $captureId,
                'error' => $errorMessage === null ? null : mb_substr($errorMessage, 0, 4000),
                'id' => $requestId,
            ]);
        } catch (\Throwable $error) {
            $this->reportFailure('update', $error);
        }
    }

    public function forDevice(string $userId, string $deviceId, int $limit = 50): array
    {
        if (!$this->enabled()) {
            return [];
        }

        $limit = max(1, min($limit, 100));
        $query = $this->db->prepare(<<<SQL
            SELECT *
            FROM catch_capture_debug_requests
            WHERE user_id = :user AND device_id = :device
            ORDER BY created_at DESC
            LIMIT {$limit}
            SQL);
        $query->execute([
            'user' => $userId,
            'device' => $deviceId,
        ]);
        $requests = $query->fetchAll();

        return $this->prepareRequests($requests);
    }

    public function forCapture(string $userId, string $captureId, int $limit = 10): array
    {
        if (!$this->enabled()) {
            return [];
        }

        $limit = max(1, min($limit, 25));
        $query = $this->db->prepare(<<<SQL
            SELECT *
            FROM catch_capture_debug_requests
            WHERE user_id = :user AND capture_id = :capture
            ORDER BY created_at DESC
            LIMIT {$limit}
            SQL);
        $query->execute([
            'user' => $userId,
            'capture' => $captureId,
        ]);

        return $this->prepareRequests($query->fetchAll());
    }

    private function prepareRequests(array $requests): array
    {
        foreach ($requests as &$request) {
            $request['parameters_pretty'] = $this->prettyJson((string) $request['parameters_json']);
            $request['files_pretty'] = $request['files_json'] === null
                ? null
                : $this->prettyJson((string) $request['files_json']);
        }
        unset($request);

        return $requests;
    }

    private function sanitize(mixed $value, int $depth = 0): mixed
    {
        if ($depth >= self::MAX_DEPTH) {
            return '[maximum depth reached]';
        }

        if (is_array($value)) {
            $result = [];
            $items = 0;

            foreach ($value as $key => $item) {
                if ($items++ >= self::MAX_ITEMS) {
                    $result['__truncated'] = 'Additional values omitted.';
                    break;
                }

                $key = (string) $key;
                $result[$key] = $this->isSensitiveKey($key)
                    ? '[redacted]'
                    : $this->sanitize($item, $depth + 1);
            }

            return $result;
        }

        if (is_string($value)) {
            return mb_strlen($value) > self::MAX_STRING_LENGTH
                ? mb_substr($value, 0, self::MAX_STRING_LENGTH) . '\n[truncated]'
                : $value;
        }

        if (is_int($value) || is_float($value) || is_bool($value) || $value === null) {
            return $value;
        }

        return '[' . get_debug_type($value) . ']';
    }

    private function sanitizeFiles(array $files): array
    {
        $safe = [];

        foreach ($files as $field => $file) {
            if (!is_array($file)) {
                continue;
            }

            $error = $file['error'] ?? UPLOAD_ERR_NO_FILE;
            $metadata = [
                'attachment_present' => $this->hasUploadedFile($error),
                'original_name' => $file['name'] ?? null,
                'declared_mime' => $file['type'] ?? null,
                'size_bytes' => $file['size'] ?? null,
                'upload_error' => $error,
            ];

            $temporaryPath = $file['tmp_name'] ?? null;
            if (
                !is_array($temporaryPath)
                && (int) $error === UPLOAD_ERR_OK
                && is_file((string) $temporaryPath)
            ) {
                $detectedMime = (new \finfo(FILEINFO_MIME_TYPE))->file((string) $temporaryPath);
                $metadata['detected_mime'] = $detectedMime === false ? null : $detectedMime;
            }

            $safe[$field] = $metadata;
        }

        return $this->sanitize($safe);
    }

    private function hasUploadedFile(mixed $error): bool
    {
        if (is_array($error)) {
            foreach ($error as $item) {
                if ($this->hasUploadedFile($item)) {
                    return true;
                }
            }

            return false;
        }

        return (int) $error !== UPLOAD_ERR_NO_FILE;
    }

    private function isSensitiveKey(string $key): bool
    {
        return preg_match('/(?:authorization|cookie|password|secret|token|api[_-]?key)/i', $key) === 1;
    }

    private function nullableServerValue(string $key, int $maxLength): ?string
    {
        $value = trim((string) ($_SERVER[$key] ?? ''));

        return $value === '' ? null : mb_substr($value, 0, $maxLength);
    }

    private function contentLength(): ?int
    {
        $value = $_SERVER['CONTENT_LENGTH'] ?? null;

        return is_numeric($value) && (int) $value >= 0 ? (int) $value : null;
    }

    private function json(mixed $value): string
    {
        return json_encode(
            $value,
            JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES,
        );
    }

    private function prettyJson(string $json): string
    {
        try {
            return json_encode(
                json_decode($json, true, 512, JSON_THROW_ON_ERROR),
                JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
            );
        } catch (\JsonException) {
            return $json;
        }
    }

    private function reportFailure(string $operation, \Throwable $error): void
    {
        error_log(sprintf(
            'Capture debug request %s failed: %s',
            $operation,
            $error->getMessage(),
        ));
    }
}
