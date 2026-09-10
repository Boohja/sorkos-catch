<?php

declare(strict_types=1);

namespace Catch\Services;

use Catch\Core\Config;
use InvalidArgumentException;
use JsonException;
use RuntimeException;

final class GenericWebhookClient
{
    private const METHODS = ['POST', 'PUT', 'PATCH'];
    private const CONTENT_TYPES = [
        'application/json' => 'JSON',
        'text/plain' => 'Plain text',
        'multipart/form-data' => 'Multipart form data',
    ];
    private const MAX_TEMPLATE_BYTES = 65_536;
    private const MAX_RESPONSE_BYTES = 65_536;
    private const RESERVED_HEADERS = [
        'authorization',
        'content-length',
        'content-type',
        'host',
        'idempotency-key',
    ];

    private const VARIABLES = [
        'capture.id' => 'Stable capture ID',
        'capture.number' => 'Catch reference number',
        'capture.type' => 'Capture type',
        'capture.title' => 'Original title or null',
        'capture.display_title' => 'Readable title with fallback',
        'capture.text' => 'Captured text or null',
        'capture.content' => 'Combined available content',
        'capture.url' => 'Captured URL or null',
        'capture.extracted_text' => 'Extracted text or null',
        'capture.source' => 'Capture source',
        'capture.status' => 'Current Catch status',
        'capture.created_at' => 'Creation timestamp',
        'capture.web_url' => 'Link back to Catch',
        'capture.tags' => 'Array of tag names',
        'capture.attachments' => 'Array of attachment metadata',
        'user.display_name' => 'Catch user display name',
        'user.email' => 'Catch user email address or null',
    ];

    public function __construct(private readonly Config $config)
    {
    }

    public static function methods(): array
    {
        return self::METHODS;
    }

    public static function variables(): array
    {
        return self::VARIABLES;
    }

    public static function contentTypes(): array
    {
        return self::CONTENT_TYPES;
    }

