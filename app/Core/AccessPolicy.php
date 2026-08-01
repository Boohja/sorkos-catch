<?php

declare(strict_types=1);

namespace Catch\Core;

final class AccessPolicy
{
    private readonly bool $prerelease;
    private readonly string $allowedSorkosUserId;

    public function __construct(Config $config)
    {
        $this->prerelease = $config->bool('access.prerelease');
        $this->allowedSorkosUserId = trim((string) $config->get('access.allowed_sorkos_user_id', ''));
    }

    public function isPrerelease(): bool
    {
        return $this->prerelease;
    }

    public function allowsUser(?array $user): bool
    {
        if (!$this->prerelease) {
            return true;
        }

        return $this->allowsSorkosUserId((string) ($user['sorkos_user_id'] ?? ''));
    }

    public function allowsSorkosUserId(string $sorkosUserId): bool
    {
        if (!$this->prerelease) {
            return true;
        }

        $sorkosUserId = trim($sorkosUserId);
        return $this->allowedSorkosUserId !== ''
            && $sorkosUserId !== ''
            && hash_equals($this->allowedSorkosUserId, $sorkosUserId);
    }
}
