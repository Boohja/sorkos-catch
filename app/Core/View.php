<?php

declare(strict_types=1);

namespace Catch\Core;

use DateTimeImmutable;
use DateTimeZone;

final class View
{
    public function __construct(private readonly string $path)
    {
    }

    public function render(string $template, array $data = [], int $httpStatus = 200): void
    {
        http_response_code($httpStatus);
        extract($data, EXTR_SKIP);
        $templateFile = $this->path . '/' . $template . '.php';
        ob_start();
        require $templateFile;
        $content = (string) ob_get_clean();
        require $this->path . '/layout.php';
    }

    public function partial(string $template, array $data = []): string
    {
        extract($data, EXTR_SKIP);
        ob_start();
        require $this->path . '/' . $template . '.php';

        return (string) ob_get_clean();
    }

    public function relativeTime(?string $value, ?DateTimeImmutable $now = null): string
    {
        if (!$value) {
            return '<1m';
        }

        $zone = new DateTimeZone('UTC');
        $date = new DateTimeImmutable($value, $zone);
        $now ??= new DateTimeImmutable('now', $zone);
        $seconds = max(0, $now->getTimestamp() - $date->getTimestamp());

        return match (true) {
            $seconds < 60 => '<1m',
            $seconds < 3_600 => intdiv($seconds, 60) . 'm',
            $seconds < 86_400 => intdiv($seconds, 3_600) . 'h',
            $seconds < 2_592_000 => intdiv($seconds, 86_400) . 'd',
            $seconds < 31_536_000 => intdiv($seconds, 2_592_000) . 'mo',
            default => intdiv($seconds, 31_536_000) . 'y',
        };
    }
}
