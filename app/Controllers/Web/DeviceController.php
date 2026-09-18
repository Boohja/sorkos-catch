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

    private function url(array $client): string
    {
        $asciiName = iconv('UTF-8', 'ASCII//TRANSLIT', $client['name']) ?: $client['name'];
        $slug = strtolower(trim(preg_replace('/[^a-z0-9]+/i', '-', $asciiName), '-'));

        return '/clients/' . $client['id'] . '-' . ($slug ?: 'client');
    }

    public function index(): void
    {
        $user = $this->user();
        $devices = $this->devices->physicalDevices($user['id']);
        $currentClientId = $this->currentClientId();

        foreach ($devices as &$device) {
            $device['current'] = false;
            foreach ($device['clients'] as &$client) {
                $client['url'] = $this->url($client);
                $client['current'] = $client['id'] === $currentClientId;
                $device['current'] = $device['current'] || $client['current'];
            }
            unset($client);
        }
        unset($device);
        $unassignedClients = $this->devices->unassignedClients($user['id']);
        foreach ($unassignedClients as &$client) {
            $client['url'] = $this->url($client);
            $client['current'] = $client['id'] === $currentClientId;
        }
        unset($client);
        $unassignedCurrent = (bool) array_filter(
            $unassignedClients,
            static fn (array $client): bool => (bool) ($client['current'] ?? false),
        );

        $this->view->render('devices/index', [
            'title' => 'Devices',
            'user' => $user,
            'devices' => $devices,
            'unassignedClients' => $unassignedClients,
            'currentClientId' => $currentClientId,
            'unassignedCurrent' => $unassignedCurrent,
            'csrf' => $this->csrf->token(),
        ]);
    }

    public function new(): void
    {
        $user = $this->user();
        $this->view->render('devices/new', [
            'title' => 'Add to Catch',
            'user' => $user,
            'devices' => $this->devices->physicalDevices($user['id']),
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
            Response::redirect('/clients/new');
        }

        $kind = (string) ($_POST['kind'] ?? '');
        $platform = (string) ($_POST['platform'] ?? '');
        $name = trim((string) ($_POST['name'] ?? ''));

        $clientType = match (true) {
            $kind === 'mobile' && in_array($platform, ['ios', 'ipados'], true) => 'shortcut',
            $kind === 'automation' && $platform === 'api' => 'api',
            default => null,
        };

        $deviceId = $this->resolvePhysicalDevice($user['id']);
        if ($clientType === null || $deviceId === null) {
            $_SESSION['device_error'] = 'Choose a supported setup and assign it to a device.';
            Response::redirect('/clients/new');
        }

        if ($name === '') {
            $name = $clientType === 'shortcut' ? 'iOS Shortcut' : 'API client';
        }

        $client = $this->devices->create($user['id'], $name, $kind, $platform, $clientType, physicalDeviceId: $deviceId);
        Response::redirect($this->url($client));
    }

    public function show(\Base $f3, array $params): void
    {
        $user = $this->user();
        $client = $this->devices->find($this->routeId($params), $user['id']);
        if (!$client) {
            Response::redirect('/settings/devices');
        }
        $client['current'] = $client['id'] === $this->currentClientId();

        $appUrl = rtrim((string) $this->config->get('app.url'), '/');
        $debugEnabled = $this->debug->enabled();

        $sessions = $this->devices->sessionsForClient($client['id'], $user['id']);
        foreach ($sessions as &$session) {
            $session['current'] = hash_equals(session_id(), (string) $session['id']);
            unset($session['id']);
        }
        unset($session);

        $this->view->render('clients/show', [
            'title' => $client['name'],
            'user' => $user,
            'client' => $client,
            'sessions' => $sessions,
            'captures' => $this->captures->listByClient($user['id'], $client['id']),
            'csrf' => $this->csrf->token(),
            'clientUrl' => $this->url($client),
            'shortcutUrl' => $appUrl . '/assets/shortcuts/Catch%20Setup.shortcut',
            'apiPairUrl' => $appUrl . '/api/clients/pair',
            'pairingCodeTtlMinutes' => DeviceRepository::PAIRING_CODE_TTL_MINUTES,
            'debugEnabled' => $debugEnabled,
            'debugRequests' => $debugEnabled
                ? $this->debug->forClient($user['id'], $client['id'])
                : [],
        ]);
    }

    public function edit(\Base $f3, array $params): void
    {
        $user = $this->user();
        $client = $this->devices->find($this->routeId($params), $user['id']);
        if (!$client || $client['status'] === 'revoked') {
            Response::redirect('/settings/devices');
        }

        $this->view->render('clients/edit', [
            'title' => 'Edit ' . $client['name'],
            'user' => $user,
            'client' => $client,
            'devices' => $this->devices->physicalDevices($user['id']),
            'clientUrl' => $this->url($client),
            'csrf' => $this->csrf->token(),
        ]);
    }

    public function createPairingCode(\Base $f3, array $params): never
    {
        $user = $this->user();
        $id = $this->routeId($params);
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
        $id = $this->routeId($params);
        if (!$this->csrf->valid($_POST['_csrf'] ?? null)) {
            Response::redirect('/settings/devices');
        }

        $name = trim((string) ($_POST['name'] ?? ''));
        $clientApp = (string) ($_POST['client_app'] ?? '');
        $os = (string) ($_POST['os'] ?? '');
        if (!$this->devices->rename($id, $user['id'], $name, $clientApp, $os)) {
            $_SESSION['flash_error'] = 'Enter a client name and choose valid client and operating-system values.';
        }
        $physicalDeviceId = (string) ($_POST['physical_device_id'] ?? '');
        if (!$this->devices->assignClient($id, $user['id'], $physicalDeviceId !== '' ? $physicalDeviceId : null)) {
            $_SESSION['flash_error'] = 'Choose a physical device that belongs to this account.';
        }

        $device = $this->devices->find($id, $user['id']);
        Response::redirect($device ? $this->url($device) : '/settings/devices');
    }

    public function status(\Base $f3, array $params): never
    {
        $user = $this->user();
        $status = $this->devices->status($this->routeId($params), $user['id']);
        if (!$status) {
            Response::json([
                'error' => [
                    'code' => 'not_found',
                    'message' => 'Client not found.',
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

        $id = $this->routeId($params);
        $this->devices->delete($id, $user['id']);

        $currentClientId = $_COOKIE['catch_client_id'] ?? $_SESSION['catch_web_client_id'] ?? null;
        if ($currentClientId === $id) {
            setcookie('catch_client_id', '', [
                'expires' => 1,
                'path' => '/',
                'secure' => $this->config->bool('session.secure', true),
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
            unset($_COOKIE['catch_client_id'], $_SESSION['catch_web_client_id']);
            $this->auth->logout();
            Response::redirect('/login');
        }

        Response::redirect('/settings/devices');
    }

    public function createPhysical(): never
    {
        $user = $this->user();
        if ($this->csrf->valid($_POST['_csrf'] ?? null)) {
            try {
                $this->devices->createPhysicalDevice(
                    $user['id'],
                    (string) ($_POST['name'] ?? ''),
                    (string) ($_POST['device_type'] ?? ''),
                );
                $_SESSION['flash_success'] = 'Device added.';
            } catch (\Throwable) {
                $_SESSION['flash_error'] = 'Enter a unique device name and choose a device type.';
            }
        }
        Response::redirect('/settings/devices');
    }

    private function resolvePhysicalDevice(string $userId): ?string
    {
        $selected = (string) ($_POST['physical_device_id'] ?? '');
        if ($selected !== 'new' && $this->devices->findPhysicalDevice($selected, $userId)) {
            return $selected;
        }
        if ($selected !== 'new') {
            return null;
        }
        try {
            return $this->devices->createPhysicalDevice(
                $userId,
                (string) ($_POST['new_device_name'] ?? ''),
                (string) ($_POST['new_device_type'] ?? ''),
            )['id'];
        } catch (\Throwable) {
            return null;
        }
    }

    private function routeId(array $params): string
    {
        return $this->id((string) ($params['client'] ?? $params['device'] ?? ''));
    }

    private function currentClientId(): string
    {
        return (string) ($_SESSION['catch_web_client_id'] ?? $_COOKIE['catch_client_id'] ?? '');
    }
}
