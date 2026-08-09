<?php

declare(strict_types=1);

namespace Catch\Http;

final class Response
{
    public static function json(array $data, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }

    public static function shortcut(string $error = '', string $result = '', int $status = 200): never
    {
        self::json(self::shortcutPayload($error, $result), $status);
    }

    /** @return array{error: string}|array{result: string} */
    public static function shortcutPayload(string $error = '', string $result = ''): array
    {
        if (($error === '') === ($result === '')) {
            throw new \LogicException('A Shortcut response must contain either an error or a result.');
        }
        return $error !== '' ? ['error' => $error] : ['result' => $result];
    }

    public static function redirect(string $url): never
    {
        header('Location: ' . $url, true, 303);
        exit;
    }
}
