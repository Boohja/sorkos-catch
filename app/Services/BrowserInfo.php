<?php

declare(strict_types=1);

namespace Catch\Services;

final class BrowserInfo
{
    /** @return array{browser:string,version:string,os:string,osKey:string,clientApp:string,clientIcon:string,label:string} */
    public static function fromUserAgent(string $userAgent): array
    {
        $browser = 'Web browser';
        $version = '';
        foreach ([
            'Edg/' => 'Microsoft Edge',
            'OPR/' => 'Opera',
            'Firefox/' => 'Firefox',
            'Chrome/' => 'Chrome',
            'Version/' => 'Safari',
        ] as $needle => $name) {
            if (preg_match('~' . preg_quote($needle, '~') . '([0-9.]+)~', $userAgent, $match)) {
                $browser = $name;
                $version = $match[1];
                break;
            }
        }

        $os = match (true) {
            str_contains($userAgent, 'Windows') => 'Windows',
            str_contains($userAgent, 'Android') => 'Android',
            str_contains($userAgent, 'iPhone'), str_contains($userAgent, 'iPad') => 'iOS',
            str_contains($userAgent, 'Mac OS X') => 'macOS',
            str_contains($userAgent, 'Linux') => 'Linux',
            default => 'Unknown OS',
        };

        $osKey = match ($os) {
            'Windows' => 'windows',
            'Android' => 'android',
            'iOS' => 'ios',
            'macOS' => 'macos',
            'Linux' => 'linux',
            default => 'unknown',
        };
        $clientApp = match ($browser) {
            'Chrome' => 'chrome',
            'Firefox' => 'firefox',
            'Microsoft Edge' => 'edge',
            'Safari' => 'safari',
            default => 'web-app',
        };
        $clientIcon = match ($clientApp) {
            'chrome', 'edge' => 'brand-chrome',
            'firefox' => 'brand-firefox',
            default => 'app',
        };

        return compact('browser', 'version', 'os', 'osKey', 'clientApp', 'clientIcon')
            + ['label' => $browser . ' on ' . $os];
    }
}
