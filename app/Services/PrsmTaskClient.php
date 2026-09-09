<?php

declare(strict_types=1);

namespace Catch\Services;

use Catch\Core\Config;
use RuntimeException;

final class PrsmTaskClient
{
    public function __construct(private readonly Config $config)
    {
    }

    public function createTask(array $target, array $capture): array
    {
        $baseUrl = rtrim(trim((string) $this->config->get('prsm.base_url', '')), '/');
        if (!filter_var($baseUrl, FILTER_VALIDATE_URL) || !preg_match('~^https?://~i', $baseUrl)) {
            throw new RuntimeException('The Prsm base URL is not configured.');
        }
        $token = trim((string) ($target['config']['token'] ?? ''));
        if ($token === '') {
            throw new RuntimeException('The Prsm target has no API token.');
        }

        $curl = curl_init($baseUrl . '/api/tasks');
        if ($curl === false) {
            throw new RuntimeException('The Prsm request could not be prepared.');
        }
        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'Authorization: Bearer ' . $token,
                'Content-Type: application/json',
            ],
            CURLOPT_POSTFIELDS => json_encode(
                self::taskPayload($capture),
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
            ),
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_SSL_VERIFYPEER => $this->config->bool('prsm.tls_verify', true),
            CURLOPT_SSL_VERIFYHOST => $this->config->bool('prsm.tls_verify', true) ? 2 : 0,
        ]);
        $body = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $curlError = curl_error($curl);
        curl_close($curl);

        if ($body === false) {
            throw new RuntimeException('Prsm could not be reached' . ($curlError ? ': ' . $curlError : '.'));
        }
        $decoded = json_decode($body, true);
        if ($status !== 201) {
            $message = is_array($decoded) ? trim((string) ($decoded['error']['message'] ?? '')) : '';
            throw new RuntimeException($message ?: 'Prsm rejected the task (HTTP ' . $status . ').');
        }

        return is_array($decoded['task'] ?? null) ? $decoded['task'] : [];
    }

    public static function taskPayload(array $capture): array
    {
        $fallback = trim((string) ($capture['text'] ?? ''))
            ?: trim((string) ($capture['url'] ?? ''))
            ?: 'Attachment';
        $title = trim((string) ($capture['title'] ?? '')) ?: $fallback;
        $title = trim((string) preg_replace('/\s+/u', ' ', $title));
        $title = mb_strimwidth($title, 0, 240, '');
        if ($title === '') {
            $title = 'Catch capture';
        }

        $parts = [];
        foreach ([
            'Text' => $capture['text'] ?? null,
            'URL' => $capture['url'] ?? null,
            'Extracted text' => $capture['extracted_text'] ?? null,
        ] as $label => $value) {
            $value = trim((string) $value);
            if ($value !== '' && !in_array($value, $parts, true)) {
                $parts[] = $label . ":\n" . $value;
            }
        }

        $payload = ['title' => $title];
        $description = trim(implode("\n\n", $parts));
        if ($description !== '') {
            $payload['description'] = mb_strimwidth($description, 0, 10000, '');
        }

        return $payload;
    }
}
