<?php

declare(strict_types=1);

namespace Catch\Services;

use Catch\Services\LinkPreview\Provider;
use Catch\Services\LinkPreview\ProviderRegistry;
use Catch\Support\CaptureTitle;
use DOMDocument;
use DOMXPath;

final class RemoteContentService
{
    private const PAGE_LIMIT = 524288;
    private const LINK_PREVIEW_USER_AGENT = 'Discordbot/2.0';
    private ?string $lastError = null;
    private ?string $lastResolvedUrl = null;
    /** @var list<Provider> */
    private array $previewProviders;

    /** @param list<Provider>|null $previewProviders */
    public function __construct(
        private readonly int $imageLimit = 15728640,
        ?array $previewProviders = null,
    ) {
        $this->previewProviders = $previewProviders ?? ProviderRegistry::defaults();
    }

    public function pageTitle(string $url): ?string
    {
        $resource = $this->request($url, self::PAGE_LIMIT, ['text/html', 'application/xhtml+xml']);
        if (!$resource) {
            return null;
        }
        if (!preg_match('~<title\b[^>]*>(.*?)</title>~is', $resource['body'], $match)
            && !preg_match('~<meta\b[^>]*(?:property|name)=["\']og:title["\'][^>]*content=["\'](.*?)["\']~is', $resource['body'], $match)
            && !preg_match('~<meta\b[^>]*content=["\'](.*?)["\'][^>]*(?:property|name)=["\']og:title["\']~is', $resource['body'], $match)) {
            return null;
        }
        $title = trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($match[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?? '');
        return CaptureTitle::shorten($title);
    }

    /**
     * @return array{
     *     metadata: array<string, string>,
     *     image: array{name: string, type: string, contents: string}|null
     * }|null
     */
    public function linkPreview(string $url): ?array
    {
        $page = $this->request(
            $url,
            self::PAGE_LIMIT,
            ['text/html', 'application/xhtml+xml'],
            self::LINK_PREVIEW_USER_AGENT,
            true,
        );
        $canonicalUrl = $page['url'] ?? $this->lastResolvedUrl ?? $url;
        $document = $page ? $this->documentMetadata($page['body'], $canonicalUrl) : [];
        $canonicalUrl = $document['canonical_url'] ?? $canonicalUrl;
        $providerAdapter = $this->previewProvider($canonicalUrl);
        $canonicalUrl = $providerAdapter?->canonicalUrl($canonicalUrl) ?? $canonicalUrl;
        $embedUrl = $document['oembed_url'] ?? $providerAdapter?->oembedUrl($canonicalUrl);
        $embed = $embedUrl ? $this->oembed($embedUrl) : [];
        $providerPreview = $this->providerPreview($providerAdapter, $canonicalUrl);

        $title = CaptureTitle::shorten($this->firstText(
            $embed['title'] ?? null,
            $providerPreview['title'] ?? null,
            $document['title'] ?? null,
        ));
        $description = $this->firstText(
            $providerPreview['description'] ?? null,
            $document['description'] ?? null,
            $embed['author_name'] ?? null,
        );
        $provider = $this->firstText(
            $embed['provider_name'] ?? null,
            $providerPreview['provider_name'] ?? null,
            $document['provider_name'] ?? null,
            $this->providerName($canonicalUrl),
        );
        $author = $this->firstText(
            $embed['author_name'] ?? null,
            $providerPreview['author_name'] ?? null,
            $document['author'] ?? null,
        );
        $imageUrl = $this->firstUrl(
            $embed['thumbnail_url'] ?? null,
            $providerPreview['image_url'] ?? null,
            $document['image_url'] ?? null,
        );
        $image = $imageUrl ? $this->image($imageUrl) : null;

        $metadata = array_filter([
            'canonical_url' => $canonicalUrl,
            'title' => $title,
            'description' => $description,
            'provider_name' => $provider,
            'author_name' => $author,
            'image_source_url' => $imageUrl,
        ], static fn (?string $value): bool => $value !== null && $value !== '');

        return $title || $description || $author || $image
            ? ['metadata' => $metadata, 'image' => $image]
            : null;
    }

    /** @return array{name:string,type:string,contents:string}|null */
    public function image(string $url): ?array
    {
        $url = $this->canonicalImageUrl($url);
        $resource = $this->request($url, $this->imageLimit, ['image/jpeg', 'image/png', 'image/webp', 'image/gif']);
        if (!$resource) {
            return null;
        }
        $path = (string) parse_url($resource['url'], PHP_URL_PATH);
        $name = basename($path) ?: 'captured-image';
        if (!str_contains($name, '.')) {
            $name .= match ($resource['type']) {
                'image/jpeg' => '.jpg', 'image/png' => '.png', 'image/webp' => '.webp', 'image/gif' => '.gif', default => '',
            };
        }
        return ['name' => mb_substr($name, 0, 500), 'type' => $resource['type'], 'contents' => $resource['body']];
    }

    public function lastError(): ?string
    {
        return $this->lastError;
    }

    /** @return array{body:string,type:string,url:string}|null */
    private function request(
        string $url,
        int $limit,
        array $allowedTypes,
        string $userAgent = 'Catch link preview/1.0',
        bool $allowTruncated = false,
    ): ?array {
        $this->lastError = null;
        $this->lastResolvedUrl = null;
        for ($redirects = 0; $redirects <= 3; $redirects++) {
            $this->lastResolvedUrl = $url;
            $target = $this->safeTarget($url);
            if (!$target) {
                $this->lastError ??= 'The remote address is not safe to retrieve.';
                return null;
            }
            $body = '';
            $headers = [];
            $tooLarge = false;
            $curl = curl_init($url);
            if ($curl === false) {
                $this->lastError = 'The remote request could not be initialized.';
                return null;
            }
            $ok = curl_setopt_array($curl, [
                CURLOPT_RETURNTRANSFER => false,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_CONNECTTIMEOUT => 4,
                CURLOPT_TIMEOUT => 8,
                CURLOPT_USERAGENT => $userAgent,
                CURLOPT_HTTPHEADER => [
                    'Accept: text/html,application/xhtml+xml,application/json,image/avif,image/webp,image/png,image/jpeg;q=0.9,*/*;q=0.1',
                    'Accept-Language: en-US,en;q=0.8',
                ],
                CURLOPT_RESOLVE => [$target['resolve']],
                CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use (&$headers): int {
                    $length = strlen($line);
                    if (str_contains($line, ':')) {
                        [$name, $value] = explode(':', $line, 2);
                        $headers[strtolower(trim($name))] = trim($value);
                    }
                    return $length;
                },
                CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$body, $limit, &$tooLarge): int {
                    if (strlen($body) + strlen($chunk) > $limit) {
                        $tooLarge = true;
                        return 0;
                    }
                    $body .= $chunk;
                    return strlen($chunk);
                },
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
            ]);
            if (!$ok) {
                $this->lastError = 'The remote request could not be configured.';
                return null;
            }
            $executed = curl_exec($curl);
            $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
            $type = strtolower(trim(explode(';', (string) curl_getinfo($curl, CURLINFO_CONTENT_TYPE), 2)[0]));
            $curlError = curl_error($curl);
            $curl = null;
            if ($executed === false) {
                if (
                    $tooLarge
                    && $allowTruncated
                    && $body !== ''
                    && $status >= 200
                    && $status < 300
                    && in_array($type, $allowedTypes, true)
                ) {
                    return ['body' => $body, 'type' => $type, 'url' => $url];
                }
                $this->lastError = $tooLarge
                    ? 'The remote response exceeds the configured download limit.'
                    : 'The remote request failed' . ($curlError !== '' ? ': ' . $curlError : '.');
                return null;
            }
            if ($status >= 300 && $status < 400 && isset($headers['location'])) {
                $url = $this->absoluteUrl($url, $headers['location']);
                if ($url === null) {
                    $this->lastError = 'The remote server returned an invalid redirect.';
                    return null;
                }
                continue;
            }
            if ($status < 200 || $status >= 300) {
                $this->lastError = 'The remote server returned HTTP ' . $status . '.';
                return null;
            }
            if (!in_array($type, $allowedTypes, true)) {
                $this->lastError = 'The remote server returned unsupported content type ' . ($type ?: 'unknown') . '.';
                return null;
            }
            return ['body' => $body, 'type' => $type, 'url' => $url];
        }
        $this->lastError = 'The remote server redirected too many times.';
        return null;
    }

