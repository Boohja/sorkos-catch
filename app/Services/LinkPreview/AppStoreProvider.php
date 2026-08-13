<?php

declare(strict_types=1);

namespace Catch\Services\LinkPreview;

final class AppStoreProvider implements Provider
{
    public function supports(string $url): bool
    {
        return strtolower((string) (parse_url($url, PHP_URL_HOST) ?? '')) === 'apps.apple.com'
            && preg_match('~/id\d+~', (string) (parse_url($url, PHP_URL_PATH) ?? '')) === 1;
    }

    public function canonicalUrl(string $url): string
    {
        return $url;
    }

    public function oembedUrl(string $url): ?string
    {
        return null;
    }

    public function lookupUrl(string $url): ?string
    {
        $path = (string) (parse_url($url, PHP_URL_PATH) ?? '');
        if (preg_match('~/id(\d+)~', $path, $match) !== 1) {
            return null;
        }

        $country = preg_match('~^/([a-z]{2})/~i', $path, $countryMatch) === 1
            ? strtolower($countryMatch[1])
            : 'us';

        return 'https://itunes.apple.com/lookup?' . http_build_query([
            'id' => $match[1],
            'country' => $country,
            'entity' => 'software',
        ], arg_separator: '&', encoding_type: PHP_QUERY_RFC3986);
    }

    public function mapLookup(array $payload): array
    {
        $result = is_array($payload['results'][0] ?? null)
            ? $payload['results'][0]
            : [];
        $artwork = $this->url($result['artworkUrl512'] ?? null)
            ?? $this->url($result['artworkUrl100'] ?? null);
        if ($artwork !== null) {
            $artwork = preg_replace('~/\d+x\d+[^/]*/~', '/600x600bb/', $artwork) ?? $artwork;
        }

        return array_filter([
            'title' => $this->text($result['trackName'] ?? null),
            'description' => $this->text($result['description'] ?? null),
            'provider_name' => 'App Store',
            'author_name' => $this->text($result['sellerName'] ?? null),
            'image_url' => $artwork,
        ], static fn (?string $value): bool => $value !== null && $value !== '');
    }

    private function text(mixed $value): ?string
    {
        if (!is_scalar($value)) {
            return null;
        }

        $text = trim(preg_replace('/\s+/u', ' ', (string) $value) ?? '');

        return $text === '' ? null : mb_substr($text, 0, 1000);
    }

    private function url(mixed $value): ?string
    {
        return is_string($value) && filter_var($value, FILTER_VALIDATE_URL) !== false
            ? $value
            : null;
    }
}
