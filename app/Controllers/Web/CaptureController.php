<?php

declare(strict_types=1);

namespace Catch\Controllers\Web;

use Catch\Core\Id;
use Catch\Core\View;
use Catch\Http\ByteRange;
use Catch\Http\Request;
use Catch\Http\Response;
use Catch\Repositories\CaptureRepository;
use Catch\Repositories\TagRepository;
use Catch\Services\AuthService;
use Catch\Services\CaptureDebugService;
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
        private readonly CaptureService $service,
        private readonly CaptureDebugService $debug,
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

    public function shareTarget(): void
    {
        $user = $this->user();
        $captureUrl = '';
        $shareError = '';

        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            $fetchSite = strtolower((string) ($_SERVER['HTTP_SEC_FETCH_SITE'] ?? ''));
            if (!in_array($fetchSite, ['', 'none', 'same-origin'], true)) {
                $shareError = 'This share request did not come from your device share sheet.';
            } else {
                $_POST['client_capture_id'] = 'web_share_' . Id::uuid();
                $_POST['type'] = 'unknown';
                $_POST['source'] = 'web-share-target';
                if (
                    trim((string) ($_POST['url'] ?? '')) !== ''
                    && trim((string) ($_POST['text'] ?? '')) === trim((string) $_POST['url'])
                ) {
                    $_POST['text'] = null;
                }
                if (isset($_FILES['files'])) {
                    $_FILES['attachments'] = $_FILES['files'];
                }

                try {
                    $result = $this->service->create(
                        $user['id'],
                        $_POST,
                        $_FILES,
                        $this->webDeviceId,
                    );
                    $captureUrl = '/captures/' . $result['capture']['id'];
                } catch (Throwable $error) {
                    $shareError = $error instanceof InvalidArgumentException
                        ? $this->validationMessage($error)
                        : 'The capture could not be saved. The shared item may be too large.';
                }
            }
        }

        $this->view->render('share/index', [
            'title' => $shareError ? 'Capture needs attention' : 'Processing capture',
            'user' => $user,
            'csrf' => $this->csrf->token(),
            'isShareTarget' => true,
            'captureUrl' => $captureUrl,
            'shareError' => $shareError,
            'debugEnabled' => $this->debug->enabled(),
        ], $shareError ? 422 : 200);
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
            'enableCaptureActionMenu' => $status !== 'trash',
            'enableLaterDialog' => $status === 'inbox',
            'enableMoveDialog' => $status !== 'trash',
            'capturePoll' => $status === 'inbox',
            'csrf' => $this->csrf->token(),
        ]);
    }

    public function poll(): void
    {
        $user = $this->user();
        $after = filter_input(INPUT_GET, 'after', FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 0],
        ]);
        $after = $after === false || $after === null ? 0 : $after;
        $captures = $this->captures->listNewerInboxCaptures($user['id'], $after);
        $html = [];
        foreach ($captures as $capture) {
            $html[] = $this->view->partial('captures/_item', [
                'capture' => $capture,
                'captureCollectionVariant' => 'switchable',
                'captureShowActions' => true,
                'bulkFormId' => 'capture-bulk-form',
                'csrf' => $this->csrf->token(),
            ]);
        }
        $cursor = $captures
            ? max(array_map(static fn (array $capture): int => (int) $capture['catch_number'], $captures))
            : $after;

        Response::json(['html' => $html, 'cursor' => $cursor]);
    }

    public function create(): void
    {
        $user = $this->user();
        if (!$this->csrf->valid($_POST['_csrf'] ?? null)) {
            if (Request::wantsJson()) {
                Response::json(['error' => 'Your session expired. Refresh and try again.'], 419);
            }
            Response::redirect('/inbox?error=csrf');
        }

        $_POST['client_capture_id'] = $_POST['client_capture_id'] ?? Id::uuid();
        $_POST['source'] = ($_POST['source'] ?? '') === 'web-share-target'
            ? 'web-share-target'
            : 'web';

        if (($_POST['type'] ?? '') === 'url') {
            $_POST['url'] = $_POST['text'] ?? null;
            $_POST['text'] = null;
        }

        try {
            $result = $this->service->create($user['id'], $_POST, $_FILES, $this->webDeviceId);
            if (Request::wantsJson()) {
                $capture = $this->captures->findCollectionItem($result['capture']['id'], $user['id']);
                Response::json([
                    'capture' => $result['capture'],
                    'created' => $result['created'],
                    'url' => '/captures/' . $result['capture']['id'],
                    'html' => $result['created'] && $capture
                        ? $this->view->partial('captures/_item', [
                            'capture' => $capture,
                            'captureCollectionVariant' => 'switchable',
                            'captureShowActions' => true,
                            'bulkFormId' => 'capture-bulk-form',
                            'csrf' => $this->csrf->token(),
                        ])
                        : null,
                ], $result['created'] ? 201 : 200);
            }
            Response::redirect('/inbox');
        } catch (Throwable $error) {
            $message = $error instanceof InvalidArgumentException
                ? $this->validationMessage($error)
                : 'The capture could not be saved.';
            if (Request::wantsJson()) {
                Response::json([
                    'error' => $message,
                ], $error instanceof InvalidArgumentException ? 422 : 500);
            }
            $_SESSION['flash_error'] = $message;
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
            'enableTagDialog' => empty($capture['deleted_at']),
            'enableLaterDialog' => empty($capture['deleted_at']) && $capture['status'] === 'inbox',
            'enableMoveDialog' => empty($capture['deleted_at']),
            'debugEnabled' => $this->debug->enabled(),
            'debugRequests' => $this->debug->forCapture($user['id'], $capture['id']),
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

        $size = filesize($path);
        if ($size === false) {
            http_response_code(500);
            exit;
        }

        $storedMime = (string) $attachment['mime_type'];
        $mime = in_array($storedMime, ['audio/m4a', 'audio/x-m4a'], true)
            ? 'audio/mp4'
            : $storedMime;

        header('Content-Type: ' . $mime);
        header('Accept-Ranges: bytes');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, max-age=3600');

        $disposition = str_starts_with($mime, 'image/') || str_starts_with($mime, 'audio/')
            ? 'inline'
            : 'attachment';
        $filename = addcslashes((string) $attachment['original_name'], '"\\');

        header('Content-Disposition: ' . $disposition . '; filename="' . $filename . '"');

        try {
            $range = ByteRange::parse($_SERVER['HTTP_RANGE'] ?? null, $size);
        } catch (InvalidArgumentException) {
            http_response_code(416);
            header('Content-Range: bytes */' . $size);
            header('Content-Length: 0');
            exit;
        }

        $start = $range['start'] ?? 0;
        $end = $range['end'] ?? ($size - 1);
        $length = $range['length'] ?? $size;
        if ($range !== null) {
            http_response_code(206);
            header(sprintf('Content-Range: bytes %d-%d/%d', $start, $end, $size));
        }
        header('Content-Length: ' . $length);

        $handle = fopen($path, 'rb');
        if ($handle === false || fseek($handle, $start) !== 0) {
            if (is_resource($handle)) {
                fclose($handle);
            }
            http_response_code(500);
            exit;
        }

        $remaining = $length;
        while ($remaining > 0 && !feof($handle)) {
            $chunk = fread($handle, min(8192, $remaining));
            if ($chunk === false || $chunk === '') {
                break;
            }
            echo $chunk;
            $remaining -= strlen($chunk);
        }
        fclose($handle);
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

            if (($result['field'] ?? null) === 'url') {
                try {
                    $this->service->refreshLinkPreview(
                        (string) $params['id'],
                        $user['id'],
                        true,
                        true,
                    );
                } catch (Throwable) {
                    // The URL edit remains valid when optional preview generation fails.
                }
                $result['reload'] = true;
            }

            Response::json($result);
        } catch (InvalidArgumentException $error) {
            Response::json(['error' => $error->getMessage()], 422);
        }
    }

    public function preview(\Base $f3, array $params): never
    {
        $user = $this->user();
        if (!$this->csrf->valid($_POST['_csrf'] ?? null)) {
            Response::json(['error' => 'Your session expired. Refresh and try again.'], 419);
        }

        $capture = $this->captures->find((string) $params['id'], $user['id']);
        if (!$capture) {
            Response::json(['error' => 'Capture not found.'], 404);
        }

        try {
            $updated = $this->service->refreshLinkPreview(
                (string) $params['id'],
                $user['id'],
            );
            $collectionCapture = $updated
                ? $this->captures->findCollectionItem((string) $params['id'], $user['id'])
                : null;
            Response::json([
                'updated' => $updated,
                'html' => $collectionCapture
                    ? $this->view->partial('captures/_item', [
                        'capture' => $collectionCapture,
                        'captureCollectionVariant' => 'switchable',
                        'captureShowActions' => true,
                        'bulkFormId' => 'capture-bulk-form',
                        'csrf' => $this->csrf->token(),
                    ])
                    : null,
            ]);
        } catch (Throwable) {
            Response::json(['updated' => false]);
        }
    }

    public function archive(\Base $f3, array $params): never
    {
        $user = $this->user();
        if (!$this->csrf->valid($_POST['_csrf'] ?? null)) {
            if (Request::wantsJson()) {
                Response::json(['error' => 'Your session expired. Refresh and try again.'], 419);
            }
            Response::redirect('/inbox');
        }

        $updated = $this->captures->setStatus((string) $params['id'], $user['id'], 'archived');
        if (Request::wantsJson()) {
            Response::json(['updated' => $updated, 'capture_status' => 'archived']);
        }

        Response::redirect('/inbox');
    }

    public function later(\Base $f3, array $params): never
    {
        $user = $this->user();
        if (!$this->csrf->valid($_POST['_csrf'] ?? null)) {
            if (Request::wantsJson()) {
                Response::json(['error' => 'Your session expired. Refresh and try again.'], 419);
            }
            Response::redirect('/inbox');
        }

        try {
            $until = $this->laterUntil();
            $updated = $this->captures->later((string) $params['id'], $user['id'], $until);
            if (!$updated) {
                throw new InvalidArgumentException('Only captures in the inbox can be moved to Later.');
            }
        } catch (InvalidArgumentException $error) {
            if (Request::wantsJson()) {
                Response::json(['error' => $error->getMessage()], 422);
            }
            $_SESSION['flash_error'] = $error->getMessage();
            Response::redirect('/inbox');
        }

        if (Request::wantsJson()) {
            Response::json([
                'updated' => true,
                'capture_status' => 'later',
                'later_until' => $until,
            ]);
        }

        $_SESSION['flash_success'] = 'Capture moved to Later.';
        Response::redirect('/inbox');
    }

    public function restore(\Base $f3, array $params): never
    {
        $user = $this->user();
        if (!$this->csrf->valid($_POST['_csrf'] ?? null)) {
            if (Request::wantsJson()) {
                Response::json(['error' => 'Your session expired. Refresh and try again.'], 419);
            }
            Response::redirect('/trash');
        }

        $restored = $this->captures->restore((string) $params['id'], $user['id']);
        if (Request::wantsJson()) {
            Response::json(['updated' => $restored, 'capture_status' => 'inbox']);
        }

        Response::redirect('/trash');
    }

    public function delete(\Base $f3, array $params): never
    {
        $user = $this->user();
        if (!$this->csrf->valid($_POST['_csrf'] ?? null)) {
            if (Request::wantsJson()) {
                Response::json(['error' => 'Your session expired. Refresh and try again.'], 419);
            }
            Response::redirect('/inbox');
        }

        $id = (string) $params['id'];
        $capture = $this->captures->find($id, $user['id']);
        if (!$capture) {
            if (Request::wantsJson()) {
                Response::json(['error' => 'Capture not found.'], 404);
            }
            Response::redirect('/inbox');
        }

        if (empty($capture['deleted_at'])) {
            $this->captures->trash($id, $user['id']);
            if (Request::wantsJson()) {
                Response::json(['deleted' => 1, 'capture_status' => 'trash']);
            }
            $_SESSION['flash_success'] = 'Capture moved to Trash. You can restore it for 30 days.';
            Response::redirect('/inbox');
        }

        $deleted = $this->purgeCaptures($user['id'], [$id]);
        if (Request::wantsJson()) {
            Response::json(['deleted' => $deleted, 'capture_status' => 'deleted']);
        }
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
            if (Request::wantsJson()) {
                Response::json(['error' => 'Your session expired. Select the captures and try again.'], 419);
            }
            $_SESSION['flash_error'] = 'Your session expired. Select the captures and try again.';
            Response::redirect($redirect);
        }

        $ids = $this->selectedCaptureIds();

        if (!$ids) {
            if (Request::wantsJson()) {
                Response::json(['error' => 'Select at least one capture to delete.'], 422);
            }
            $_SESSION['flash_error'] = 'Select at least one capture to delete.';
            Response::redirect($redirect);
        }

        try {
            $changed = 0;
            if ($status !== 'trash') {
                $trashed = $this->captures->trashMany($user['id'], $ids);
                $changed = $trashed;
                $noun = $trashed === 1 ? 'capture was' : 'captures were';
                $_SESSION['flash_success'] = $trashed . ' ' . $noun . ' moved to Trash.';
            } else {
                $deleted = $this->purgeCaptures($user['id'], $ids);
                $changed = $deleted;
                if ($deleted) {
                    $noun = $deleted === 1 ? 'capture' : 'captures';
                    $_SESSION['flash_success'] = $deleted . ' ' . $noun . ' permanently deleted.';
                } else {
                    $_SESSION['flash_error'] = 'The selected captures could not be permanently deleted.';
                }
            }
        } catch (Throwable) {
            if (Request::wantsJson()) {
                Response::json([
                    'error' => $status === 'trash'
                        ? 'The selected captures could not be permanently deleted. Nothing was changed.'
                        : 'The selected captures could not be moved to Trash.',
                ], 500);
            }
            $_SESSION['flash_error'] = $status === 'trash'
                ? 'The selected captures could not be permanently deleted. Nothing was changed.'
                : 'The selected captures could not be moved to Trash.';
        }

        if (Request::wantsJson()) {
            Response::json([
                'changed' => $changed,
                'capture_ids' => $ids,
                'capture_status' => $status === 'trash' ? 'deleted' : 'trash',
            ]);
        }

        Response::redirect($redirect);
    }

    public function bulkArchive(): never
    {
        $user = $this->user();
        $redirect = (string) ($_POST['view'] ?? '') === 'archived' ? '/archive' : '/inbox';

        if (!$this->csrf->valid($_POST['_csrf'] ?? null)) {
            if (Request::wantsJson()) {
                Response::json(['error' => 'Your session expired. Select the captures and try again.'], 419);
            }
            $_SESSION['flash_error'] = 'Your session expired. Select the captures and try again.';
            Response::redirect($redirect);
        }

        $ids = $this->selectedCaptureIds();
        if (!$ids) {
            if (Request::wantsJson()) {
                Response::json(['error' => 'Select at least one capture to archive.'], 422);
            }
            $_SESSION['flash_error'] = 'Select at least one capture to archive.';
            Response::redirect($redirect);
        }

        $archived = $this->captures->archiveMany($user['id'], $ids);
        if (Request::wantsJson()) {
            Response::json([
                'changed' => $archived,
                'capture_ids' => $ids,
                'capture_status' => 'archived',
            ]);
        }
        $noun = $archived === 1 ? 'capture was' : 'captures were';
        $_SESSION['flash_success'] = $archived . ' ' . $noun . ' archived.';
        Response::redirect($redirect);
    }

    public function bulkLater(): never
    {
        $user = $this->user();
        if (!$this->csrf->valid($_POST['_csrf'] ?? null)) {
            if (Request::wantsJson()) {
                Response::json(['error' => 'Your session expired. Select the captures and try again.'], 419);
            }
            $_SESSION['flash_error'] = 'Your session expired. Select the captures and try again.';
            Response::redirect('/inbox');
        }

        $ids = $this->selectedCaptureIds();
        if (!$ids) {
            if (Request::wantsJson()) {
                Response::json(['error' => 'Select at least one capture to move to Later.'], 422);
            }
            $_SESSION['flash_error'] = 'Select at least one capture to move to Later.';
            Response::redirect('/inbox');
        }

        try {
            $until = $this->laterUntil();
            $changed = $this->captures->laterMany($user['id'], $ids, $until);
        } catch (InvalidArgumentException $error) {
            if (Request::wantsJson()) {
                Response::json(['error' => $error->getMessage()], 422);
            }
            $_SESSION['flash_error'] = $error->getMessage();
            Response::redirect('/inbox');
        }

        if (Request::wantsJson()) {
            Response::json([
                'changed' => $changed,
                'capture_ids' => $ids,
                'capture_status' => 'later',
                'later_until' => $until,
            ]);
        }

        $noun = $changed === 1 ? 'capture was' : 'captures were';
        $_SESSION['flash_success'] = $changed . ' ' . $noun . ' moved to Later.';
        Response::redirect('/inbox');
    }

    private function laterUntil(): string
    {
        $choice = (string) ($_POST['later_choice'] ?? '');
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $until = match ($choice) {
            '1_hour' => $now->modify('+1 hour'),
            '12_hours' => $now->modify('+12 hours'),
            '1_day' => $now->modify('+1 day'),
            '1_week' => $now->modify('+1 week'),
            'date' => $this->customLaterUntil(),
            default => throw new InvalidArgumentException('Choose when this capture should return to the inbox.'),
        };

        if ($until <= $now) {
            throw new InvalidArgumentException('Choose a date in the future.');
        }

        return $until->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }

    private function customLaterUntil(): \DateTimeImmutable
    {
        $date = (string) ($_POST['later_date'] ?? '');
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
            throw new InvalidArgumentException('Choose a date for this capture to return.');
        }

        $utcValue = trim((string) ($_POST['later_until_utc'] ?? ''));
        if ($utcValue !== '') {
            try {
                return new \DateTimeImmutable($utcValue);
            } catch (\Exception) {
                throw new InvalidArgumentException('The selected date could not be read. Choose it again.');
            }
        }

        $until = \DateTimeImmutable::createFromFormat(
            '!Y-m-d H:i',
            $date . ' 01:00',
            new \DateTimeZone('UTC'),
        );
        if (!$until) {
            throw new InvalidArgumentException('The selected date could not be read. Choose it again.');
        }

        return $until;
    }

    private function selectedCaptureIds(): array
    {
        $ids = is_array($_POST['capture_ids'] ?? null) ? $_POST['capture_ids'] : [];

        return array_slice(
            array_values(array_unique(array_filter(
                $ids,
                static fn (mixed $id): bool => is_string($id)
                    && preg_match('/^[0-9a-f-]{36}$/i', $id) === 1,
            ))),
            0,
            200,
        );
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
