<?php

declare(strict_types=1);

namespace Catch\Controllers\Web;

use Catch\Core\Id;
use Catch\Core\View;
use Catch\Http\Response;
use Catch\Repositories\CaptureRepository;
use Catch\Repositories\ListRepository;
use Catch\Repositories\TagRepository;
use Catch\Services\AuthService;
use Catch\Services\CaptureService;
use Catch\Services\Csrf;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

final class CaptureController
{
    public function __construct(
        private readonly View $view,
        private readonly AuthService $auth,
        private readonly CaptureRepository $captures,
        private readonly TagRepository $tags,
        private readonly ListRepository $lists,
        private readonly CaptureService $service,
        private readonly Csrf $csrf,
        private readonly string $uploadsPath,
        private readonly ?string $webDeviceId = null,
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

    public function index(): void
    {
        $this->renderIndex('inbox');
    }

    public function archiveIndex(): void
    {
        $this->renderIndex('archived');
    }

    public function trashIndex(): void
    {
        $this->renderIndex('trash');
    }

    private function renderIndex(string $status): void
    {
        $user = $this->user();
        $this->purgeExpiredTrash($user['id']);

        $captures = $status === 'trash'
            ? $this->captures->listTrash($user['id'])
            : $this->captures->list($user['id'], $status);

        $title = match ($status) {
            'trash' => 'Trash',
            'archived' => 'Archived',
            default => 'Inbox',
        };

        $this->view->render('captures/index', [
            'title' => $title,
            'user' => $user,
            'captures' => $captures,
            'status' => $status,
            'csrf' => $this->csrf->token(),
        ]);
    }

    public function create(): void
    {
        $user = $this->user();
        if (!$this->csrf->valid($_POST['_csrf'] ?? null)) {
            Response::redirect('/inbox?error=csrf');
        }

        $_POST['client_capture_id'] = $_POST['client_capture_id'] ?? Id::uuid();
        $_POST['source'] = 'web';

        if (($_POST['type'] ?? '') === 'url') {
            $_POST['url'] = $_POST['text'] ?? null;
            $_POST['text'] = null;
        }

        try {
            $result = $this->service->create($user['id'], $_POST, $_FILES, $this->webDeviceId);
            Response::redirect('/captures/' . $result['capture']['id']);
        } catch (Throwable $error) {
            $_SESSION['flash_error'] = $error instanceof InvalidArgumentException
                ? $this->validationMessage($error)
                : 'The capture could not be saved.';
            Response::redirect('/inbox');
        }
    }

    public function show(\Base $f3, array $params): void
    {
        $user = $this->user();
        $capture = $this->captures->find((string) $params['id'], $user['id']);

        if (!$capture) {
            $this->view->render('errors/404', [
                'title' => 'Not found',
                'user' => $user,
            ], 404);
            return;
        }

        foreach ($capture['attachments'] as &$attachment) {
            $stored = $this->captures->findAttachment($attachment['id'], $user['id']);
            $attachment['available'] = $stored
                && is_file($this->uploadsPath . '/' . $stored['storage_name']);
        }
        unset($attachment);

        $this->view->render('captures/show', [
            'title' => $capture['title'] ?: 'Capture',
            'user' => $user,
            'capture' => $capture,
            'availableTags' => $this->tags->list($user['id']),
            'availableLists' => $this->lists->list($user['id']),
            'csrf' => $this->csrf->token(),
        ]);
    }

    public function attachment(\Base $f3, array $params): never
    {
        $user = $this->user();
        $attachment = $this->captures->findAttachment((string) $params['id'], $user['id']);

        if (!$attachment) {
            http_response_code(404);
            exit;
        }

        $base = realpath($this->uploadsPath);
        $path = realpath($this->uploadsPath . '/' . $attachment['storage_name']);

        if (
            $base === false
            || $path === false
            || !str_starts_with($path, $base . DIRECTORY_SEPARATOR)
            || !is_file($path)
        ) {
            http_response_code(404);
            exit;
        }

        header('Content-Type: ' . $attachment['mime_type']);
        header('Content-Length: ' . filesize($path));
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, max-age=3600');

        $mime = (string) $attachment['mime_type'];
        $disposition = str_starts_with($mime, 'image/') || str_starts_with($mime, 'audio/')
            ? 'inline'
            : 'attachment';
        $filename = addcslashes((string) $attachment['original_name'], '"\\');

        header('Content-Disposition: ' . $disposition . '; filename="' . $filename . '"');
        readfile($path);
        exit;
    }

    public function update(\Base $f3, array $params): never
    {
        $user = $this->user();
        if (!$this->csrf->valid($_POST['_csrf'] ?? null)) {
            Response::json(['error' => 'Your session expired. Refresh and try again.'], 419);
        }

        try {
            $result = $this->captures->updateEditableField(
                (string) $params['id'],
                $user['id'],
                (string) ($_POST['field'] ?? ''),
                (string) ($_POST['value'] ?? ''),
            );

            if (!$result) {
                Response::json(['error' => 'Capture not found.'], 404);
            }

            Response::json($result);
        } catch (InvalidArgumentException $error) {
            Response::json(['error' => $error->getMessage()], 422);
        }
    }

    public function archive(\Base $f3, array $params): never
    {
        $user = $this->user();
        if ($this->csrf->valid($_POST['_csrf'] ?? null)) {
            $this->captures->setStatus((string) $params['id'], $user['id'], 'archived');
        }

        Response::redirect('/inbox');
    }

    public function restore(\Base $f3, array $params): never
    {
        $user = $this->user();
        if ($this->csrf->valid($_POST['_csrf'] ?? null)) {
            $this->captures->restore((string) $params['id'], $user['id']);
        }

        Response::redirect('/trash');
    }

    public function delete(\Base $f3, array $params): never
    {
        $user = $this->user();
        if (!$this->csrf->valid($_POST['_csrf'] ?? null)) {
            Response::redirect('/inbox');
        }

        $id = (string) $params['id'];
        $capture = $this->captures->find($id, $user['id']);
        if (!$capture) {
            Response::redirect('/inbox');
        }

        if (empty($capture['deleted_at'])) {
            $this->captures->trash($id, $user['id']);
            $_SESSION['flash_success'] = 'Capture moved to Trash. You can restore it for 30 days.';
            Response::redirect('/inbox');
        }

        $this->purgeCaptures($user['id'], [$id]);
        Response::redirect('/trash');
    }

    public function bulkDelete(): never
    {
        $user = $this->user();
        $requested = (string) ($_POST['view'] ?? 'inbox');
        $status = in_array($requested, ['inbox', 'archived', 'trash'], true)
            ? $requested
            : 'inbox';
        $redirect = match ($status) {
            'archived' => '/archive',
            'trash' => '/trash',
            default => '/inbox',
        };

        if (!$this->csrf->valid($_POST['_csrf'] ?? null)) {
            $_SESSION['flash_error'] = 'Your session expired. Select the captures and try again.';
            Response::redirect($redirect);
        }

        $ids = is_array($_POST['capture_ids'] ?? null) ? $_POST['capture_ids'] : [];
        $ids = array_slice(
            array_values(array_unique(array_filter(
                $ids,
                static fn (mixed $id): bool => is_string($id)
                    && preg_match('/^[0-9a-f-]{36}$/i', $id) === 1,
            ))),
            0,
            200,
        );

        if (!$ids) {
            $_SESSION['flash_error'] = 'Select at least one capture to delete.';
            Response::redirect($redirect);
        }

        try {
            if ($status !== 'trash') {
                $trashed = $this->captures->trashMany($user['id'], $ids);
                $noun = $trashed === 1 ? 'capture was' : 'captures were';
                $_SESSION['flash_success'] = $trashed . ' ' . $noun . ' moved to Trash.';
            } else {
                $deleted = $this->purgeCaptures($user['id'], $ids);
                if ($deleted) {
                    $noun = $deleted === 1 ? 'capture' : 'captures';
                    $_SESSION['flash_success'] = $deleted . ' ' . $noun . ' permanently deleted.';
                } else {
                    $_SESSION['flash_error'] = 'The selected captures could not be permanently deleted.';
                }
            }
        } catch (Throwable) {
            $_SESSION['flash_error'] = $status === 'trash'
                ? 'The selected captures could not be permanently deleted. Nothing was changed.'
                : 'The selected captures could not be moved to Trash.';
        }

        Response::redirect($redirect);
    }

    private function purgeExpiredTrash(string $userId): void
    {
        $ids = $this->captures->expiredTrashIds($userId, 30);
        if (!$ids) {
            return;
        }

        try {
            $this->purgeCaptures($userId, $ids);
        } catch (Throwable) {
            // Cleanup is best-effort during a regular page request.
        }
    }

    private function purgeCaptures(string $userId, array $ids): int
    {
        $storageNames = $this->captures->attachmentStorageNames($userId, $ids);
        if (!$this->attachmentFilesDeletable($storageNames)) {
            throw new RuntimeException('Attachment cleanup is unavailable.');
        }

        $result = $this->captures->purgeMany($userId, $ids);
        $failed = $this->removeAttachmentFiles($result['storage_names']);
        if ($failed) {
            throw new RuntimeException('Attachment cleanup was incomplete.');
        }

        return (int) $result['deleted'];
    }

    private function attachmentFilesDeletable(array $storageNames): bool
    {
        $base = realpath($this->uploadsPath);
        if ($base === false) {
            return $storageNames === [];
        }

        foreach ($storageNames as $storageName) {
            $path = realpath($this->uploadsPath . '/' . (string) $storageName);
            if ($path === false) {
                continue;
            }

            if (
                !str_starts_with($path, $base . DIRECTORY_SEPARATOR)
                || !is_file($path)
                || !is_writable($path)
            ) {
                return false;
            }
        }

        return true;
    }

    private function removeAttachmentFiles(array $storageNames): int
    {
        $base = realpath($this->uploadsPath);
        if ($base === false) {
            return count($storageNames);
        }

        $failed = 0;
        foreach ($storageNames as $storageName) {
            $path = realpath($this->uploadsPath . '/' . (string) $storageName);
            if ($path === false) {
                continue;
            }

            if (
                !str_starts_with($path, $base . DIRECTORY_SEPARATOR)
                || !is_file($path)
                || !unlink($path)
            ) {
                $failed++;
                continue;
            }

            $directory = dirname($path);
            if ($directory !== $base) {
                @rmdir($directory);
            }
        }

        return $failed;
    }

    private function validationMessage(InvalidArgumentException $error): string
    {
        $fallback = 'Add content, a URL, or a supported file.';
        $fields = json_decode($error->getMessage(), true);
        if (!is_array($fields)) {
            return $fallback;
        }

        $messages = array_values(array_filter(
            $fields,
            static fn (mixed $message): bool => is_string($message) && $message !== '',
        ));

        return $messages ? implode(' ', $messages) : $fallback;
    }
}
