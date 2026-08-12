<?php

declare(strict_types=1);

namespace Catch\Controllers\Api;

use Catch\Http\Request;
use Catch\Http\Response;
use Catch\Repositories\CaptureRepository;
use Catch\Repositories\DeviceRepository;
use Catch\Services\CaptureDebugService;
use Catch\Services\CaptureService;
use InvalidArgumentException;

final class CaptureController
{
    public function __construct(
        private readonly DeviceRepository $devices,
        private readonly CaptureRepository $captures,
        private readonly CaptureService $service,
        private readonly CaptureDebugService $debug,
    ) {
    }

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
        $status = (string) ($_GET['status'] ?? 'inbox');

        if ($status === 'trash') {
            $captures = $this->captures->listTrash($user['id']);
        } else {
            $status = in_array($status, ['inbox', 'archived'], true) ? $status : 'inbox';
            $captures = $this->captures->list($user['id'], $status);
        }

        Response::json(['data' => $captures]);
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
        if (str_contains($contentType, 'application/json')) {
            [$input, $debugParameters] = Request::captureJson();
        } else {
            $input = $_POST;
            $debugParameters = $_POST;
        }
        $debugRequestId = $this->debug->begin(
            $user,
            $shortcut ? '/api/shortcut/captures' : '/api/v1/captures',
            $debugParameters,
            $_FILES,
        );

        try {
            $input['client_capture_id'] = $this->clientCaptureId(
                $input['client_capture_id'] ?? null,
                $_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? null,
            );
            if (isset($input['metadata']) && is_string($input['metadata'])) {
                $input['metadata'] = json_decode($input['metadata'], true) ?: [];
            }

            if ($shortcut && $this->uploadedFileCount($_FILES) > 1) {
                throw new InvalidArgumentException(json_encode(['attachment' => 'iOS Shortcut captures accept only one attachment.']));
            }
            $result = $this->service->create($user['id'], $input, $_FILES, $user['device_id']);
            $capture = $result['capture'];
            $status = $result['created'] ? 201 : 200;
            $this->debug->finish(
                $debugRequestId,
                $result['created'] ? 'accepted_created' : 'accepted_existing',
                $status,
                (string) $capture['id'],
            );
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
            $this->debug->finish(
                $debugRequestId,
                'rejected_validation',
                422,
                errorMessage: $this->validationMessage($fields),
            );
            if ($shortcut) {
                Response::shortcut($this->validationMessage($fields), '', 422);
            }
            Response::json(['error' => ['code' => 'validation_failed', 'message' => 'The request is invalid.', 'fields' => $fields]], 422);
        } catch (\Throwable $error) {
            $this->debug->finish(
                $debugRequestId,
                'rejected_server_error',
                500,
                errorMessage: $error->getMessage(),
            );
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
        if (!$this->captures->trash((string) $params['id'], $user['id'])) {
            Response::json(['error' => ['code' => 'not_found', 'message' => 'Capture not found.']], 404);
        }
        Response::json(['status' => 'trashed', 'deleted_at' => gmdate(DATE_ATOM)]);
    }

    private function validationMessage(mixed $fields): string
    {
        if (!is_array($fields)) {
            return 'The request is invalid.';
        }
        $messages = array_values(array_filter($fields, static fn (mixed $message): bool => is_string($message) && $message !== ''));
        return $messages ? implode(' ', $messages) : 'The request is invalid.';
    }

    private function clientCaptureId(mixed $bodyValue, mixed $idempotencyKey): string
    {
        foreach ([$bodyValue, $idempotencyKey] as $candidate) {
            if (!is_string($candidate)) {
                continue;
            }
            $candidate = trim($candidate);
            if ($candidate !== '' && !str_starts_with($candidate, 'catch_device_')) {
                return $candidate;
            }
        }
        return 'client_capture_' . bin2hex(random_bytes(16));
    }

    private function uploadedFileCount(array $files): int
    {
        $count = 0;
        foreach (['attachment', 'attachments'] as $name) {
            $field = $files[$name] ?? null;
            if (!is_array($field) || !array_key_exists('error', $field)) {
                continue;
            }
            $errors = is_array($field['error']) ? $field['error'] : [$field['error']];
            foreach ($errors as $error) {
                if ((int) $error !== UPLOAD_ERR_NO_FILE) {
                    $count++;
                }
            }
        }
        return $count;
    }
}
