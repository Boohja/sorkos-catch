<?php

declare(strict_types=1);

namespace Catch\Controllers\Api;

use Catch\Core\Config;
use Catch\Http\Request;
use Catch\Http\Response;
use Catch\Repositories\DeviceRepository;

final class ShortcutController
{
    public function __construct(
        private readonly DeviceRepository $devices,
        private readonly Config $config,
    ) {}

    public function pairDevice(): never
    {
        $this->pair(false);
    }

    public function pairShortcut(): never
    {
        $this->pair(true);
    }

    private function pair(bool $shortcut): never
    {
        $contentType = strtolower($_SERVER['CONTENT_TYPE'] ?? '');
        $input = str_contains($contentType, 'application/json') ? Request::json() : $_POST;
        $code = (string) ($input['pairing_code'] ?? '');

        try {
            $result = $this->devices->pair($code);
        } catch (\Throwable) {
            if ($shortcut) {
                Response::shortcut('The device could not be paired.', '', 500);
            }
            Response::json(['error' => ['code' => 'pairing_failed', 'message' => 'The device could not be paired.']], 500);
        }

        if (!$result) {
            if ($shortcut) {
                Response::shortcut('The pairing code is invalid.', '', 401);
            }
            Response::json(['error' => ['code' => 'invalid_pairing_code', 'message' => 'The pairing code is invalid.']], 401);
        }

        if ($shortcut) {
            Response::shortcut('', (string) $result['device_token'], 201);
        }

        Response::json([
            'device_token' => $result['device_token'],
            'token_type' => 'Bearer',
            'capture_endpoint' => rtrim((string) $this->config->get('app.url'), '/') . '/api/shortcut/captures',
        ], 201);
    }
}
