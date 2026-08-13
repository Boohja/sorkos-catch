<?php

declare(strict_types=1);

namespace Catch\Controllers\Api;

use Catch\Core\Config;
use Catch\Http\Request;
use Catch\Http\Response;
use Catch\Repositories\CliAuthRepository;
use Catch\Repositories\DeviceRepository;

final class CliController
{
    public function __construct(private readonly CliAuthRepository $auth, private readonly DeviceRepository $devices, private readonly Config $config)
    {
    }

    public function start(): never
    {
        $input = Request::json();
        $challenge = (string) ($input['code_challenge'] ?? '');
        $deviceName = trim((string) ($input['device_name'] ?? ''));
        $platform = strtolower(trim((string) ($input['platform'] ?? '')));
        $errors = [];
        if (!preg_match('/^[A-Za-z0-9_-]{43}$/', $challenge)) {
            $errors['code_challenge'] = 'A valid SHA-256 code challenge is required.';
        }
        if ($deviceName === '' || mb_strlen($deviceName) > 120) {
            $errors['device_name'] = 'A device name of at most 120 characters is required.';
        }
        if (!in_array($platform, ['windows', 'linux'], true)) {
            $errors['platform'] = 'Platform must be windows or linux.';
        }
        if ($errors) {
            Response::json(['error' => ['code' => 'validation_failed', 'message' => 'The request is invalid.', 'fields' => $errors]], 422);
        }
        $request = $this->auth->start($deviceName, $platform, $challenge);
        $request['authorization_url'] = rtrim((string) $this->config->get('app.url'), '/') . '/cli/authorize?' . http_build_query(['login' => $request['login_id']], arg_separator: '&', encoding_type: PHP_QUERY_RFC3986);
        $request['interval'] = 2;
        Response::json($request, 201);
    }

    public function status(\Base $f3, array $params): never
    {
        $result = $this->auth->exchange((string) $params['login'], (string) (Request::json()['code_verifier'] ?? ''));
        if ($result['status'] === 'pending') {
            Response::json(['status' => 'pending'], 202);
        }
        if ($result['status'] === 'expired') {
            Response::json(['error' => ['code' => 'authorization_expired', 'message' => 'The authorization request expired.']], 410);
        }
        if ($result['status'] === 'invalid_verifier') {
            Response::json(['error' => ['code' => 'invalid_verifier', 'message' => 'The authorization proof is invalid.']], 401);
        }
        if ($result['status'] !== 'connected') {
            Response::json(['error' => ['code' => 'authorization_not_found', 'message' => 'The authorization request was not found.']], 404);
        }
        Response::json(['status' => 'connected', 'token' => $result['device_token'], 'token_type' => 'Bearer', 'scope' => 'capture:read', 'device' => ['id' => $result['device_id'], 'name' => $result['device_name']]]);
    }

    public function whoami(): never
    {
        $token = Request::bearerToken();
        $user = $token ? $this->devices->userForToken($token, 'capture:read') : null;
        if (!$user || $user['client_type'] !== 'cli') {
            Response::json(['error' => ['code' => 'unauthorized', 'message' => 'A valid CLI token is required.']], 401);
        }
        Response::json(['data' => ['id' => $user['id'], 'email' => $user['email'], 'display_name' => $user['display_name'], 'device' => ['id' => $user['device_id'], 'name' => $user['device_name'], 'platform' => $user['platform']]]]);
    }

    public function logout(): never
    {
        $token = Request::bearerToken();
        if (!$token || !$this->devices->revokeForToken($token, 'cli')) {
            Response::json(['error' => ['code' => 'unauthorized', 'message' => 'A valid CLI token is required.']], 401);
        }
        Response::json(['status' => 'logged_out']);
    }
}
