<?php

declare(strict_types=1);

namespace Catch\Services;

use Catch\Core\Database;
use Catch\Core\Id;
use Catch\Repositories\CaptureRepository;
use Catch\Validation\CaptureValidator;
use InvalidArgumentException;

final class CaptureService
{
    public function __construct(private readonly Database $database, private readonly CaptureValidator $validator, private readonly UploadService $uploads, private readonly ?RemoteContentService $remote = null)
    {
    }
    public function create(string $userId, array $input, array $files = [], ?string $deviceId = null): array
    {
        if (($input['type'] ?? '') === 'unknown') {
            $input = $this->normalizeUnknownInput($input, $files);
        }
        $errors = $this->validator->validate($input);
        if ($errors) {
            throw new InvalidArgumentException(json_encode($errors));
        }
        $metadata = is_array($input['metadata'] ?? null) ? $input['metadata'] : [];
        $source = (string)($input['source'] ?? 'web');
        $url = trim((string)($input['url'] ?? ''));
        $repo = new CaptureRepository($this->database->connection());
        if ($existing = $repo->findByClientId((string) $input['client_capture_id'], $userId)) {
            return ['capture' => $existing, 'created' => false];
        }

        if ($url !== '' && $this->isHttpUrl($url) && $this->remote) {
            $metadata['link_preview_fetch'] = [
                'status' => 'pending',
                'attempts' => 0,
            ];
        }
        if (empty(trim((string)($input['title'] ?? ''))) && $url !== '') {
            $input['title'] = $this->nullable($metadata['link_text'] ?? null);
        }
        if ($url !== '' && empty($metadata['source_url'])) {
            $metadata['source_url'] = $url;
        }
        if (!empty($metadata['source_url']) && empty($metadata['source_domain'])) {
            $metadata['source_domain'] = (string)(parse_url((string)$metadata['source_url'], PHP_URL_HOST) ?? '');
        }
        if (!empty($metadata['source_url']) && $metadata['source_url'] === $url && empty($metadata['source_title']) && !empty($input['title'])) {
            $metadata['source_title'] = (string)$input['title'];
        }
        if (empty($metadata['capture_method'])) {
            $metadata['capture_method'] = $source === 'browser-extension' ? (str_contains((string)($metadata['browser_context'] ?? ''), 'context-menu') ? 'browser-extension-context-menu' : 'browser-extension') : $source;
        }
        $input['metadata'] = $metadata;
        $remoteImage = !empty($input['remote_attachment_url']) && $this->remote ? $this->remote->image((string)$input['remote_attachment_url']) : null;
        if (!empty($input['remote_attachment_url']) && !$remoteImage) {
            throw new InvalidArgumentException(json_encode(['attachment' => $this->remote?->lastError() ?: 'The image could not be retrieved from the source page.']));
        }
        if ($remoteImage && empty(trim((string)($input['title'] ?? '')))) {
            $input['title'] = $remoteImage['name'];
        }
        $id = Id::uuid();
        $data = ['id' => $id,'user_id' => $userId,'device_id' => $deviceId,'client_capture_id' => (string)$input['client_capture_id'],'type' => (string)$input['type'],'title' => $this->nullable($input['title'] ?? null),'text' => $this->nullable($input['text'] ?? null),'url' => $this->nullable($input['url'] ?? null),'extracted_text' => $this->nullable($input['extracted_text'] ?? null),'source' => $source,'metadata_json' => json_encode($input['metadata'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)];
        $stored = [];
        try {
            $this->database->transaction(function () use ($repo, &$data, $files, $id, &$stored, $remoteImage) {
                $data['catch_number'] = $repo->nextCatchNumber($data['user_id']);
                $repo->insert($data);
                foreach ($this->normalizeFiles($files) as $file) {
                    $attachment = $this->uploads->store($file, $id);
                    $stored[] = $attachment['storage_name'];
                    $repo->addAttachment($attachment);
                }
                if ($remoteImage) {
                    $attachment = $this->uploads->storeContents($remoteImage['contents'], $remoteImage['name'], $remoteImage['type'], $id);
                    $stored[] = $attachment['storage_name'];
                    $repo->addAttachment($attachment);
                }
            });
        } catch (UnsupportedAttachmentException $error) {
            foreach ($stored as $storageName) {
                $this->uploads->remove($storageName);
            }
            throw new InvalidArgumentException(json_encode(['attachment' => $error->getMessage()]));
        } catch (\Throwable $error) {
            foreach ($stored as $storageName) {
                $this->uploads->remove($storageName);
            }
            throw $error;
        }
        return ['capture' => $repo->find($id, $userId),'created' => true];
    }

    public function refreshLinkPreview(
        string $captureId,
        string $userId,
        bool $removeOnFailure = false,
        bool $force = false,
    ): bool {
        if (!$this->remote) {
            return false;
        }

        $repo = new CaptureRepository($this->database->connection());
        $capture = $repo->find($captureId, $userId);
        if (!$capture) {
            return false;
        }

        $url = trim((string) ($capture['url'] ?? ''));
        $metadata = is_array($capture['metadata'] ?? null) ? $capture['metadata'] : [];
        $fetch = is_array($metadata['link_preview_fetch'] ?? null)
            ? $metadata['link_preview_fetch']
            : ['status' => 'pending', 'attempts' => 0];
        if (!$force && !$this->previewFetchIsDue($fetch)) {
            return false;
        }

        $attempts = min(3, max(0, (int) ($fetch['attempts'] ?? 0)) + 1);

        $linkPreview = null;
        if ($this->isHttpUrl($url)) {
            try {
                $linkPreview = $this->remote->linkPreview($url);
            } catch (\Throwable) {
                $linkPreview = null;
            }
        }

        if ($linkPreview) {
            $metadata['link_preview'] = $linkPreview['metadata'];
            $metadata['link_preview_fetch'] = [
                'status' => 'complete',
                'attempts' => $attempts,
                'attempted_at' => gmdate(DATE_ATOM),
            ];
        } else {
            if ($removeOnFailure) {
                unset($metadata['link_preview']);
            }
            $metadata['link_preview_fetch'] = $this->failedPreviewFetchState($attempts);
        }

        $newPreview = null;
        if (!empty($linkPreview['image']['contents'])) {
            try {
                $newPreview = $this->uploads->storePreview(
                    $linkPreview['image']['contents'],
                    $captureId,
                );
            } catch (\Throwable) {
                $newPreview = null;
            }
        }

        $replacePreview = $linkPreview !== null || $removeOnFailure;
        $oldStorageNames = $replacePreview
            ? $repo->previewStorageNames($captureId, $userId)
            : [];
        $defaultTitle = $this->nullable($capture['title'] ?? null) === null
            ? $this->nullable($linkPreview['metadata']['title'] ?? null)
            : null;
        try {
            $this->database->transaction(function () use (
                $repo,
                $captureId,
                $userId,
                $metadata,
                $newPreview,
                $replacePreview,
                $defaultTitle,
            ): void {
                if ($replacePreview) {
                    $repo->deletePreviewAttachments($captureId, $userId);
                }
                $repo->updateMetadata(
                    $captureId,
                    $userId,
                    $metadata,
                    $defaultTitle,
                );
                if ($newPreview) {
                    $repo->addAttachment($newPreview);
                }
            });
        } catch (\Throwable $error) {
            if ($newPreview) {
                $this->uploads->remove($newPreview['storage_name']);
            }
            throw $error;
        }

        foreach ($oldStorageNames as $storageName) {
            $this->uploads->remove($storageName);
        }

        return $linkPreview !== null;
    }

    private function previewFetchIsDue(array $fetch): bool
    {
        $status = (string) ($fetch['status'] ?? 'pending');
        if (in_array($status, ['complete', 'unavailable'], true)) {
            return false;
        }

        if ((int) ($fetch['attempts'] ?? 0) >= 3) {
            return false;
        }

        $retryAt = strtotime((string) ($fetch['retry_at'] ?? ''));

        return $retryAt === false || $retryAt <= time();
    }

    private function failedPreviewFetchState(int $attempts): array
    {
        $error = $this->remote?->lastError();
        $retryable = $error !== null && (
            str_contains($error, 'request failed')
            || str_contains($error, 'could not be resolved')
            || preg_match('/HTTP (429|5\d\d)\b/', $error) === 1
        );

        $state = [
            'status' => $retryable && $attempts < 3 ? 'retry' : 'unavailable',
            'attempts' => $attempts,
            'attempted_at' => gmdate(DATE_ATOM),
            'error' => $error ?? 'No supported preview metadata was found.',
        ];
        if ($state['status'] === 'retry') {
            $delays = [900, 21600, 86400];
            $state['retry_at'] = gmdate(DATE_ATOM, time() + $delays[$attempts - 1]);
        }

        return $state;
    }
    private function nullable(mixed $value): ?string
    {
        $value = trim((string)$value);
        return $value === '' ? null : $value;
    }
    private function normalizeFiles(array $files): array
    {
        $field = $files['attachments'] ?? $files['attachment'] ?? null;
        if (!$field || !isset($field['name'])) {
            return [];
        }
        if (!is_array($field['name'])) {
            return [$field];
        } $result = [];
        foreach ($field['name'] as $i => $name) {
            if (($field['error'][$i] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                $result[] = ['name' => $name,'type' => $field['type'][$i] ?? '','tmp_name' => $field['tmp_name'][$i],'error' => $field['error'][$i],'size' => $field['size'][$i]];
            }
        }
        return $result;
    }

    private function normalizeUnknownInput(array $input, array $files): array
    {
        $nonFileAttachment = $input['attachments'] ?? $input['attachment'] ?? null;
        if ($this->hasValue($nonFileAttachment)) {
            throw new InvalidArgumentException(json_encode(['attachment' => 'Unknown captures accept attachments only as image or PDF file uploads.']));
        }

        $attachments = $this->normalizeFiles($files);
        $hasImage = false;
        $hasAudio = false;
        $hasPdf = false;
        $totalSize = 0;
        foreach ($attachments as $file) {
            try {
                $info = $this->uploads->inspectUnknownAttachment($file);
            } catch (\RuntimeException $error) {
                throw new InvalidArgumentException(json_encode(['attachment' => $error->getMessage()]));
            }
            $totalSize += $info['size'];
            if ($totalSize > $this->uploads->maxBytes()) {
                throw new InvalidArgumentException(json_encode(['attachment' => 'The combined attachments exceed the upload limit.']));
            }
            if ($info['mime'] === 'application/pdf') {
                $hasPdf = true;
            } elseif (str_starts_with($info['mime'], 'audio/')) {
                $hasAudio = true;
            } else {
                $hasImage = true;
            }
        }

        $text = trim((string)($input['text'] ?? ''));
        $url = trim((string)($input['url'] ?? ''));
        $extracted = trim((string)($input['extracted_text'] ?? ''));
        if ($url === '' && $this->isHttpUrl($text)) {
            $url = $text;
            $text = '';
            $input['url'] = $url;
            $input['text'] = null;
        }
        if ($text === '' && $url === '' && !$attachments && $extracted !== '') {
            $text = $extracted;
            $input['text'] = $text;
        }

        $kinds = [];
        if ($text !== '') {
            $kinds[] = 'text';
        }if ($url !== '') {
            $kinds[] = 'url';
        }if ($hasImage) {
            $kinds[] = 'image';
        }if ($hasAudio) {
            $kinds[] = 'audio';
        }if ($hasPdf) {
            $kinds[] = 'file';
        }
        $input['type'] = count($kinds) > 1 ? 'mixed' : ($kinds[0] ?? 'text');

        if (empty(trim((string)($input['title'] ?? '')))) {
            if ($text !== '') {
                $input['title'] = $this->textTitle($text);
            }
            if (empty(trim((string)($input['title'] ?? ''))) && count($attachments) === 1) {
                $input['title'] = mb_substr(basename((string)($attachments[0]['name'] ?? '')), 0, 500);
            }
        }
        return $input;
    }

    private function isHttpUrl(string $value): bool
    {
        if (filter_var($value, FILTER_VALIDATE_URL) === false) {
            return false;
        }
        return in_array(strtolower((string)(parse_url($value, PHP_URL_SCHEME) ?? '')), ['http','https'], true);
    }

    private function textTitle(string $text): string
    {
        $line = trim((string)(preg_split('/\R/u', $text, 2)[0] ?? $text));
        return mb_strimwidth($line, 0, 120, '…');
    }

    private function hasValue(mixed $value): bool
    {
        if (is_array($value)) {
            return array_filter($value, fn (mixed $item): bool => $this->hasValue($item)) !== [];
        }
        return trim((string)$value) !== '';
    }
}
