<?php

declare(strict_types=1);

namespace Catch\Core;

use Catch\Services\BrowserInfo;
use DateTimeImmutable;
use DateTimeZone;

final class View
{
    private const DEVICE_TYPE_LABELS = ['laptop' => 'Laptop','phone' => 'Phone','pc' => 'PC','tablet' => 'Tablet','extension' => 'Extension','cli' => 'CLI'];
    private const CLIENT_LABELS = ['web' => 'Web session','extension' => 'Browser extension','shortcut' => 'Shortcut','api' => 'API client','cli' => 'CLI client'];

    public function __construct(private readonly string $path)
    {
        $f3 = \Base::instance();
        $f3->set('UI', rtrim($this->path, '/\\') . '/');
        $f3->set('TEMP', dirname($this->path, 2) . '/storage/tmp/');
    }

    public function render(string $template, array $data = [], int $httpStatus = 200): void
    {
        http_response_code($httpStatus);
        $data = $this->prepare($data, $template === 'captures/show');
        $data['content'] = $template . '.html';
        echo \Template::instance()->render('layout.html', 'text/html', $data);
    }

    public function partial(string $template, array $data = []): string
    {
        return \Template::instance()->render($template . '.html', 'text/html', $this->prepare($data));
    }

    public function relativeTime(?string $value, ?DateTimeImmutable $now = null): string
    {
        if (!$value) {
            return '<1m';
        }
        $zone = new DateTimeZone('UTC');
        $date = new DateTimeImmutable($value, $zone);
        $now ??= new DateTimeImmutable('now', $zone);
        $seconds = max(0, $now->getTimestamp() - $date->getTimestamp());

        return match (true) {
            $seconds < 60 => '<1m',
            $seconds < 3_600 => intdiv($seconds, 60) . 'm',
            $seconds < 86_400 => intdiv($seconds, 3_600) . 'h',
            $seconds < 2_592_000 => intdiv($seconds, 86_400) . 'd',
            $seconds < 31_536_000 => intdiv($seconds, 2_592_000) . 'mo',
            default => intdiv($seconds, 31_536_000) . 'y',
        };
    }

    private function prepare(array $data, bool $detailCapture = false): array
    {
        $data += [
            'title' => 'Catch','user' => null,'configured' => false,'csrf' => '','status' => '',
            'captures' => [],'capture' => null,'devices' => [],'device' => null,
            'availableLists' => [],'availableTags' => [],'emailInboxes' => [],'emailInbox' => null,
            'debugRequests' => [],'debugEnabled' => false,'enableCaptureActionMenu' => false,
            'enableListDialog' => false,'enableTagDialog' => false,'error' => null,
            'connected' => false,'request' => null,'pairing' => null,'list' => null,
            'tag' => null,'tags' => [],'lists' => [],'settingsTab' => 'general','login' => '','appUrl' => '',
            'bulkFormId' => '','debugRequestHeading' => 'Incoming capture requests','debugRequestCard' => false,
            'isShareTarget' => false,'captureUrl' => '','shareError' => '','enableLaterDialog' => false,
            'enableMoveDialog' => false,
            'capturePoll' => false,
        ];
        $data['currentPath'] = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        $data['isAuthenticated'] = is_array($data['user']);
        $data['isComingSoon'] = $data['currentPath'] === '/coming-soon';
        $data['layoutCsrf'] = (string) ($data['csrf'] ?: ($_SESSION['_csrf'] ?? ''));
        $data['year'] = date('Y');
        $data['flashMessages'] = $this->flashMessages();

        if ($data['isAuthenticated']) {
            $data['displayName'] = trim((string) ($data['user']['display_name'] ?? '')) ?: 'Catch User';
            $data['avatarUrl'] = trim((string) ($data['user']['avatar_url'] ?? ''));
            $data['avatarInitial'] = mb_strtoupper(mb_substr($data['displayName'], 0, 1));
            $data['profileCreatedAt'] = $this->utc((string) ($data['user']['created_at'] ?? ''));
        } else {
            $data['displayName'] = '';
            $data['avatarUrl'] = '';
            $data['avatarInitial'] = 'C';
            $data['profileCreatedAt'] = '';
        }

        $data['devices'] = array_map($this->prepareDevice(...), $data['devices']);
        $data['deviceTypes'] = self::DEVICE_TYPE_LABELS;
        if (is_array($data['device'])) {
            $data['device'] = $this->prepareDevice($data['device']);
        }
        $data['emailInboxes'] = array_map($this->prepareEmailInbox(...), $data['emailInboxes']);
        if (is_array($data['emailInbox'])) {
            $data['emailInbox'] = $this->prepareEmailInbox($data['emailInbox']);
        }

        $data['captures'] = array_map($this->prepareCapture(...), $data['captures']);
        if (is_array($data['capture'])) {
            $data['capture'] = $this->prepareCapture($data['capture'], $detailCapture);
            $data += $data['capture']['view'];
        }
        foreach ($data['debugRequests'] as &$request) {
            $request['utcCreatedAt'] = $this->utc((string) $request['created_at']);
            $request['verdictLabel'] = str_replace('_', ' ', (string) $request['verdict']);
            $request['contentLengthLabel'] = $request['content_length'] === null ? 'not sent' : number_format((int) $request['content_length']) . ' bytes';
        }
        unset($request);

        $data['captureCollectionVariant'] ??= 'switchable';
        $data['captureShowActions'] ??= true;
        $data['captureShowViewToggle'] ??= $data['captureCollectionVariant'] === 'switchable';
        $data['captureEmptyTitle'] ??= $data['status'] === 'trash' ? 'Trash is empty' : 'Nothing here yet';
        $data['captureEmptyText'] ??= $data['status'] === 'trash' ? 'Captures you move to Trash will appear here for 30 days.' : 'Captures matching this view will appear here.';
        $data['capturePollAfter'] = $data['captures']
            ? max(array_map(static fn (array $capture): int => (int) ($capture['catch_number'] ?? 0), $data['captures']))
            : 0;
        $data['collectionListId'] = (string) ($data['list']['id'] ?? '');
        $data['heading'] = match ($data['status']) {
            'archived' => 'Archived','trash' => 'Trash',default => 'Inbox'
        };
        $data['permanent'] = $data['status'] === 'trash';

        return $data;
    }

