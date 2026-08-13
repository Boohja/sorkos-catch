<?php

declare(strict_types=1);

namespace Catch\Controllers\Web;

use Catch\Core\View;
use Catch\Http\Response;
use Catch\Repositories\DeviceRepository;
use Catch\Services\AuthService;
use Catch\Services\Csrf;

final class AccountController
{
    public function __construct(
        private readonly View $view,
        private readonly AuthService $auth,
        private readonly DeviceRepository $devices,
        private readonly Csrf $csrf,
    ) {
    }

    public function profile(): void
    {
        $user = $this->user();

        $this->view->render('account/profile', [
            'title' => 'Profile',
            'user' => $user,
            'csrf' => $this->csrf->token(),
        ]);
    }

    public function settings(): void
    {
        $this->view->render('account/settings', [
            'title' => 'Settings',
            'user' => $this->user(),
            'settingsTab' => 'general',
            'csrf' => $this->csrf->token(),
        ]);
    }

    public function devices(): void
    {
        $user = $this->user();
        $devices = $this->devices->all($user['id']);

        foreach ($devices as &$device) {
            $device['url'] = $this->deviceUrl($device);
        }
        unset($device);

        $this->view->render('account/settings', [
            'title' => 'Settings · Devices',
            'user' => $user,
            'settingsTab' => 'devices',
            'devices' => $devices,
            'csrf' => $this->csrf->token(),
        ]);
    }

    private function user(): array
    {
        $user = $this->auth->user();
        if (!$user) {
            Response::redirect('/login');
        }

        return $user;
    }

    private function deviceUrl(array $device): string
    {
        $asciiName = iconv('UTF-8', 'ASCII//TRANSLIT', $device['name']) ?: $device['name'];
        $slug = strtolower(trim(preg_replace('/[^a-z0-9]+/i', '-', $asciiName), '-'));

        return '/devices/' . $device['id'] . '-' . ($slug ?: 'device');
    }
}
