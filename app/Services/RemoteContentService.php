<?php

declare(strict_types=1);

namespace Catch\Services;

final class RemoteContentService
{
    private const PAGE_LIMIT = 524288;
    private ?string $lastError = null;

    public function __construct(private readonly int $imageLimit = 15728640) {}

    public function pageTitle(string $url): ?string
    {
        $resource = $this->request($url, self::PAGE_LIMIT, ['text/html', 'application/xhtml+xml']);
        if (!$resource) return null;
        if (!preg_match('~<title\b[^>]*>(.*?)</title>~is', $resource['body'], $match)
            && !preg_match('~<meta\b[^>]*(?:property|name)=["\']og:title["\'][^>]*content=["\'](.*?)["\']~is', $resource['body'], $match)
            && !preg_match('~<meta\b[^>]*content=["\'](.*?)["\'][^>]*(?:property|name)=["\']og:title["\']~is', $resource['body'], $match)) return null;
        $title = trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($match[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?? '');
        return $title === '' ? null : mb_substr($title, 0, 500);
    }

    /** @return array{name:string,type:string,contents:string}|null */
    public function image(string $url): ?array
    {
        $url = $this->canonicalImageUrl($url);
        $resource = $this->request($url, $this->imageLimit, ['image/jpeg', 'image/png', 'image/webp', 'image/gif']);
        if (!$resource) return null;
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
    private function request(string $url, int $limit, array $allowedTypes): ?array
    {
        $this->lastError = null;
        for ($redirects = 0; $redirects <= 3; $redirects++) {
            $target = $this->safeTarget($url);
            if (!$target) { $this->lastError = 'The remote address is not safe to retrieve.'; return null; }
            $body = '';
            $headers = [];
            $tooLarge = false;
            $curl = curl_init($url);
            if ($curl === false) { $this->lastError = 'The remote request could not be initialized.'; return null; }
            $ok = curl_setopt_array($curl, [
                CURLOPT_RETURNTRANSFER => false,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_CONNECTTIMEOUT => 4,
                CURLOPT_TIMEOUT => 8,
                CURLOPT_USERAGENT => 'Catch link preview/1.0',
                CURLOPT_HTTPHEADER => ['Accept: text/html,application/xhtml+xml,image/avif,image/webp,image/png,image/jpeg;q=0.9,*/*;q=0.1'],
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
                    if (strlen($body) + strlen($chunk) > $limit) { $tooLarge = true; return 0; }
                    $body .= $chunk;
                    return strlen($chunk);
                },
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
            ]);
            if (!$ok) { $this->lastError = 'The remote request could not be configured.'; return null; }
            $executed = curl_exec($curl);
            $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
            $type = strtolower(trim(explode(';', (string) curl_getinfo($curl, CURLINFO_CONTENT_TYPE), 2)[0]));
            $curlError = curl_error($curl);
            $curl = null;
            if ($executed === false) { $this->lastError = $tooLarge ? 'The image exceeds the configured upload limit.' : 'The remote request failed'.($curlError!==''?': '.$curlError:'.'); return null; }
            if ($status >= 300 && $status < 400 && isset($headers['location'])) {
                $url = $this->absoluteUrl($url, $headers['location']);
                if ($url === null) { $this->lastError = 'The remote server returned an invalid redirect.'; return null; }
                continue;
            }
            if ($status < 200 || $status >= 300) { $this->lastError = 'The remote server returned HTTP '.$status.'.'; return null; }
            if (!in_array($type, $allowedTypes, true)) { $this->lastError = 'The remote server returned unsupported content type '.($type?:'unknown').'.'; return null; }
            return ['body' => $body, 'type' => $type, 'url' => $url];
        }
        $this->lastError = 'The remote server redirected too many times.';
        return null;
    }

    private function canonicalImageUrl(string $url): string
    {
        $parts = parse_url($url);
        if (!is_array($parts) || strtolower((string)($parts['host']??'')) !== 'preview.redd.it') return $url;
        $filename = basename((string)($parts['path']??''));
        if (!preg_match('~-v\d+-([a-z0-9]+)\.([a-z0-9]+)$~i', $filename, $match)) return $url;
        return 'https://i.redd.it/'.$match[1].'.'.strtolower($match[2]);
    }

    /** @return array{resolve:string}|null */
    private function safeTarget(string $url): ?array
    {
        $parts = parse_url($url);
        if (!is_array($parts) || !in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true) || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])) return null;
        $scheme = strtolower((string) $parts['scheme']);
        $port = (int) ($parts['port'] ?? ($scheme === 'https' ? 443 : 80));
        if (!in_array($port, [80, 443], true)) return null;
        $host = strtolower((string) $parts['host']);
        $ips = array_values(array_unique(array_merge(gethostbynamel($host) ?: [], array_map(static fn (array $record): string => (string) ($record['ipv6'] ?? ''), dns_get_record($host, DNS_AAAA) ?: []))));
        $ip = null;
        foreach ($ips as $candidate) {
            if ($candidate !== '' && filter_var($candidate, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) { $ip = $candidate; break; }
        }
        if ($ip === null) return null;
        $resolvedIp = str_contains($ip, ':') ? '[' . $ip . ']' : $ip;
        return ['resolve' => $host . ':' . $port . ':' . $resolvedIp];
    }

    private function absoluteUrl(string $base, string $location): ?string
    {
        if (filter_var($location, FILTER_VALIDATE_URL)) return $location;
        $parts = parse_url($base);
        if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) return null;
        if (str_starts_with($location, '//')) return $parts['scheme'] . ':' . $location;
        $origin = $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
        if (str_starts_with($location, '/')) return $origin . $location;
        $directory = preg_replace('~/[^/]*$~', '/', (string) ($parts['path'] ?? '/')) ?: '/';
        return $origin . $directory . $location;
    }
}
