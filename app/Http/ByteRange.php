<?php

declare(strict_types=1);

namespace Catch\Http;

use InvalidArgumentException;

final class ByteRange
{
    /** @return array{start: int, end: int, length: int}|null */
    public static function parse(?string $header, int $size): ?array
    {
        $header = trim((string) $header);
        if ($header === '') {
            return null;
        }

        if ($size <= 0 || !preg_match('/^bytes=(\d*)-(\d*)$/', $header, $match)) {
            throw new InvalidArgumentException('Invalid byte range.');
        }

        $startValue = $match[1];
        $endValue = $match[2];
        if ($startValue === '' && $endValue === '') {
            throw new InvalidArgumentException('Invalid byte range.');
        }

        if ($startValue === '') {
            $suffixLength = (int) $endValue;
            if ($suffixLength <= 0) {
                throw new InvalidArgumentException('Invalid byte range.');
            }
            $start = max(0, $size - $suffixLength);
            $end = $size - 1;
        } else {
            $start = (int) $startValue;
            $end = $endValue === '' ? $size - 1 : min((int) $endValue, $size - 1);
        }

        if ($start >= $size || $start > $end) {
            throw new InvalidArgumentException('Unsatisfiable byte range.');
        }

        return [
            'start' => $start,
            'end' => $end,
            'length' => $end - $start + 1,
        ];
    }
}
