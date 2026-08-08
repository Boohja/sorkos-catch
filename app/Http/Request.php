<?php

declare(strict_types=1);

namespace Catch\Http;

final class Request
{
    public static function json(): array
    {
        $content = file_get_contents('php://input') ?: '';
        $decoded = json_decode($content, true);
        return is_array($decoded) ? $decoded : [];
    }

    public static function bearerToken(): ?string
    {
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
        if($header===''&&function_exists('getallheaders')){
            foreach(getallheaders() as $name=>$value)if(strcasecmp((string)$name,'Authorization')===0){$header=(string)$value;break;}
        }
        return preg_match('/^Bearer\s+(.+)$/i', $header, $matches) ? trim($matches[1]) : null;
    }

    public static function wantsJson(): bool
    {
        return str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') || str_starts_with($_SERVER['REQUEST_URI'] ?? '', '/api/');
    }

    public static function isShortcutApi(): bool
    {
        $path = (string) (parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');
        return $path === '/api/shortcut' || str_starts_with($path, '/api/shortcut/');
    }
}
