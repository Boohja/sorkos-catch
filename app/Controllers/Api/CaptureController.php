<?php

declare(strict_types=1);

namespace Catch\Controllers\Api;

use Catch\Http\Request;
use Catch\Http\Response;
use Catch\Repositories\CaptureRepository;
use Catch\Repositories\DeviceRepository;
use Catch\Services\CaptureService;
use InvalidArgumentException;

final class CaptureController
{
    public function __construct(
        private readonly DeviceRepository $devices,
        private readonly CaptureRepository $captures,
        private readonly CaptureService $service,
    ) {}

    private function user(string $scope = 'full', bool $shortcut = false): array
    {
        $token = Request::bearerToken();
        $user = $token ? $this->devices->userForToken($token, $scope) : null;
        if (!$user) {
            if ($shortcut) {
                Response::shortcut('A token with the required permission is needed.', '', 401);
            }
            Response::json(['error' => ['code' => 'unauthorized', 'message' => 'A token with the required permission is needed.']], 401);
        }
        return $user;
    }

    public function index(): never
    {
        $user = $this->user();
        Response::json(['data' => $this->captures->list($user['id'], $_GET['status'] ?? 'inbox')]);
    }

    public function create(): never
    {
        $this->createCapture(false);
    }

    public function createShortcut(): never
    {
        $this->createCapture(true);
    }

    private function createCapture(bool $shortcut): never
    {
        $user = $this->user('capture:write', $shortcut);
        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
        $input = str_contains($contentType, 'application/json') ? Request::json() : $_POST;
        $input['client_capture_id'] = $input['client_capture_id'] ?? ($_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? null);
        if (isset($input['metadata']) && is_string($input['metadata'])) {
            $input['metadata'] = json_decode($input['metadata'], true) ?: [];
        }

        try {
            $result = $this->service->create($user['id'], $input, $_FILES);
            $capture = $result['capture'];
            $status = $result['created'] ? 201 : 200;
            if ($shortcut) {
                Response::shortcut('', (string) $capture['catch_number'], $status);
            }
            Response::json([
                'id' => $capture['id'],
                'catch_number' => (int) $capture['catch_number'],
                'status' => $result['created'] ? 'created' : 'existing',
                'created_at' => $capture['created_at'],
                'matched_rules' => 0,
            ], $status);
        } catch (InvalidArgumentException $error) {
            $fields = json_decode($error->getMessage(), true);
            if ($shortcut) {
                Response::shortcut($this->validationMessage($fields), '', 422);
            }
            Response::json(['error' => ['code' => 'validation_failed', 'message' => 'The request is invalid.', 'fields' => $fields]], 422);
        } catch (\Throwable) {
            if ($shortcut) {
                Response::shortcut('The capture could not be stored.', '', 500);
            }
            Response::json(['error' => ['code' => 'capture_failed', 'message' => 'The capture could not be stored.']], 500);
        }
    }

    public function show(\Base $f3, array $params): never
    {
        $user = $this->user();
        $capture = $this->captures->find((string) $params['id'], $user['id']);
        if (!$capture) {
            Response::json(['error' => ['code' => 'not_found', 'message' => 'Capture not found.']], 404);
        }
        Response::json(['data' => $capture]);
    }

    public function archive(\Base $f3, array $params): never
    {
        $user = $this->user();
        if (!$this->captures->setStatus((string) $params['id'], $user['id'], 'archived')) {
            Response::json(['error' => ['code' => 'not_found', 'message' => 'Capture not found.']], 404);
        }
        Response::json(['status' => 'archived']);
    }

    public function delete(\Base $f3, array $params): never
    {
        $user = $this->user();
        if (!$this->captures->setStatus((string) $params['id'], $user['id'], 'deleted')) {
            Response::json(['error' => ['code' => 'not_found', 'message' => 'Capture not found.']], 404);
        }
        Response::json(['status' => 'deleted']);
    }

    private function validationMessage(mixed $fields): string
    {
        if (!is_array($fields)) {
            return 'The request is invalid.';
        }
        $messages = array_values(array_filter($fields, static fn (mixed $message): bool => is_string($message) && $message !== ''));
        return $messages ? implode(' ', $messages) : 'The request is invalid.';
    }
}
