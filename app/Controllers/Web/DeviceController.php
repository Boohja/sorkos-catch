<?php

declare(strict_types=1);

namespace Catch\Controllers\Web;

use Catch\Core\Config;
use Catch\Core\View;
use Catch\Http\Response;
use Catch\Repositories\CaptureRepository;
use Catch\Repositories\DeviceRepository;
use Catch\Services\AuthService;
use Catch\Services\CaptureDebugService;
use Catch\Services\Csrf;

final class DeviceController
{
    public function __construct(
        private readonly View $view,
        private readonly AuthService $auth,
        private readonly DeviceRepository $devices,
        private readonly CaptureRepository $captures,
        private readonly Csrf $csrf,
        private readonly Config $config,
        private readonly CaptureDebugService $debug,
    ) {
    }

    private function user(): array
    {
        $user = $this->auth->user();
        if (!$user) {
            Response::redirect('/login');
        }

        return $user;
    }

    private function id(string $routeValue): string
    {
        return substr($routeValue, 0, 36);
    }

    private function url(array $device): string
    {
        $asciiName = iconv('UTF-8', 'ASCII//TRANSLIT', $device['name']) ?: $device['name'];
        $slug = strtolower(trim(preg_replace('/[^a-z0-9]+/i', '-', $asciiName), '-'));

        return '/devices/' . $device['id'] . '-' . ($slug ?: 'device');
    }

    public function index(): void
    {
        $user = $this->user();
        $devices = $this->devices->all($user['id']);

        foreach ($devices as &$device) {
            $device['url'] = $this->url($device);
        }
        unset($device);

        $this->view->render('devices/index', [
            'title' => 'Devices',
            'user' => $user,
            'devices' => $devices,
            'csrf' => $this->csrf->token(),
        ]);
    }

    public function new(): void
    {
        $this->view->render('devices/new', [
            'title' => 'Add to Catch',
            'user' => $this->user(),
            'error' => $_SESSION['device_error'] ?? null,
            'csrf' => $this->csrf->token(),
        ]);

        unset($_SESSION['device_error']);
    }

    public function shortcuts(): void
    {
        $appUrl = rtrim((string) $this->config->get('app.url'), '/');

        $this->view->render('devices/shortcuts', [
            'title' => 'Shortcut library',
            'user' => $this->user(),
            'shortcutUrl' => $appUrl . '/assets/shortcuts/Catch%20Setup.shortcut',
        ]);
    }

    public function create(): never
    {
        $user = $this->user();
        if (!$this->csrf->valid($_POST['_csrf'] ?? null)) {
            Response::redirect('/devices/new');
        }

        $kind = (string) ($_POST['kind'] ?? '');
        $platform = (string) ($_POST['platform'] ?? '');
        $name = trim((string) ($_POST['name'] ?? ''));

        $clientType = match (true) {
            $kind === 'mobile' && in_array($platform, ['ios', 'ipados'], true) => 'shortcut',
            $kind === 'automation' && $platform === 'api' => 'api',
            default => null,
        };

        if ($clientType === null || $name === '') {
            $_SESSION['device_error'] = 'Choose a supported setup and enter a name, then try again.';
            Response::redirect('/devices/new');
        }

        $device = $this->devices->create($user['id'], $name, $kind, $platform, $clientType);
        Response::redirect($this->url($device));
    }

    public function show(\Base $f3, array $params): void
    {
        $user = $this->user();
        $device = $this->devices->find($this->id((string) $params['device']), $user['id']);
        if (!$device) {
            Response::redirect('/settings/devices');
        }

        $appUrl = rtrim((string) $this->config->get('app.url'), '/');
        $debugEnabled = $this->debug->enabled();

        $this->view->render('devices/show', [
            'title' => $device['name'],
            'user' => $user,
            'device' => $device,
            'captures' => $this->captures->listByDevice($user['id'], $device['id']),
            'csrf' => $this->csrf->token(),
            'deviceUrl' => $this->url($device),
            'shortcutUrl' => $appUrl . '/assets/shortcuts/Catch%20Setup.shortcut',
            'apiPairUrl' => $appUrl . '/api/devices/pair',
            'pairingCodeTtlMinutes' => DeviceRepository::PAIRING_CODE_TTL_MINUTES,
            'debugEnabled' => $debugEnabled,
            'debugRequests' => $debugEnabled
                ? $this->debug->forDevice($user['id'], $device['id'])
                : [],
        ]);
    }

    public function createPairingCode(\Base $f3, array $params): never
    {
        $user = $this->user();
        $id = $this->id((string) $params['device']);
        if (!$this->csrf->valid($_POST['_csrf'] ?? null)) {
            Response::redirect('/settings/devices');
        }

        $device = $this->devices->find($id, $user['id']);
        if (!$device) {
            Response::redirect('/settings/devices');
        }

        $this->devices->createPairingCode($id, $user['id']);
        Response::redirect($this->url($device));
    }

    public function rename(\Base $f3, array $params): never
    {
        $user = $this->user();
        $id = $this->id((string) $params['device']);
        if (!$this->csrf->valid($_POST['_csrf'] ?? null)) {
            Response::redirect('/settings/devices');
        }

        $name = trim((string) ($_POST['name'] ?? ''));
        $deviceType = (string) ($_POST['device_type'] ?? '');
        $this->devices->rename($id, $user['id'], $name, $deviceType);

        $device = $this->devices->find($id, $user['id']);
        Response::redirect($device ? $this->url($device) : '/settings/devices');
    }

    public function status(\Base $f3, array $params): never
    {
        $user = $this->user();
        $status = $this->devices->status($this->id((string) $params['device']), $user['id']);
        if (!$status) {
            Response::json([
                'error' => [
                    'code' => 'not_found',
                    'message' => 'Device not found.',
                ],
            ], 404);
        }

        Response::json($status);
    }

    public function delete(\Base $f3, array $params): never
    {
        $user = $this->user();
        if (!$this->csrf->valid($_POST['_csrf'] ?? null)) {
            Response::redirect('/settings/devices');
        }

        $id = $this->id((string) $params['device']);
        $this->devices->delete($id, $user['id']);

        if (($_SESSION['catch_web_device_id'] ?? null) === $id) {
            $this->auth->logout();
            Response::redirect('/login');
        }

        Response::redirect('/settings/devices');
    }
}
