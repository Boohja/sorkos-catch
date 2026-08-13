<?php

declare(strict_types=1);

namespace Catch\Services\LinkPreview;

final class TikTokProvider implements Provider
{
    public function supports(string $url): bool
    {
        $host = strtolower((string) (parse_url($url, PHP_URL_HOST) ?? ''));

        return $host === 'tiktok.com' || str_ends_with($host, '.tiktok.com');
    }

    public function canonicalUrl(string $url): string
    {
        $parts = parse_url($url);
        if (!is_array($parts)
            || !preg_match('~^/@[^/]+/video/\d+~', (string) ($parts['path'] ?? ''))) {
            return $url;
        }

        return (string) $parts['scheme'] . '://' . strtolower((string) $parts['host']) . $parts['path'];
    }

    public function oembedUrl(string $url): ?string
    {
        return 'https://www.tiktok.com/oembed?url=' . rawurlencode($url);
    }

    public function lookupUrl(string $url): ?string
    {
        return null;
    }

    public function mapLookup(array $payload): array
    {
        return [];
    }
}