    private function canonicalImageUrl(string $url): string
    {
        $parts = parse_url($url);
        if (!is_array($parts) || strtolower((string)($parts['host'] ?? '')) !== 'preview.redd.it') {
            return $url;
        }
        $filename = basename((string)($parts['path'] ?? ''));
        if (!preg_match('~-v\d+-([a-z0-9]+)\.([a-z0-9]+)$~i', $filename, $match)) {
            return $url;
        }
        return 'https://i.redd.it/' . $match[1] . '.' . strtolower($match[2]);
    }

    /** @return array<string, string> */
    private function documentMetadata(string $html, string $baseUrl): array
    {
        $previous = libxml_use_internal_errors(true);
        $document = new DOMDocument();
        $loaded = $document->loadHTML(
            '<?xml encoding="utf-8" ?>' . $html,
            LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING,
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (!$loaded) {
            return [];
        }

        $xpath = new DOMXPath($document);
        $meta = [];
        foreach ($xpath->query('//meta[@content]') ?: [] as $node) {
            $key = strtolower(trim(
                $node->attributes?->getNamedItem('property')?->nodeValue
                ?? $node->attributes?->getNamedItem('name')?->nodeValue
                ?? '',
            ));
            $value = $this->cleanText($node->attributes?->getNamedItem('content')?->nodeValue);
            if ($key !== '' && $value !== null && !isset($meta[$key])) {
                $meta[$key] = $value;
            }
        }

        $titleNode = $xpath->query('//title')->item(0);
        $title = $this->firstText(
            $meta['og:title'] ?? null,
            $meta['twitter:title'] ?? null,
            $titleNode?->textContent,
        );
        $canonical = $this->linkUrl($xpath, 'canonical', $baseUrl);
        $oembed = $this->oembedUrl($xpath, $baseUrl);
        $image = $this->firstUrl(
            $this->absoluteUrl($baseUrl, $meta['og:image:secure_url'] ?? ''),
            $this->absoluteUrl($baseUrl, $meta['og:image'] ?? ''),
            $this->absoluteUrl($baseUrl, $meta['twitter:image'] ?? ''),
        );

        return array_filter([
            'title' => $title,
            'description' => $this->firstText(
                $meta['og:description'] ?? null,
                $meta['twitter:description'] ?? null,
                $meta['description'] ?? null,
            ),
            'provider_name' => $this->firstText($meta['og:site_name'] ?? null),
            'author' => $this->firstText($meta['author'] ?? null, $meta['article:author'] ?? null),
            'canonical_url' => $canonical,
            'oembed_url' => $oembed,
            'image_url' => $image,
        ], static fn (?string $value): bool => $value !== null && $value !== '');
    }

    private function linkUrl(DOMXPath $xpath, string $relation, string $baseUrl): ?string
    {
        foreach ($xpath->query('//link[@href]') ?: [] as $node) {
            $rel = strtolower((string) $node->attributes?->getNamedItem('rel')?->nodeValue);
            if (!in_array($relation, preg_split('/\s+/', trim($rel)) ?: [], true)) {
                continue;
            }

            return $this->absoluteUrl(
                $baseUrl,
                (string) $node->attributes?->getNamedItem('href')?->nodeValue,
            );
        }

        return null;
    }

    private function oembedUrl(DOMXPath $xpath, string $baseUrl): ?string
    {
        foreach ($xpath->query('//link[@href]') ?: [] as $node) {
            $rel = strtolower((string) $node->attributes?->getNamedItem('rel')?->nodeValue);
            $type = strtolower((string) $node->attributes?->getNamedItem('type')?->nodeValue);
            if (!str_contains($rel, 'alternate') || !str_contains($type, 'json+oembed')) {
                continue;
            }

            return $this->absoluteUrl(
                $baseUrl,
                (string) $node->attributes?->getNamedItem('href')?->nodeValue,
            );
        }

        return null;
    }

    /** @return array<string, mixed> */
    private function oembed(string $url): array
    {
        $resource = $this->request(
            $url,
            262144,
            ['application/json', 'application/json+oembed'],
        );
        if (!$resource) {
            return [];
        }

        $decoded = json_decode($resource['body'], true);

        return is_array($decoded) ? $decoded : [];
    }

    private function previewProvider(string $url): ?Provider
    {
        foreach ($this->previewProviders as $provider) {
            if ($provider->supports($url)) {
                return $provider;
            }
        }

        return null;
    }

    /** @return array<string, string> */
    private function providerPreview(?Provider $provider, string $url): array
    {
        $lookupUrl = $provider?->lookupUrl($url);
        if ($lookupUrl === null) {
            return [];
        }

        $resource = $this->request(
            $lookupUrl,
            262144,
            ['application/json', 'text/javascript'],
        );
        if (!$resource) {
            return [];
        }

        try {
            $payload = json_decode($resource['body'], true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        return $provider->mapLookup($payload);
    }

    private function providerName(string $url): ?string
    {
        $host = strtolower((string) (parse_url($url, PHP_URL_HOST) ?? ''));
        $host = preg_replace('/^www\./', '', $host) ?? $host;

        return $host === '' ? null : $host;
    }

    private function firstText(mixed ...$values): ?string
    {
        foreach ($values as $value) {
            $text = $this->cleanText($value);
            if ($text !== null) {
                return mb_substr($text, 0, 1000);
            }
        }

        return null;
    }

    private function cleanText(mixed $value): ?string
    {
        if (!is_scalar($value)) {
            return null;
        }

        $text = trim(preg_replace(
            '/\s+/u',
            ' ',
            html_entity_decode(strip_tags((string) $value), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
        ) ?? '');

        return $text === '' ? null : $text;
    }

    private function firstUrl(mixed ...$values): ?string
    {
        foreach ($values as $value) {
            if (is_string($value) && $this->isHttpUrl($value)) {
                return $value;
            }
        }

        return null;
    }

    /** @return array{resolve:string}|null */
    private function safeTarget(string $url): ?array
    {
        $parts = parse_url($url);
        if (!is_array($parts) || !in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true) || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }
        $scheme = strtolower((string) $parts['scheme']);
        $port = (int) ($parts['port'] ?? ($scheme === 'https' ? 443 : 80));
        if (!in_array($port, [80, 443], true)) {
            return null;
        }
        $host = strtolower((string) $parts['host']);
        $ips = array_values(array_unique(array_merge(gethostbynamel($host) ?: [], array_map(static fn (array $record): string => (string) ($record['ipv6'] ?? ''), dns_get_record($host, DNS_AAAA) ?: []))));
        $ip = null;
        foreach ($ips as $candidate) {
            if ($candidate !== '' && filter_var($candidate, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                $ip = $candidate;
                break;
            }
        }
        if ($ip === null) {
            $this->lastError = 'The remote address could not be resolved.';
            return null;
        }
        $resolvedIp = str_contains($ip, ':') ? '[' . $ip . ']' : $ip;
        return ['resolve' => $host . ':' . $port . ':' . $resolvedIp];
    }

    private function absoluteUrl(string $base, string $location): ?string
    {
        if ($location === '') {
            return null;
        }
        if (filter_var($location, FILTER_VALIDATE_URL)) {
            return $this->isHttpUrl($location) ? $location : null;
        }
        $parts = parse_url($base);
        if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            return null;
        }
        if (str_starts_with($location, '//')) {
            return $parts['scheme'] . ':' . $location;
        }
        $origin = $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
        if (str_starts_with($location, '/')) {
            return $origin . $location;
        }
        $directory = preg_replace('~/[^/]*$~', '/', (string) ($parts['path'] ?? '/')) ?: '/';
        return $origin . $directory . $location;
    }

    private function isHttpUrl(string $url): bool
    {
        $scheme = strtolower((string) (parse_url($url, PHP_URL_SCHEME) ?? ''));

        return filter_var($url, FILTER_VALIDATE_URL) !== false
            && in_array($scheme, ['http', 'https'], true);
    }
}
