<?php

declare(strict_types=1);

namespace Catch\Repositories;

use Catch\Core\Id;
use PDO;

final class EmailImportRepository
{
    public function __construct(private readonly PDO $db)
    {
    }

    public function record(string $inboxId, string $messageKey, ?string $messageId, string $captureId): void
    {
        $query = $this->db->prepare(
            'INSERT INTO catch_email_imports '
            . '(id,inbox_id,message_key_hash,message_id,capture_id,created_at) '
            . 'VALUES (:id,:inbox,:message_key,:message_id,:capture,UTC_TIMESTAMP(6)) '
            . 'ON DUPLICATE KEY UPDATE capture_id=VALUES(capture_id)',
        );
        $query->execute([
            'id' => Id::uuid(),
            'inbox' => $inboxId,
            'message_key' => hash('sha256', $messageKey),
            'message_id' => $messageId,
            'capture' => $captureId,
        ]);
    }
}
