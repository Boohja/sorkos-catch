<?php

declare(strict_types=1);

namespace Catch\Support;

final class CaptureTitle
{
    public const MAX_LENGTH = 100;

    public static function shorten(mixed $value): ?string
    {
        $title = trim((string) $value);
        if ($title === '') {
            return null;
        }
        if (mb_strlen($title) <= self::MAX_LENGTH) {
            return $title;
        }

        $prefix = rtrim(mb_substr($title, 0, self::MAX_LENGTH - 3));
        $lastSpace = mb_strrpos($prefix, ' ');
        if ($lastSpace !== false && $lastSpace >= 70) {
            $prefix = rtrim(mb_substr($prefix, 0, $lastSpace));
        }

        return $prefix . '...';
    }
}
