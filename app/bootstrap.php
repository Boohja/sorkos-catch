<?php

declare(strict_types=1);

$catchRoot = dirname(__DIR__);
require_once $catchRoot . '/lib/f3/base.php';

spl_autoload_register(static function (string $class) use ($catchRoot): void {
    $prefix = 'Catch\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $path = $catchRoot . '/app/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($path)) {
        require_once $path;
    }
});
