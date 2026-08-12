<?php

declare(strict_types=1);

namespace Catch\Services;

use Catch\Core\Config;
use Catch\Core\Id;
use finfo;
use RuntimeException;

final class UploadService
{
    private const DEFAULT_ALLOWED_MIME = [
        'image/jpeg',
        'image/png',
        'image/webp',
        'application/pdf',
        'text/plain',
        'audio/mpeg',
        'audio/mp4',
        'audio/m4a',
        'audio/x-m4a',
        'audio/aac',
        'audio/x-aac',
        'audio/wav',
        'audio/x-wav',
        'audio/vnd.wave',
        'audio/x-caf',
        'audio/ogg',
        'audio/webm',
        'audio/flac',
        'audio/x-flac',
    ];

    private array $allowed;

    public function __construct(
        private readonly Config $config,
        private readonly string $path,
    ) {
        $configured = (string) $config->get(
            'uploads.allowed_mime',
            implode(',', self::DEFAULT_ALLOWED_MIME),
        );
        $this->allowed = array_filter(array_map('trim', explode(',', $configured)));
    }

    public function store(array $file, string $captureId): array
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new RuntimeException('The attachment could not be uploaded.');
        }

        $size = (int) $file['size'];
        if ($size > $this->maxBytes()) {
            throw new RuntimeException('The attachment exceeds the upload limit.');
        }

        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name'])
            ?: 'application/octet-stream';
        if (!in_array($mime, $this->allowed, true)) {
            throw new UnsupportedAttachmentException($this->rejectionMessage($file, $mime));
        }

        $storageName = Id::uuid();
        $relativeDirectory = gmdate('Y/m');
        $directory = $this->path . '/' . $relativeDirectory;
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('Upload storage is unavailable.');
        }

        $target = $directory . '/' . $storageName;
        if (!move_uploaded_file($file['tmp_name'], $target)) {
            throw new RuntimeException('The attachment could not be stored.');
        }

        chmod($target, 0600);
        [$width, $height] = $this->imageDimensions($target, $mime);

        return [
            'id' => Id::uuid(),
            'capture_id' => $captureId,
            'original_name' => basename((string) $file['name']),
            'storage_name' => $relativeDirectory . '/' . $storageName,
            'mime_type' => $mime,
            'size_bytes' => $size,
            'width' => $width,
            'height' => $height,
            'checksum' => hash_file('sha256', $target),
        ];
    }

    /** @return array{mime: string, size: int} */
    public function inspectUnknownAttachment(array $file): array
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new RuntimeException('The attachment could not be uploaded.');
        }

        $path = (string) ($file['tmp_name'] ?? '');
        $actualSize = $path !== '' && is_file($path) ? filesize($path) : false;
        $size = $actualSize === false ? (int) ($file['size'] ?? 0) : (int) $actualSize;

        if ($size <= 0) {
            throw new RuntimeException('Empty attachments are not accepted.');
        }
        if ($size > $this->maxBytes()) {
            throw new RuntimeException('The attachment exceeds the upload limit.');
        }

        $detected = $path !== '' ? (new finfo(FILEINFO_MIME_TYPE))->file($path) : false;
        $mime = $detected ?: 'application/octet-stream';
        $safeKind = str_starts_with($mime, 'image/')
            || str_starts_with($mime, 'audio/')
            || $mime === 'application/pdf';

        if (!$safeKind || !in_array($mime, $this->allowed, true)) {
            throw new UnsupportedAttachmentException($this->rejectionMessage($file, $mime));
        }

        return ['mime' => $mime, 'size' => $size];
    }

    public function maxBytes(): int
    {
        return (int) $this->config->get('uploads.max_bytes', 15_728_640);
    }

    public function storeContents(
        string $contents,
        string $name,
        string $mime,
        string $captureId,
    ): array {
        $size = strlen($contents);
        if ($size === 0 || $size > $this->maxBytes()) {
            throw new RuntimeException('The remote attachment exceeds the upload limit.');
        }
        if (!in_array($mime, $this->allowed, true)) {
            throw new UnsupportedAttachmentException(
                $this->rejectionMessage(['name' => $name], $mime, 'remote attachment'),
            );
        }

        $storageName = Id::uuid();
        $relative = gmdate('Y/m') . '/' . $storageName;
        $directory = $this->path . '/' . gmdate('Y/m');
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('Upload storage is unavailable.');
        }

        $target = $this->path . '/' . $relative;
        if (file_put_contents($target, $contents, LOCK_EX) === false) {
            throw new RuntimeException('The remote attachment could not be stored.');
        }

        chmod($target, 0600);
        [$width, $height] = $this->imageDimensions($target, $mime);

        return [
            'id' => Id::uuid(),
            'capture_id' => $captureId,
            'original_name' => basename($name),
            'storage_name' => $relative,
            'mime_type' => $mime,
            'size_bytes' => $size,
            'width' => $width,
            'height' => $height,
            'checksum' => hash('sha256', $contents),
        ];
    }

    private function rejectionMessage(
        array $file,
        string $mime,
        string $subject = 'attachment',
    ): string {
        $extension = strtolower((string) pathinfo(
            (string) ($file['name'] ?? ''),
            PATHINFO_EXTENSION,
        ));
        $extension = $extension !== ''
            ? '.' . preg_replace('/[^a-z0-9]+/', '', $extension)
            : 'none';

        return 'This ' . $subject . ' type is not allowed. '
            . 'Detected MIME type: ' . $mime . '; file extension: ' . $extension . '.';
    }

    /** @return array{0: ?int, 1: ?int} */
    private function imageDimensions(string $path, string $mime): array
    {
        if (!str_starts_with($mime, 'image/')) {
            return [null, null];
        }

        $image = @getimagesize($path);
        if (!$image) {
            return [null, null];
        }

        return [(int) $image[0], (int) $image[1]];
    }
}
