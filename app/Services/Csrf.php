<?php
declare(strict_types=1);
namespace Catch\Services;

final class Csrf
{
    public function token(): string
    {
        return $_SESSION['_csrf'] ??= bin2hex(random_bytes(24));
    }
    public function valid(?string $token): bool
    {
        return is_string($token) && isset($_SESSION['_csrf']) && hash_equals($_SESSION['_csrf'],$token);
    }
}
