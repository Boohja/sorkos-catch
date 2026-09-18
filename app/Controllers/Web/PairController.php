<?php

declare(strict_types=1);

namespace Catch\Controllers\Web;

use Catch\Core\View;
use Catch\Http\Response;
use Catch\Repositories\DeviceRepository;
use Catch\Services\AuthService;
use Catch\Services\Csrf;

final class PairController
{
    public function __construct(private readonly View $view, private readonly AuthService $auth, private readonly DeviceRepository $devices, private readonly Csrf $csrf)
    {
    }

    public function show(): void
    {
        $request = (string)($_GET['request'] ?? '');
        $user = $this->auth->user();
        if (!$user) {
            $_SESSION['after_login_path'] = $this->returnPath($request);
            Response::redirect('/auth/start');
        }
        $pairing = $this->devices->extensionPairingRequest($request);
        $webClient = !empty($_SESSION['catch_web_client_id'])
            ? $this->devices->find((string) $_SESSION['catch_web_client_id'], $user['id'])
            : null;
        $this->view->render('pair/index', [
            'title' => 'Connect browser',
            'user' => $user,
            'pairing' => $pairing,
            'request' => $request,
            'devices' => $this->devices->physicalDevices($user['id']),
            'suggestedDeviceId' => (string) ($webClient['device_id'] ?? ''),
            'pairingError' => $_SESSION['pairing_error'] ?? null,
            'csrf' => $this->csrf->token(),
        ], $pairing ? 200 : 404);
        unset($_SESSION['pairing_error']);
    }

    public function approve(): void
    {
        $request = (string)($_POST['request'] ?? '');
        $user = $this->auth->user();
        if (!$user) {
            $_SESSION['after_login_path'] = $this->returnPath($request);
            Response::redirect('/auth/start');
        }
        if (!$this->csrf->valid($_POST['_csrf'] ?? null)) {
            Response::redirect($this->returnPath($request));
        }
        $deviceId = $this->resolveDevice($user['id']);
        if ($deviceId === null) {
            Response::redirect($this->returnPath($request));
        }
        try {
            $connected = $this->devices->approveExtensionPairingRequest($request, $user['id'], $deviceId, (string)($_SERVER['HTTP_USER_AGENT'] ?? ''));
        } catch (\Throwable) {
            $connected = null;
        }
        $this->view->render('pair/index', ['title' => $connected ? 'Browser connected' : 'Connection failed','user' => $user,'pairing' => null,'request' => $request,'connected' => $connected,'csrf' => $this->csrf->token()], $connected ? 200 : 410);
    }

    private function returnPath(string $request): string
    {
        return '/pair?' . http_build_query(['request' => preg_match('/^[0-9a-f]{48}$/', $request) ? $request : ''], arg_separator:'&', encoding_type:PHP_QUERY_RFC3986);
    }

    private function resolveDevice(string $userId): ?string
    {
        $selected = (string) ($_POST['device_id'] ?? '');
        if ($selected !== 'new' && $this->devices->findPhysicalDevice($selected, $userId)) {
            return $selected;
        }
        if ($selected === 'new') {
            try {
                return $this->devices->createPhysicalDevice(
                    $userId,
                    (string) ($_POST['new_device_name'] ?? ''),
                    (string) ($_POST['new_device_type'] ?? ''),
                )['id'];
            } catch (\Throwable) {
                // The message below intentionally avoids leaking database details.
            }
        }
        $_SESSION['pairing_error'] = 'Choose an existing device or enter a name for a new one.';

        return null;
    }
}
