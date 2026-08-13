<?php

declare(strict_types=1);

namespace Catch\Services\LinkPreview;

final class ProviderRegistry
{
    /** @return list<Provider> */
    public static function defaults(): array
    {
        return [
            new TikTokProvider(),
            new AppStoreProvider(),
        ];
    }
}
