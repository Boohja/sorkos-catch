<?php

declare(strict_types=1);

namespace Catch\Services\LinkPreview;

interface Provider
{
    public function supports(string $url): bool;

    public function canonicalUrl(string $url): string;

    public function oembedUrl(string $url): ?string;

    public function lookupUrl(string $url): ?string;

    /** @return array<string, string> */
    public function mapLookup(array $payload): array;
}
