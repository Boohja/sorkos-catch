<?php

declare(strict_types=1);

namespace Catch\Validation;

final class CaptureValidator
{
    private const TYPES = ['text','url','image','audio','file','mixed','unknown'];
    public function validate(array $input): array
    {
        $errors = [];
        if (!isset($input['client_capture_id']) || !is_string($input['client_capture_id']) || strlen($input['client_capture_id']) > 128) {
            $errors['client_capture_id'] = 'A stable client_capture_id is required.';
        }
        if (!in_array($input['type'] ?? '', self::TYPES, true)) {
            $errors['type'] = 'Type must be text, url, image, audio, file, mixed, or unknown.';
        }
        if (isset($input['url']) && $input['url'] !== '' && filter_var($input['url'], FILTER_VALIDATE_URL) === false) {
            $errors['url'] = 'URL is invalid.';
        }
        if (empty(trim((string)($input['text'] ?? ''))) && empty(trim((string)($input['url'] ?? ''))) && empty(trim((string)($input['remote_attachment_url'] ?? ''))) && empty($_FILES['attachments']['name'][0]) && empty($_FILES['attachment']['name'])) {
            $errors['content'] = 'Provide text, a URL, or an attachment.';
        }
        if (isset($input['remote_attachment_url']) && $input['remote_attachment_url'] !== '' && filter_var($input['remote_attachment_url'], FILTER_VALIDATE_URL) === false) {
            $errors['remote_attachment_url'] = 'The remote attachment URL is invalid.';
        }
        return $errors;
    }
}