    public static function defaultTemplateJson(): string
    {
        return json_encode([
            'title' => '{{capture.display_title}}',
            'content' => '{{capture.content}}',
            'url' => '{{capture.url}}',
            'catch' => [
                'id' => '{{capture.id}}',
                'number' => '{{capture.number}}',
                'type' => '{{capture.type}}',
                'tags' => '{{capture.tags}}',
                'web_url' => '{{capture.web_url}}',
            ],
        ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    public static function defaultTemplateText(): string
    {
        return "{{capture.display_title}}\n\n{{capture.content}}\n\n{{capture.web_url}}";
    }

    public static function defaultTemplateMultipart(): string
    {
        return json_encode([
            'title' => '{{capture.display_title}}',
            'content' => '{{capture.content}}',
            'url' => '{{capture.url}}',
            'catch_id' => '{{capture.id}}',
            'tags' => '{{capture.tags}}',
        ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    public static function validateContentType(string $contentType): string
    {
        $contentType = strtolower(trim($contentType));
        if (!array_key_exists($contentType, self::CONTENT_TYPES)) {
            throw new InvalidArgumentException('Choose JSON, plain text, or multipart form data.');
        }

        return $contentType;
    }

    public static function parseBodyTemplate(string $body, string $contentType): array|string
    {
        $contentType = self::validateContentType($contentType);
        if ($contentType === 'text/plain') {
            if (trim($body) === '' || strlen($body) > self::MAX_TEMPLATE_BYTES) {
                throw new InvalidArgumentException('Enter a plain-text body of up to 64 KB.');
            }
            self::validateTemplateNode($body);

            return $body;
        }

        $template = self::parseTemplate($body);
        if ($contentType === 'multipart/form-data' && array_is_list($template)) {
            throw new InvalidArgumentException('Multipart form data must be a JSON object of field names and values.');
        }

        return $template;
    }

    public static function validateEndpoint(string $url): string
    {
        $url = trim($url);
        $parts = parse_url($url);
        if (
            !is_array($parts)
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || empty($parts['host'])
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['fragment'])
            || filter_var($url, FILTER_VALIDATE_URL) === false
        ) {
            throw new InvalidArgumentException('Enter a valid HTTPS endpoint URL without credentials or a fragment.');
        }

        return $url;
    }

    public static function validateMethod(string $method): string
    {
        $method = strtoupper(trim($method));
        if (!in_array($method, self::METHODS, true)) {
            throw new InvalidArgumentException('Choose POST, PUT, or PATCH as the endpoint method.');
        }

        return $method;
    }

    public static function validateHeaderName(string $name): string
    {
        $name = trim($name);
        if (
            $name === ''
            || !preg_match('/^[A-Za-z][A-Za-z0-9-]{0,79}$/', $name)
            || in_array(strtolower($name), self::RESERVED_HEADERS, true)
        ) {
            throw new InvalidArgumentException('Enter a valid, non-reserved API key header name.');
        }

        return $name;
    }

    public static function parseTemplate(string $json): array
    {
        $json = trim($json);
        if ($json === '' || strlen($json) > self::MAX_TEMPLATE_BYTES) {
            throw new InvalidArgumentException('Enter a JSON body of up to 64 KB.');
        }
        try {
            $template = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw new InvalidArgumentException('The endpoint body is not valid JSON: ' . $error->getMessage());
        }
        if (!is_array($template)) {
            throw new InvalidArgumentException('The endpoint JSON body must be an object or array.');
        }
        self::validateTemplateNode($template);

        return $template;
    }

    public static function renderTemplate(
        array $template,
        array $capture,
        string $appUrl,
        bool $omitEmpty = true,
        array $user = [],
    ): array {
        $variables = self::captureVariables($capture, $appUrl, $user);
        $rendered = self::renderNode($template, $variables, $omitEmpty);

        return is_array($rendered) ? $rendered : [];
    }

    public function send(array $target, array $capture, array $step, array $user): array
    {
        $targetConfig = is_array($target['config'] ?? null) ? $target['config'] : [];
        $stepConfig = is_array($step['config'] ?? null) ? $step['config'] : [];
        $url = self::validateEndpoint((string) ($targetConfig['url'] ?? ''));
        $method = self::validateMethod((string) ($targetConfig['method'] ?? 'POST'));
        $contentType = self::validateContentType((string) ($stepConfig['content_type'] ?? 'application/json'));
        $template = $stepConfig['body_template'] ?? self::parseBodyTemplate(
            match ($contentType) {
                'text/plain' => self::defaultTemplateText(),
                'multipart/form-data' => self::defaultTemplateMultipart(),
                default => self::defaultTemplateJson(),
            },
            $contentType,
        );
        if ($contentType === 'text/plain' && !is_string($template)) {
            throw new RuntimeException('The plain-text endpoint body is invalid.');
        }
        if ($contentType !== 'text/plain' && !is_array($template)) {
            throw new RuntimeException('The structured endpoint body is invalid.');
        }
        self::validateTemplateNode($template);
        $variables = self::captureVariables($capture, (string) $this->config->get('app.url', ''), $user);
        $omitEmpty = (bool) ($stepConfig['omit_empty'] ?? true);
        if ($contentType === 'text/plain') {
            $requestBody = self::renderText($template, $variables);
        } else {
            $payload = self::renderNode($template, $variables, $omitEmpty);
            $requestBody = $contentType === 'multipart/form-data'
                ? self::multipartFields(is_array($payload) ? $payload : [])
                : json_encode(
                    $payload,
                    JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
                );
        }
        $targetAddress = $this->safeTarget($url);

        $headers = [
            'Accept: application/json',
            'User-Agent: Catch/1.0',
            'X-Catch-Id: ' . (string) ($capture['id'] ?? ''),
            'Idempotency-Key: ' . hash('sha256', implode(':', [
                (string) ($capture['id'] ?? ''),
                (string) ($step['id'] ?? ''),
            ])),
        ];
        if ($contentType === 'application/json') {
            $headers[] = 'Content-Type: application/json; charset=utf-8';
        } elseif ($contentType === 'text/plain') {
            $headers[] = 'Content-Type: text/plain; charset=utf-8';
        }
        $this->addAuthenticationHeaders($headers, $targetConfig);

        $curl = curl_init($url);
        if ($curl === false) {
            throw new RuntimeException('The endpoint request could not be prepared.');
        }
        $responseBody = '';
        curl_setopt_array($curl, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_POSTFIELDS => $requestBody,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_MAXREDIRS => 0,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_SSL_VERIFYPEER => $this->config->bool('webhook.tls_verify', true),
            CURLOPT_SSL_VERIFYHOST => $this->config->bool('webhook.tls_verify', true) ? 2 : 0,
            CURLOPT_RESOLVE => [$targetAddress],
            CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$responseBody): int {
                $remaining = self::MAX_RESPONSE_BYTES - strlen($responseBody);
                if ($remaining > 0) {
                    $responseBody .= substr($chunk, 0, $remaining);
                }

                return strlen($chunk);
            },
        ]);
        $result = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $curlError = curl_error($curl);
        curl_close($curl);

        if ($result === false) {
            throw new RuntimeException('The endpoint could not be reached' . ($curlError ? ': ' . $curlError : '.'));
        }
        if ($status < 200 || $status >= 300) {
            throw new RuntimeException('The endpoint rejected the capture (HTTP ' . $status . ').');
        }

        $decoded = json_decode($responseBody, true);

        return is_array($decoded) ? $decoded : [];
    }

    private static function validateTemplateNode(mixed $node): void
    {
        if (is_array($node)) {
            foreach ($node as $value) {
                self::validateTemplateNode($value);
            }
            return;
        }
        if (!is_string($node)) {
            return;
        }

        preg_match_all('/\{\{\s*([^{}]+?)\s*\}\}/', $node, $matches);
        foreach ($matches[1] ?? [] as $variable) {
            if (!array_key_exists(trim((string) $variable), self::VARIABLES)) {
                throw new InvalidArgumentException('Unknown endpoint variable: {{' . trim((string) $variable) . '}}.');
            }
        }
        $withoutVariables = preg_replace('/\{\{\s*[^{}]+?\s*\}\}/', '', $node) ?? $node;
        if (str_contains($withoutVariables, '{{') || str_contains($withoutVariables, '}}')) {
            throw new InvalidArgumentException('The endpoint body contains an incomplete variable.');
        }
    }

    private static function captureVariables(array $capture, string $appUrl, array $user = []): array
    {
        $title = trim((string) ($capture['title'] ?? ''));
        $text = trim((string) ($capture['text'] ?? ''));
        $url = trim((string) ($capture['url'] ?? ''));
        $extracted = trim((string) ($capture['extracted_text'] ?? ''));
        $displayTitle = $title ?: $text ?: $url ?: 'Attachment';
        $content = implode("\n\n", array_values(array_unique(array_filter(
            [$text, $url, $extracted],
            static fn (string $value): bool => $value !== '',
        ))));
        $tags = array_values(array_filter(array_map(
            static fn (array $tag): string => trim((string) ($tag['name'] ?? '')),
            is_array($capture['tags'] ?? null) ? $capture['tags'] : [],
        )));
        $attachments = array_map(static fn (array $attachment): array => [
            'id' => (string) ($attachment['id'] ?? ''),
            'name' => (string) ($attachment['original_name'] ?? ''),
            'mime_type' => (string) ($attachment['mime_type'] ?? ''),
            'size_bytes' => (int) ($attachment['size_bytes'] ?? 0),
            'kind' => (string) ($attachment['kind'] ?? 'source'),
        ], is_array($capture['attachments'] ?? null) ? $capture['attachments'] : []);
        $id = (string) ($capture['id'] ?? '');

        return [
            'capture.id' => $id,
            'capture.number' => (int) ($capture['catch_number'] ?? 0),
            'capture.type' => (string) ($capture['type'] ?? 'text'),
            'capture.title' => $title !== '' ? $title : null,
            'capture.display_title' => mb_strimwidth($displayTitle, 0, 500, '…'),
            'capture.text' => $text !== '' ? $text : null,
            'capture.content' => $content !== '' ? $content : null,
            'capture.url' => $url !== '' ? $url : null,
            'capture.extracted_text' => $extracted !== '' ? $extracted : null,
            'capture.source' => (string) ($capture['source'] ?? ''),
            'capture.status' => (string) ($capture['status'] ?? 'inbox'),
            'capture.created_at' => (string) ($capture['created_at'] ?? ''),
            'capture.web_url' => rtrim($appUrl, '/') . '/captures/' . rawurlencode($id),
            'capture.tags' => $tags,
            'capture.attachments' => $attachments,
            'user.display_name' => (string) ($user['display_name'] ?? ''),
            'user.email' => trim((string) ($user['email'] ?? '')) ?: null,
        ];
    }

    private static function renderNode(mixed $node, array $variables, bool $omitEmpty): mixed
    {
        if (!is_array($node)) {
            if (!is_string($node)) {
                return $node;
            }
            if (preg_match('/^\{\{\s*([a-z][a-z0-9_.]*)\s*\}\}$/i', $node, $match)) {
                return $variables[$match[1]] ?? null;
            }

            return preg_replace_callback(
                '/\{\{\s*([a-z][a-z0-9_.]*)\s*\}\}/i',
                static function (array $match) use ($variables): string {
                    $value = $variables[$match[1]] ?? null;
                    if (is_array($value)) {
                        throw new InvalidArgumentException(
                            'Array variable {{' . $match[1] . '}} must be the complete JSON value.',
                        );
                    }

                    return $value === null ? '' : (string) $value;
                },
                $node,
            );
        }

        $isList = array_is_list($node);
        $rendered = [];
        foreach ($node as $key => $value) {
            $value = self::renderNode($value, $variables, $omitEmpty);
            if (!$isList && $omitEmpty && self::isEmpty($value)) {
                continue;
            }
            $rendered[$key] = $value;
        }

        return $isList ? array_values($rendered) : $rendered;
    }

    private static function isEmpty(mixed $value): bool
    {
        return $value === null || $value === '' || (is_array($value) && $value === []);
    }

    private static function renderText(string $template, array $variables): string
    {
        return preg_replace_callback(
            '/\{\{\s*([a-z][a-z0-9_.]*)\s*\}\}/i',
            static function (array $match) use ($variables): string {
                $value = $variables[$match[1]] ?? null;
                if (is_array($value)) {
                    return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                }

                return $value === null ? '' : (string) $value;
            },
            $template,
        ) ?? '';
    }

    private static function multipartFields(array $payload): array
    {
        $fields = [];
        foreach ($payload as $name => $value) {
            $name = (string) $name;
            if (!preg_match('/^[A-Za-z0-9_.\-\[\]]{1,120}$/', $name)) {
                throw new InvalidArgumentException('Multipart field names may contain letters, numbers, dots, dashes, underscores, and brackets.');
            }
            if (is_array($value)) {
                $fields[$name] = json_encode(
                    $value,
                    JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
                );
            } elseif (is_bool($value)) {
                $fields[$name] = $value ? 'true' : 'false';
            } else {
                $fields[$name] = $value === null ? '' : (string) $value;
            }
        }

        return $fields;
    }

    private function addAuthenticationHeaders(array &$headers, array $config): void
    {
        $auth = is_array($config['auth'] ?? null) ? $config['auth'] : [];
        $type = (string) ($auth['type'] ?? 'none');
        if ($type === 'none') {
            return;
        }
        $secret = trim((string) ($config['secret'] ?? ''));
        if ($secret === '') {
            throw new RuntimeException('The endpoint target has no authentication secret.');
        }
        if ($type === 'bearer') {
            $headers[] = 'Authorization: Bearer ' . $secret;
            return;
        }
        if ($type === 'api_key') {
            $headers[] = self::validateHeaderName((string) ($auth['header'] ?? 'X-API-Key')) . ': ' . $secret;
            return;
        }

        throw new RuntimeException('The endpoint authentication type is not supported.');
    }

    private function safeTarget(string $url): string
    {
        $parts = parse_url($url);
        $host = strtolower((string) ($parts['host'] ?? ''));
        $port = (int) ($parts['port'] ?? 443);
        if ($port < 1 || $port > 65_535) {
            throw new RuntimeException('The endpoint URL uses an invalid port.');
        }

        $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : array_merge(
            gethostbynamel($host) ?: [],
            array_map(
                static fn (array $record): string => (string) ($record['ipv6'] ?? ''),
                dns_get_record($host, DNS_AAAA) ?: [],
            ),
        );
        $allowPrivate = $this->config->bool('webhook.allow_private', false);
        foreach (array_values(array_unique($ips)) as $ip) {
            if ($ip === '' || filter_var($ip, FILTER_VALIDATE_IP) === false) {
                continue;
            }
            if (
                !$allowPrivate
                && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false
            ) {
                continue;
            }
            $resolved = str_contains($ip, ':') ? '[' . $ip . ']' : $ip;

            return $host . ':' . $port . ':' . $resolved;
        }

        throw new RuntimeException('The endpoint address is private, reserved, or could not be resolved.');
    }
}
