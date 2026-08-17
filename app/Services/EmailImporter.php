<?php

declare(strict_types=1);

namespace Catch\Services;

use Catch\Core\Config;
use Catch\Repositories\EmailImportRepository;
use Catch\Repositories\EmailInboxRepository;
use RuntimeException;
use Throwable;

final class EmailImporter
{
    public function __construct(
        private readonly Config $config,
        private readonly EmailInboxRepository $inboxes,
        private readonly EmailImportRepository $imports,
        private readonly CaptureService $captures,
        private readonly EmailMessageReader $reader,
        private readonly string $logFile,
    ) {
    }

    /** @return array{processed: int, discarded: int, failed: int} */
    public function run(): array
    {
        $this->assertAvailable();
        $folder = trim((string) $this->config->get('mail.imap_folder', ''));
        $connection = @imap_open($this->mailbox($folder), (string) $this->config->get('mail.username'), (string) $this->config->get('mail.password'));
        if ($connection === false) {
            throw new RuntimeException('IMAP connection failed: ' . (imap_last_error() ?: 'unknown error'));
        }

        $counts = ['processed' => 0, 'discarded' => 0, 'failed' => 0];
        try {
            $uids = imap_search($connection, 'ALL', SE_UID) ?: [];
            foreach ($uids as $uid) {
                $this->process($connection, (int) $uid, $counts);
            }
            imap_expunge($connection);
        } finally {
            imap_close($connection);
        }

        return $counts;
    }

    /** @param array{processed: int, discarded: int, failed: int} $counts */
    private function process(mixed $connection, int $uid, array &$counts): void
    {
        // Fetching headers does not set the Seen flag. PHP only accepts the
        // fetch-header-specific flag set here; FT_PEEK is body-fetch only.
        $rawHeaders = imap_fetchheader($connection, $uid, FT_UID);
        if ($rawHeaders === false) {
            $this->discard($connection, $uid);
            ++$counts['discarded'];
            return;
        }

        $inbox = null;
        foreach ($this->reader->recipientAddresses($rawHeaders, (string) $this->config->get('mail.address_domain', 'catch.sorkos.net')) as $address) {
            $inbox = $this->inboxes->findActiveByAddress($address);
            if ($inbox) {
                break;
            }
        }
        if (!$inbox) {
            $this->discard($connection, $uid);
            ++$counts['discarded'];
            return;
        }

        try {
            $overview = imap_fetch_overview($connection, (string) $uid, FT_UID);
            $size = is_array($overview) && isset($overview[0]->size) ? (int) $overview[0]->size : 0;
            $maxBytes = (int) $this->config->get('mail.max_bytes', 5242880);
            if ($size > $maxBytes) {
                throw new RuntimeException(sprintf('Message size %d exceeds the configured %d-byte limit.', $size, $maxBytes));
            }

            $message = $this->reader->read($connection, $uid, $rawHeaders);
            $text = trim($message['body']);
            if ($text === '') {
                $text = $message['subject'] !== '' ? $message['subject'] : '(Empty email)';
            }
            $clientId = 'email:' . hash('sha256', $inbox['id'] . "\0" . $message['message_key']);
            $result = $this->captures->create($inbox['user_id'], [
                'client_capture_id' => $clientId,
                'type' => 'text',
                'title' => $message['subject'] !== '' ? mb_substr($message['subject'], 0, 500) : null,
                'text' => $text,
                'source' => 'email',
                'metadata' => [
                    'source' => 'email',
                    'from' => mb_substr($message['from'], 0, 998),
                    'received_at' => $message['received_at'],
                    'message_id' => $message['message_id'],
                    'has_attachments' => $message['has_attachments'],
                ],
            ]);
            $this->imports->record($inbox['id'], $message['message_key'], $message['message_id'], $result['capture']['id']);
            $this->move($connection, $uid, (string) $this->config->get('mail.imap_processed_folder'));
            ++$counts['processed'];
        } catch (Throwable $error) {
            $this->log($uid, $error);
            if (!$this->tryMove($connection, $uid, (string) $this->config->get('mail.imap_failed_folder'))) {
                $this->log($uid, new RuntimeException('The message could not be moved to the failed folder.'));
            }
            ++$counts['failed'];
        }
    }

    private function discard(mixed $connection, int $uid): void
    {
        imap_delete($connection, (string) $uid, FT_UID);
    }

    private function move(mixed $connection, int $uid, string $folder): void
    {
        if (!$this->tryMove($connection, $uid, $folder)) {
            throw new RuntimeException('The message could not be moved to ' . $folder . '.');
        }
    }

    private function tryMove(mixed $connection, int $uid, string $folder): bool
    {
        return $folder !== '' && imap_mail_move($connection, (string) $uid, $folder, CP_UID);
    }

    private function mailbox(string $folder): string
    {
        $host = trim((string) $this->config->get('mail.host'));
        $port = (int) $this->config->get('mail.port', 993);
        $security = mb_strtolower(trim((string) $this->config->get('mail.security', 'ssl')));
        $flags = '/imap';
        if (in_array($security, ['ssl', 'tls'], true)) {
            $flags .= '/' . $security;
        }
        if (!$this->config->bool('mail.validate_cert', true)) {
            $flags .= '/novalidate-cert';
        }

        return sprintf('{%s:%d%s}%s', $host, $port, $flags, $folder);
    }

    private function assertAvailable(): void
    {
        if (!extension_loaded('imap')) {
            throw new RuntimeException('The PHP IMAP extension is required.');
        }
        foreach (['host', 'username', 'password', 'imap_folder', 'imap_processed_folder', 'imap_failed_folder'] as $key) {
            if (trim((string) $this->config->get('mail.' . $key, '')) === '') {
                throw new RuntimeException('Missing mail configuration: mail.' . $key);
            }
        }
    }

    private function log(int $uid, Throwable $error): void
    {
        @error_log(sprintf("[%s] Email import UID %d failed: %s\n", gmdate(DATE_ATOM), $uid, $error->getMessage()), 3, $this->logFile);
    }
}