    private function prepareDevice(array $device): array
    {
        $info = BrowserInfo::fromUserAgent((string) ($device['user_agent'] ?? ''));
        $platform = (string) ($device['platform'] ?? '');
        $platformLabel = ['ios' => 'iOS','ipados' => 'iPadOS'][$platform] ?? ucfirst($platform);
        $deviceType = array_key_exists((string) ($device['device_type'] ?? ''), self::DEVICE_TYPE_LABELS) ? (string) $device['device_type'] : 'pc';
        $device['view'] = [
            'typeLabel' => self::CLIENT_LABELS[$device['client_type'] ?? 'shortcut'] ?? 'Device',
            'platformLabel' => !empty($device['user_agent']) ? $info['browser'] . ' on ' . $info['os'] : $platformLabel,
            'deviceType' => $deviceType,
            'statusLabel' => match ((string) ($device['status'] ?? 'setup')) {
                'connected' => 'Connected','revoked' => 'Access removed',default => 'Setup pending'
            },
            'utcConnectedAt' => $this->utc((string) ($device['connected_at'] ?? '')),
            'utcLastSeenAt' => $this->utc((string) ($device['last_seen_at'] ?? '')),
            'relativeLastSeenAt' => $this->relativeTime($device['last_seen_at'] ?? null),
        ];
        return $device;
    }

    private function prepareEmailInbox(array $inbox): array
    {
        $inbox['active'] = empty($inbox['revoked_at']);
        $inbox['view'] = [
            'utcLastUsedAt' => $this->utc((string) ($inbox['last_used_at'] ?? '')),
            'relativeLastUsedAt' => $this->relativeTime($inbox['last_used_at'] ?? null),
        ];

        return $inbox;
    }

    private function prepareCapture(array $capture, bool $detail = false): array
    {
        $capture += [
            'title' => null,'text' => null,'url' => null,'extracted_text' => null,'deleted_at' => null,
            'status' => 'inbox','created_at' => '','type' => 'text','tags' => [],'lists' => [],
            'attachments' => [],'visual_attachment_id' => null,'assigned_list_ids' => '',
        ];
        $metadata = is_array($capture['metadata'] ?? null) ? $capture['metadata'] : [];
        $status = !empty($capture['deleted_at']) ? 'trash' : (string) ($capture['status'] ?? 'inbox');
        $timeValue = (string) ($status === 'trash' ? $capture['deleted_at'] : $capture['created_at']);
        $title = (string) ($capture['title'] ?: mb_strimwidth((string) ($capture['text'] ?: $capture['url'] ?: 'Attachment'), 0, 90, '…'));
        $excerpt = trim((string) ($capture['text'] ?: ($capture['extracted_text'] ?? '')));
        $host = !empty($capture['url']) ? (string) parse_url((string) $capture['url'], PHP_URL_HOST) : '';
        $host = preg_replace('/^www\./i', '', $host) ?: $host;
        $previewFetch = is_array($metadata['link_preview_fetch'] ?? null) ? $metadata['link_preview_fetch'] : [];
        $retryAt = strtotime((string) ($previewFetch['retry_at'] ?? ''));
        $capture['view'] = [
            'icon' => match ((string) $capture['type']) {
                'url' => 'link','image' => 'image','audio' => 'voice',default => 'text'
            },
            'title' => $title,'url' => '/captures/' . urlencode((string) $capture['id']),'status' => $status,
            'statusLabel' => $status === 'trash' ? 'Trash' : ucfirst($status),'timeValue' => $timeValue,
            'utcTimeValue' => $this->utc($timeValue),'relativeTime' => $this->relativeTime($timeValue),
            'excerpt' => $excerpt ?: $title,
            'excerptHtml' => nl2br(htmlspecialchars($excerpt ?: $title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')),
            'host' => $host ?: 'Link',
            'assignedListIdsJson' => json_encode(array_values(array_filter(explode(',', (string) ($capture['assigned_list_ids'] ?? '')))), JSON_THROW_ON_ERROR),
            'previewFetchDue' => empty($capture['visual_attachment_id']) && $status !== 'trash' && !empty($capture['url'])
                && in_array((string) ($previewFetch['status'] ?? ''), ['pending', 'retry'], true)
                && (int) ($previewFetch['attempts'] ?? 0) < 3 && ($retryAt === false || $retryAt <= time()),
        ];
        return $detail ? $this->prepareCaptureDetail($capture, $metadata) : $capture;
    }

    private function prepareCaptureDetail(array $capture, array $metadata): array
    {
        $safeHttp = static fn (mixed $value): ?string => is_string($value) && preg_match('~^https?://~i', $value) ? $value : null;
        $sourceUrl = $safeHttp($metadata['source_url'] ?? null) ?? $safeHttp($metadata['referring_page_url'] ?? null) ?? $safeHttp($capture['url'] ?? null);
        $sourceTitle = trim((string) ($metadata['source_title'] ?? $metadata['referring_page_title'] ?? $metadata['page_title'] ?? ''));
        if ($sourceTitle === '' && $sourceUrl && $sourceUrl === $capture['url']) {
            $sourceTitle = trim((string) ($capture['title'] ?? ''));
        }
        $sourceDomain = trim((string) ($metadata['source_domain'] ?? ''));
        if ($sourceDomain === '' && $sourceUrl) {
            $sourceDomain = (string) (parse_url($sourceUrl, PHP_URL_HOST) ?? '');
        }
        $context = (string) ($metadata['browser_context'] ?? '');
        $method = (string) ($metadata['capture_method'] ?? '');
        if ($method === '') {
            $method = $capture['source'] === 'browser-extension' ? (str_contains($context, 'context-menu') ? 'browser-extension-context-menu' : 'browser-extension') : (string) $capture['source'];
        }

        $primaryImage = $primaryAudio = $previewAttachment = null;
        foreach ($capture['attachments'] as &$attachment) {
            $attachment['viewUrl'] = '/attachments/' . urlencode((string) $attachment['id']);
            $attachment['sizeKb'] = number_format((int) $attachment['size_bytes'] / 1024, 1, '.', ',');
            $kind = $attachment['kind'] ?? 'source';
            $mime = (string) $attachment['mime_type'];
            if ($kind === 'source' && !$primaryImage && $capture['type'] === 'image' && str_starts_with($mime, 'image/')) {
                $primaryImage = $attachment;
            }
            if ($kind === 'source' && !$primaryAudio && $capture['type'] === 'audio' && str_starts_with($mime, 'audio/')) {
                $primaryAudio = $attachment;
            }
            if ($kind === 'preview' && !$previewAttachment && !empty($attachment['available'])) {
                $previewAttachment = $attachment;
            }
        }
        unset($attachment);
        $remaining = array_values(array_filter(
            $capture['attachments'],
            static fn (array $attachment): bool =>
            ($attachment['kind'] ?? 'source') === 'source'
            && (!$primaryImage || $attachment['id'] !== $primaryImage['id'])
            && (!$primaryAudio || $attachment['id'] !== $primaryAudio['id']),
        ));
        $isTrashed = $capture['view']['status'] === 'trash';
        $linkPreview = is_array($metadata['link_preview'] ?? null) ? $metadata['link_preview'] : [];
        $previewFetch = is_array($metadata['link_preview_fetch'] ?? null) ? $metadata['link_preview_fetch'] : ['status' => 'pending','attempts' => 0];
        $retryAt = strtotime((string) ($previewFetch['retry_at'] ?? ''));
        $textMatchesTitle = !empty($capture['text']) && trim((string) $capture['text']) === trim((string) $capture['title']);
        $deviceLabel = trim((string) ($capture['device_name'] ?? '')) ?: match ((string) $capture['source']) {
            'web' => 'Catch Web','browser-extension' => 'Browser Extension','ios-shortcut' => 'iOS Shortcut',default => ucwords(str_replace(['-', '_'], ' ', (string) $capture['source']))
        };
        $previewTitle = trim((string) ($linkPreview['title'] ?? $capture['title'] ?? ''));
        $previewProvider = trim((string) ($linkPreview['provider_name'] ?? $sourceDomain));
        $previewAuthor = trim((string) ($linkPreview['author_name'] ?? ''));
        $trashExpires = $isTrashed ? date('Y-m-d H:i:s', strtotime((string) $capture['deleted_at'] . ' UTC +30 days')) : '';

        $capture['view'] += [
            'metadata' => $metadata,'utcCreatedAt' => $this->utc((string) $capture['created_at']),'isTrashed' => $isTrashed,
            'textHtml' => nl2br(htmlspecialchars((string) $capture['text'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')),
            'extractedTextHtml' => nl2br(htmlspecialchars((string) $capture['extracted_text'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')),
            'primaryImage' => $primaryImage,'primaryAudio' => $primaryAudio,'previewAttachment' => $previewAttachment,
            'previewFetchDue' => !$previewAttachment && !$isTrashed && !empty($capture['url'])
                && in_array((string) ($previewFetch['status'] ?? 'pending'), ['pending', 'retry'], true)
                && (int) ($previewFetch['attempts'] ?? 0) < 3 && ($retryAt === false || $retryAt <= time()),
            'urlIsPrimary' => !$primaryImage && !empty($capture['url']) && ($capture['type'] === 'url' || empty($capture['text']) || $textMatchesTitle),
            'remainingAttachments' => $remaining,'trashExpires' => $trashExpires,'utcTrashExpires' => $this->utc($trashExpires),
            'backRoute' => $isTrashed ? '/trash' : ($capture['status'] === 'archived' ? '/archive' : '/inbox'),
            'backLabel' => $isTrashed ? 'Trash' : ($capture['status'] === 'archived' ? 'Archived' : 'Inbox'),
            'assignedListIdsJson' => json_encode(array_column($capture['lists'] ?? [], 'id'), JSON_THROW_ON_ERROR),
            'deviceLabel' => $deviceLabel,'sourceUrl' => $sourceUrl,'sourceTitle' => $sourceTitle ?: $sourceDomain ?: $sourceUrl,
            'emailInboxId' => (string) ($capture['email_inbox_id'] ?? ''),
            'emailInboxName' => trim((string) ($capture['email_inbox_name'] ?? '')) ?: 'Email inbox',
            'emailInboxAddress' => trim((string) ($capture['email_inbox_address'] ?? '')),
            'emailFromAddress' => (string) ($capture['source'] ?? '') === 'email'
                ? trim((string) ($metadata['from'] ?? ''))
                : '',
            'linkedUrl' => $safeHttp($metadata['linked_url'] ?? null),
            'methodLabel' => match ($method) {
                'browser-extension-context-menu' => 'Browser Extension, Context Menu','browser-extension' => 'Browser Extension','ios-shortcut' => 'iOS Shortcut','web' => 'Catch Web',default => ucwords(str_replace(['-', '_'], ' ', $method))
            },
            'linkPreview' => ['title' => $previewTitle ?: (string) $capture['url'],'ariaTitle' => $previewTitle ?: 'captured link',
                'description' => trim((string) ($linkPreview['description'] ?? '')),'provider' => $previewProvider,
                'author' => $previewAuthor,'hasByline' => $previewProvider !== '' || $previewAuthor !== ''],
        ];
        return $capture;
    }

    private function flashMessages(): array
    {
        $messages = [];
        foreach (['error', 'success'] as $type) {
            $key = 'flash_' . $type;
            if (!empty($_SESSION[$key])) {
                $messages[] = ['type' => $type,'message' => (string) $_SESSION[$key]];
                unset($_SESSION[$key]);
            }
        }
        return $messages;
    }

    private function utc(?string $value): string
    {
        return $value ? str_replace(' ', 'T', substr($value, 0, 19)) . 'Z' : '';
    }
}
