<?php

declare(strict_types=1);

namespace Catch\Repositories;

use InvalidArgumentException;
use PDO;
use RuntimeException;
use Throwable;

final class CaptureRepository
{
    public function __construct(private readonly PDO $db)
    {
    }

    public function list(string $userId, string $status = 'inbox', int $limit = 100): array
    {
        $limit = max(1, min($limit, 200));
        if ($status === 'inbox') {
            $this->releaseDueLater($userId);
        }
        $sql = <<<SQL
            SELECT c.*, d.name device_name, d.client_type device_client_type,
                (SELECT COUNT(*) FROM catch_attachments a WHERE a.capture_id = c.id AND a.kind = 'source') attachment_count,
                (SELECT a.id FROM catch_attachments a
                    WHERE a.capture_id = c.id
                        AND a.mime_type LIKE 'image/%'
                        AND a.kind IN ('preview', 'source')
                    ORDER BY CASE WHEN a.kind = 'preview' THEN 0 ELSE 1 END,
                        a.created_at DESC, a.id DESC LIMIT 1) visual_attachment_id,
                (SELECT GROUP_CONCAT(cl.list_id ORDER BY cl.list_id)
                    FROM catch_capture_lists cl
                    WHERE cl.capture_id = c.id) assigned_list_ids
            FROM catch_captures c
            LEFT JOIN catch_devices d ON d.id = c.device_id
            WHERE c.user_id = :user
                AND c.status = :status
                AND c.deleted_at IS NULL
            ORDER BY c.created_at DESC
            LIMIT {$limit}
            SQL;
        $query = $this->db->prepare($sql);
        $query->execute(['user' => $userId, 'status' => $status]);

        return $this->withTags(array_map([$this, 'hydrate'], $query->fetchAll()), $userId);
    }

    public function listTrash(string $userId, int $limit = 100): array
    {
        $limit = max(1, min($limit, 200));
        $sql = <<<SQL
            SELECT c.*, d.name device_name, d.client_type device_client_type,
                (SELECT COUNT(*) FROM catch_attachments a WHERE a.capture_id = c.id AND a.kind = 'source') attachment_count,
                (SELECT a.id FROM catch_attachments a
                    WHERE a.capture_id = c.id
                        AND a.mime_type LIKE 'image/%'
                        AND a.kind IN ('preview', 'source')
                    ORDER BY CASE WHEN a.kind = 'preview' THEN 0 ELSE 1 END,
                        a.created_at DESC, a.id DESC LIMIT 1) visual_attachment_id,
                (SELECT GROUP_CONCAT(cl.list_id ORDER BY cl.list_id)
                    FROM catch_capture_lists cl
                    WHERE cl.capture_id = c.id) assigned_list_ids
            FROM catch_captures c
            LEFT JOIN catch_devices d ON d.id = c.device_id
            WHERE c.user_id = :user
                AND c.deleted_at IS NOT NULL
            ORDER BY c.deleted_at DESC
            LIMIT {$limit}
            SQL;
        $query = $this->db->prepare($sql);
        $query->execute(['user' => $userId]);

        return $this->withTags(array_map([$this, 'hydrate'], $query->fetchAll()), $userId);
    }

    public function listByTag(
        string $userId,
        string $tagId,
        string $status = 'inbox',
        int $limit = 100,
    ): array {
        $limit = max(1, min($limit, 200));
        if ($status === 'inbox') {
            $this->releaseDueLater($userId);
        }
        $sql = <<<SQL
            SELECT c.*, d.name device_name, d.client_type device_client_type,
                (SELECT COUNT(*) FROM catch_attachments a WHERE a.capture_id = c.id AND a.kind = 'source') attachment_count,
                (SELECT a.id FROM catch_attachments a
                    WHERE a.capture_id = c.id
                        AND a.mime_type LIKE 'image/%'
                        AND a.kind IN ('preview', 'source')
                    ORDER BY CASE WHEN a.kind = 'preview' THEN 0 ELSE 1 END,
                        a.created_at DESC, a.id DESC LIMIT 1) visual_attachment_id,
                (SELECT GROUP_CONCAT(assigned.list_id ORDER BY assigned.list_id)
                    FROM catch_capture_lists assigned
                    WHERE assigned.capture_id = c.id) assigned_list_ids
            FROM catch_captures c
            JOIN catch_capture_tags ct ON ct.capture_id = c.id
            LEFT JOIN catch_devices d ON d.id = c.device_id
            WHERE c.user_id = :user
                AND c.status = :status
                AND c.deleted_at IS NULL
                AND ct.tag_id = :tag
            ORDER BY c.created_at DESC
            LIMIT {$limit}
            SQL;
        $query = $this->db->prepare($sql);
        $query->execute(['user' => $userId, 'status' => $status, 'tag' => $tagId]);

        return $this->withTags(array_map([$this, 'hydrate'], $query->fetchAll()), $userId);
    }

    public function listByList(string $userId, string $listId, int $limit = 100): array
    {
        $limit = max(1, min($limit, 200));
        $sql = <<<SQL
            SELECT c.*, d.name device_name, d.client_type device_client_type,
                (SELECT COUNT(*) FROM catch_attachments a WHERE a.capture_id = c.id AND a.kind = 'source') attachment_count,
                (SELECT a.id FROM catch_attachments a
                    WHERE a.capture_id = c.id
                        AND a.mime_type LIKE 'image/%'
                        AND a.kind IN ('preview', 'source')
                    ORDER BY CASE WHEN a.kind = 'preview' THEN 0 ELSE 1 END,
                        a.created_at DESC, a.id DESC LIMIT 1) visual_attachment_id,
                (SELECT GROUP_CONCAT(assigned.list_id ORDER BY assigned.list_id)
                    FROM catch_capture_lists assigned
                    WHERE assigned.capture_id = c.id) assigned_list_ids
            FROM catch_captures c
            JOIN catch_capture_lists cl ON cl.capture_id = c.id
            LEFT JOIN catch_devices d ON d.id = c.device_id
            WHERE c.user_id = :user
                AND c.deleted_at IS NULL
                AND cl.list_id = :list
            ORDER BY cl.assigned_at DESC, c.created_at DESC
            LIMIT {$limit}
            SQL;
        $query = $this->db->prepare($sql);
        $query->execute(['user' => $userId, 'list' => $listId]);

        return $this->withTags(array_map([$this, 'hydrate'], $query->fetchAll()), $userId);
    }

    public function listByDevice(string $userId, string $deviceId, int $limit = 200): array
    {
        $limit = max(1, min($limit, 500));
        $sql = <<<SQL
            SELECT c.*, d.name device_name, d.client_type device_client_type,
                (SELECT COUNT(*) FROM catch_attachments a WHERE a.capture_id = c.id AND a.kind = 'source') attachment_count,
                (SELECT a.id FROM catch_attachments a
                    WHERE a.capture_id = c.id
                        AND a.mime_type LIKE 'image/%'
                        AND a.kind IN ('preview', 'source')
                    ORDER BY CASE WHEN a.kind = 'preview' THEN 0 ELSE 1 END,
                        a.created_at DESC, a.id DESC LIMIT 1) visual_attachment_id,
                (SELECT GROUP_CONCAT(cl.list_id ORDER BY cl.list_id)
                    FROM catch_capture_lists cl
                    WHERE cl.capture_id = c.id) assigned_list_ids
            FROM catch_captures c
            LEFT JOIN catch_devices d ON d.id = c.device_id
            WHERE c.user_id = :user
                AND c.device_id = :device
            ORDER BY c.created_at DESC
            LIMIT {$limit}
            SQL;
        $query = $this->db->prepare($sql);
        $query->execute(['user' => $userId, 'device' => $deviceId]);

        return $this->withTags(array_map([$this, 'hydrate'], $query->fetchAll()), $userId);
    }

    public function listByEmailInbox(string $userId, string $inboxId, int $limit = 200): array
    {
        $limit = max(1, min($limit, 500));
        $sql = <<<SQL
            SELECT c.*, d.name device_name, d.client_type device_client_type,
                (SELECT COUNT(*) FROM catch_attachments a WHERE a.capture_id = c.id AND a.kind = 'source') attachment_count,
                (SELECT a.id FROM catch_attachments a
                    WHERE a.capture_id = c.id
                        AND a.mime_type LIKE 'image/%'
                        AND a.kind IN ('preview', 'source')
                    ORDER BY CASE WHEN a.kind = 'preview' THEN 0 ELSE 1 END,
                        a.created_at DESC, a.id DESC LIMIT 1) visual_attachment_id,
                (SELECT GROUP_CONCAT(cl.list_id ORDER BY cl.list_id)
                    FROM catch_capture_lists cl
                    WHERE cl.capture_id = c.id) assigned_list_ids
            FROM catch_email_imports e
            JOIN catch_email_inboxes i ON i.id = e.inbox_id
            JOIN catch_captures c ON c.id = e.capture_id
            LEFT JOIN catch_devices d ON d.id = c.device_id
            WHERE i.user_id = :user
                AND i.id = :inbox
            ORDER BY e.created_at DESC
            LIMIT {$limit}
            SQL;
        $query = $this->db->prepare($sql);
        $query->execute(['user' => $userId, 'inbox' => $inboxId]);

        return $this->withTags(array_map([$this, 'hydrate'], $query->fetchAll()), $userId);
    }

    public function find(string $id, string $userId): ?array
    {
        $sql = <<<'SQL'
            SELECT c.*, d.name device_name, d.device_type,
                d.client_type device_client_type, d.platform device_platform,
                d.status device_status, i.id email_inbox_id,
                i.name email_inbox_name, i.address email_inbox_address
            FROM catch_captures c
            LEFT JOIN catch_devices d ON d.id = c.device_id
            LEFT JOIN catch_email_imports e ON e.capture_id = c.id
            LEFT JOIN catch_email_inboxes i ON i.id = e.inbox_id AND i.user_id = c.user_id
            WHERE c.id = :id AND c.user_id = :user
            LIMIT 1
            SQL;
        $query = $this->db->prepare($sql);
        $query->execute(['id' => $id, 'user' => $userId]);
        $capture = $query->fetch() ?: null;

        if (!$capture) {
            return null;
        }

        $capture = $this->hydrate($capture);
        $attachments = $this->db->prepare(
            'SELECT id, kind, original_name, mime_type, size_bytes, width, height, created_at '
            . 'FROM catch_attachments WHERE capture_id = :id ORDER BY created_at',
        );
        $attachments->execute(['id' => $id]);
        $capture['attachments'] = $attachments->fetchAll();
        $capture['tags'] = $this->tagsForCapture($id, $userId);
        $capture['lists'] = $this->listsForCapture($id, $userId);

        return $capture;
    }

    public function listNewerInboxCaptures(string $userId, int $afterNumber, int $limit = 50): array
    {
        $limit = max(1, min($limit, 100));
        $this->releaseDueLater($userId);
        $sql = <<<SQL
            SELECT c.*, d.name device_name, d.client_type device_client_type,
                (SELECT COUNT(*) FROM catch_attachments a WHERE a.capture_id = c.id AND a.kind = 'source') attachment_count,
                (SELECT a.id FROM catch_attachments a
                    WHERE a.capture_id = c.id
                        AND a.mime_type LIKE 'image/%'
                        AND a.kind IN ('preview', 'source')
                    ORDER BY CASE WHEN a.kind = 'preview' THEN 0 ELSE 1 END,
                        a.created_at DESC, a.id DESC LIMIT 1) visual_attachment_id,
                (SELECT GROUP_CONCAT(cl.list_id ORDER BY cl.list_id)
                    FROM catch_capture_lists cl
                    WHERE cl.capture_id = c.id) assigned_list_ids
            FROM catch_captures c
            LEFT JOIN catch_devices d ON d.id = c.device_id
            WHERE c.user_id = :user
                AND c.status = 'inbox'
                AND c.deleted_at IS NULL
                AND c.catch_number > :after_number
            ORDER BY c.catch_number ASC
            LIMIT {$limit}
            SQL;
        $query = $this->db->prepare($sql);
        $query->execute(['user' => $userId, 'after_number' => max(0, $afterNumber)]);

        return $this->withTags(array_map([$this, 'hydrate'], $query->fetchAll()), $userId);
    }

    public function findCollectionItem(string $id, string $userId): ?array
    {
        $sql = <<<'SQL'
            SELECT c.*, d.name device_name, d.client_type device_client_type,
                (SELECT COUNT(*) FROM catch_attachments a
                    WHERE a.capture_id = c.id AND a.kind = 'source') attachment_count,
                (SELECT a.id FROM catch_attachments a
                    WHERE a.capture_id = c.id
                        AND a.mime_type LIKE 'image/%'
                        AND a.kind IN ('preview', 'source')
                    ORDER BY CASE WHEN a.kind = 'preview' THEN 0 ELSE 1 END,
                        a.created_at DESC, a.id DESC LIMIT 1) visual_attachment_id,
                (SELECT GROUP_CONCAT(cl.list_id ORDER BY cl.list_id)
                    FROM catch_capture_lists cl
                    WHERE cl.capture_id = c.id) assigned_list_ids
            FROM catch_captures c
            LEFT JOIN catch_devices d ON d.id = c.device_id
            WHERE c.id = :id AND c.user_id = :user
            LIMIT 1
            SQL;
        $query = $this->db->prepare($sql);
        $query->execute(['id' => $id, 'user' => $userId]);
        $capture = $query->fetch() ?: null;

        if (!$capture) {
            return null;
        }

        return $this->withTags([$this->hydrate($capture)], $userId)[0] ?? null;
    }

    public function findByReference(string $reference, string $userId): ?array
    {
        if (preg_match('/^[1-9]\d*$/', $reference)) {
            $query = $this->db->prepare('SELECT id FROM catch_captures WHERE catch_number=:number AND user_id=:user LIMIT 1');
            $query->execute(['number' => (int) $reference, 'user' => $userId]);
            $id = $query->fetchColumn();
            if (!$id) {
                return null;
            }
            return $this->find((string) $id, $userId);
        }

        return $this->find($reference, $userId);
    }

    public function search(string $userId, string $term, string $status = 'inbox', int $limit = 100): array
    {
        $limit = max(1, min($limit, 200));
        $term = mb_substr(trim($term), 0, 500);
        if ($status === 'inbox') {
            $this->releaseDueLater($userId);
        }
        $statusClause = $status === 'deleted'
            ? 'c.deleted_at IS NOT NULL'
            : 'c.status=:status AND c.deleted_at IS NULL';
        $sql = <<<SQL
            SELECT c.*, d.name device_name, d.client_type device_client_type,
                (SELECT COUNT(*) FROM catch_attachments a WHERE a.capture_id=c.id AND a.kind='source') attachment_count,
                (SELECT a.id FROM catch_attachments a
                    WHERE a.capture_id = c.id
                        AND a.mime_type LIKE 'image/%'
                        AND a.kind IN ('preview', 'source')
                    ORDER BY CASE WHEN a.kind = 'preview' THEN 0 ELSE 1 END,
                        a.created_at DESC, a.id DESC LIMIT 1) visual_attachment_id,
                (SELECT GROUP_CONCAT(cl.list_id ORDER BY cl.list_id) FROM catch_capture_lists cl WHERE cl.capture_id=c.id) assigned_list_ids
            FROM catch_captures c
            LEFT JOIN catch_devices d ON d.id=c.device_id
            WHERE c.user_id=:user AND {$statusClause}
                AND (:term='' OR c.title LIKE :title_pattern OR c.text LIKE :text_pattern OR c.url LIKE :url_pattern OR c.extracted_text LIKE :extracted_pattern OR CAST(c.catch_number AS CHAR)=:number_term)
            ORDER BY c.created_at DESC
            LIMIT {$limit}
            SQL;
        $query = $this->db->prepare($sql);
        $parameters = [
            'user' => $userId,
            'term' => $term,
            'number_term' => $term,
            'title_pattern' => '%' . $term . '%',
            'text_pattern' => '%' . $term . '%',
            'url_pattern' => '%' . $term . '%',
            'extracted_pattern' => '%' . $term . '%',
        ];
        if ($status !== 'deleted') {
            $parameters['status'] = $status;
        }
        $query->execute($parameters);

        return $this->withTags(array_map([$this, 'hydrate'], $query->fetchAll()), $userId);
    }

    public function findByClientId(string $clientId, string $userId): ?array
    {
        $query = $this->db->prepare(
            'SELECT * FROM catch_captures '
            . 'WHERE client_capture_id = :client AND user_id = :user LIMIT 1',
        );
        $query->execute(['client' => $clientId, 'user' => $userId]);

        return $query->fetch() ?: null;
    }

    public function nextCatchNumber(string $userId): int
    {
        $query = $this->db->prepare(
            'SELECT next_catch_number FROM catch_users WHERE id = :user FOR UPDATE',
        );
        $query->execute(['user' => $userId]);
        $number = $query->fetchColumn();

        if ($number === false) {
            throw new RuntimeException('The capture owner could not be found.');
        }

        $this->db->prepare(
            'UPDATE catch_users SET next_catch_number = next_catch_number + 1 WHERE id = :user',
        )->execute(['user' => $userId]);

        return (int) $number;
    }

    public function insert(array $data): void
    {
        $sql = <<<'SQL'
            INSERT INTO catch_captures (
                id, user_id, device_id, catch_number, client_capture_id, type,
                title, text, url, extracted_text, source, metadata_json,
                status, created_at, updated_at
            ) VALUES (
                :id, :user_id, :device_id, :catch_number, :client_capture_id, :type,
                :title, :text, :url, :extracted_text, :source, :metadata_json,
                'inbox', UTC_TIMESTAMP(6), UTC_TIMESTAMP(6)
            )
            SQL;
        $this->db->prepare($sql)->execute($data);
    }

    public function addAttachment(array $data): void
    {
        $sql = <<<'SQL'
            INSERT INTO catch_attachments (
                id, capture_id, kind, original_name, storage_name, mime_type,
                size_bytes, width, height, checksum, created_at
            ) VALUES (
                :id, :capture_id, :kind, :original_name, :storage_name, :mime_type,
                :size_bytes, :width, :height, :checksum, UTC_TIMESTAMP(6)
            )
            SQL;
        $this->db->prepare($sql)->execute($data);
    }

    public function previewStorageNames(string $id, string $userId): array
    {
        $query = $this->db->prepare(<<<'SQL'
            SELECT a.storage_name
            FROM catch_attachments a
            JOIN catch_captures c ON c.id = a.capture_id
            WHERE a.capture_id = :capture
                AND a.kind = 'preview'
                AND c.user_id = :user
            SQL);
        $query->execute(['capture' => $id, 'user' => $userId]);

        return array_values(array_filter($query->fetchAll(PDO::FETCH_COLUMN), 'is_string'));
    }

    public function deletePreviewAttachments(string $id, string $userId): void
    {
        $query = $this->db->prepare(<<<'SQL'
            DELETE FROM catch_attachments
            WHERE capture_id = :capture
                AND kind = 'preview'
                AND EXISTS (
                    SELECT 1
                    FROM catch_captures c
                    WHERE c.id = catch_attachments.capture_id
                        AND c.user_id = :user
                )
            SQL);
        $query->execute(['capture' => $id, 'user' => $userId]);
    }

    public function updateMetadata(
        string $id,
        string $userId,
        array $metadata,
        ?string $defaultTitle = null,
    ): void {
        $query = $this->db->prepare(<<<'SQL'
            UPDATE catch_captures
            SET metadata_json = :metadata,
                title = COALESCE(NULLIF(title, ''), :default_title),
                updated_at = UTC_TIMESTAMP(6)
            WHERE id = :id AND user_id = :user
            SQL);
        $query->execute([
            'metadata' => json_encode(
                $metadata,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
            ),
            'default_title' => $defaultTitle,
            'id' => $id,
            'user' => $userId,
        ]);
    }

    public function setStatus(string $id, string $userId, string $status): bool
    {
        if (!in_array($status, ['inbox', 'archived'], true)) {
            throw new InvalidArgumentException('Unknown capture status.');
        }

        $archivedAt = $status === 'archived' ? 'UTC_TIMESTAMP(6)' : 'NULL';
        $sql = <<<SQL
            UPDATE catch_captures
            SET status = :status,
                archived_at = {$archivedAt},
                later_until = NULL,
                updated_at = UTC_TIMESTAMP(6)
            WHERE id = :id
                AND user_id = :user
                AND deleted_at IS NULL
            SQL;
        $query = $this->db->prepare($sql);
        $query->execute(['status' => $status, 'id' => $id, 'user' => $userId]);

        return $query->rowCount() > 0;
    }

    public function trash(string $id, string $userId): bool
    {
        $sql = <<<'SQL'
            UPDATE catch_captures
            SET deleted_at = UTC_TIMESTAMP(6), updated_at = UTC_TIMESTAMP(6)
            WHERE id = :id AND user_id = :user AND deleted_at IS NULL
            SQL;
        $query = $this->db->prepare($sql);
        $query->execute(['id' => $id, 'user' => $userId]);

        return $query->rowCount() > 0;
    }

    public function trashMany(string $userId, array $ids): int
    {
        [$placeholders, $params] = $this->captureIdParams($userId, $ids);
        if (!$placeholders) {
            return 0;
        }

        $sql = 'UPDATE catch_captures '
            . 'SET deleted_at = UTC_TIMESTAMP(6), updated_at = UTC_TIMESTAMP(6) '
            . 'WHERE user_id = :user AND deleted_at IS NULL '
            . 'AND id IN (' . implode(',', $placeholders) . ')';
        $query = $this->db->prepare($sql);
        $query->execute($params);

        return $query->rowCount();
    }

    public function archiveMany(string $userId, array $ids): int
    {
        [$placeholders, $params] = $this->captureIdParams($userId, $ids);
        if (!$placeholders) {
            return 0;
        }

        $sql = 'UPDATE catch_captures '
            . "SET status = 'archived', archived_at = COALESCE(archived_at, UTC_TIMESTAMP(6)), later_until = NULL, "
            . 'updated_at = UTC_TIMESTAMP(6) '
            . 'WHERE user_id = :user AND status = \'inbox\' AND deleted_at IS NULL '
            . 'AND id IN (' . implode(',', $placeholders) . ')';
        $query = $this->db->prepare($sql);
        $query->execute($params);

        return $query->rowCount();
    }

    public function later(string $id, string $userId, string $until): bool
    {
        $query = $this->db->prepare(<<<'SQL'
            UPDATE catch_captures
            SET status = 'later',
                later_until = :later_until,
                archived_at = NULL,
                updated_at = UTC_TIMESTAMP(6)
            WHERE id = :id
                AND user_id = :user
                AND status = 'inbox'
                AND deleted_at IS NULL
            SQL);
        $query->execute(['later_until' => $until, 'id' => $id, 'user' => $userId]);

        return $query->rowCount() > 0;
    }

    public function laterMany(string $userId, array $ids, string $until): int
    {
        [$placeholders, $params] = $this->captureIdParams($userId, $ids);
        if (!$placeholders) {
            return 0;
        }

        $params['later_until'] = $until;
        $sql = 'UPDATE catch_captures '
            . "SET status = 'later', later_until = :later_until, archived_at = NULL, "
            . 'updated_at = UTC_TIMESTAMP(6) '
            . "WHERE user_id = :user AND status = 'inbox' AND deleted_at IS NULL "
            . 'AND id IN (' . implode(',', $placeholders) . ')';
        $query = $this->db->prepare($sql);
        $query->execute($params);

        return $query->rowCount();
    }

    public function restore(string $id, string $userId): bool
    {
        $sql = <<<'SQL'
            UPDATE catch_captures c
            SET deleted_at = NULL,
                status = IF(
                    EXISTS(SELECT 1 FROM catch_capture_lists cl WHERE cl.capture_id = c.id),
                    'archived',
                    'inbox'
                ),
                archived_at = IF(
                    EXISTS(SELECT 1 FROM catch_capture_lists cl WHERE cl.capture_id = c.id),
                    COALESCE(c.archived_at, UTC_TIMESTAMP(6)),
                    NULL
                ),
                later_until = NULL,
                updated_at = UTC_TIMESTAMP(6)
            WHERE c.id = :id AND c.user_id = :user AND c.deleted_at IS NOT NULL
            SQL;
        $query = $this->db->prepare($sql);
        $query->execute(['id' => $id, 'user' => $userId]);

        return $query->rowCount() > 0;
    }

    private function releaseDueLater(string $userId): void
    {
        $query = $this->db->prepare(<<<'SQL'
            UPDATE catch_captures
            SET status = 'inbox',
                later_until = NULL,
                updated_at = UTC_TIMESTAMP(6)
            WHERE user_id = :user
                AND status = 'later'
                AND later_until <= UTC_TIMESTAMP(6)
                AND deleted_at IS NULL
            SQL);
        $query->execute(['user' => $userId]);
    }

    public function expiredTrashIds(string $userId, int $days = 30): array
    {
        $days = max(1, $days);
        $sql = <<<SQL
            SELECT id
            FROM catch_captures
            WHERE user_id = :user
                AND deleted_at IS NOT NULL
                AND deleted_at < DATE_SUB(UTC_TIMESTAMP(6), INTERVAL {$days} DAY)
            ORDER BY deleted_at
            LIMIT 200
            SQL;
        $query = $this->db->prepare($sql);
        $query->execute(['user' => $userId]);

        return array_values(array_filter($query->fetchAll(PDO::FETCH_COLUMN), 'is_string'));
    }

    public function updateEditableField(
        string $id,
        string $userId,
        string $field,
        string $value,
    ): ?array {
        $limits = [
            'title' => 500,
            'text' => 1_000_000,
            'extracted_text' => 1_000_000,
            'url' => 2_048,
        ];

        if (!isset($limits[$field])) {
            throw new InvalidArgumentException('This field cannot be edited.');
        }

        if (in_array($field, ['text', 'extracted_text'], true)) {
            $value = str_replace(["\r\n", "\r"], "\n", $value);
        }
        $value = trim($value);
        if (mb_strlen($value) > $limits[$field]) {
            $label = ucfirst(str_replace('_', ' ', $field));
            throw new InvalidArgumentException($label . ' is too long.');
        }

        if ($field === 'url' && $value !== '') {
            $scheme = strtolower((string) (parse_url($value, PHP_URL_SCHEME) ?? ''));
            if (!filter_var($value, FILTER_VALIDATE_URL) || !in_array($scheme, ['http', 'https'], true)) {
                throw new InvalidArgumentException('Enter a valid http or https URL.');
            }
        }

        $exists = $this->db->prepare(
            'SELECT 1 FROM catch_captures '
            . 'WHERE id = :id AND user_id = :user AND deleted_at IS NULL',
        );
        $exists->execute(['id' => $id, 'user' => $userId]);
        if (!$exists->fetchColumn()) {
            return null;
        }

        $sql = "UPDATE catch_captures SET {$field} = :value, updated_at = UTC_TIMESTAMP(6) "
            . 'WHERE id = :id AND user_id = :user AND deleted_at IS NULL';
        $query = $this->db->prepare($sql);
        $query->execute([
            'value' => $value === '' ? null : $value,
            'id' => $id,
            'user' => $userId,
        ]);

        return ['field' => $field, 'value' => $value];
    }

    public function attachmentStorageNames(string $userId, array $ids): array
    {
        [$placeholders, $params] = $this->captureIdParams($userId, $ids);
        if (!$placeholders) {
            return [];
        }

        $sql = 'SELECT a.storage_name FROM catch_attachments a '
            . 'JOIN catch_captures c ON c.id = a.capture_id '
            . 'WHERE c.user_id = :user AND c.deleted_at IS NOT NULL '
            . 'AND c.id IN (' . implode(',', $placeholders) . ')';
        $query = $this->db->prepare($sql);
        $query->execute($params);

        return array_values(array_filter($query->fetchAll(PDO::FETCH_COLUMN), 'is_string'));
    }

    /** @return array{deleted: int, storage_names: array<int, string>} */
    public function purgeMany(string $userId, array $ids): array
    {
        [$placeholders, $params] = $this->captureIdParams($userId, $ids);
        if (!$placeholders) {
            return ['deleted' => 0, 'storage_names' => []];
        }

        $ownsTransaction = !$this->db->inTransaction();
        if ($ownsTransaction) {
            $this->db->beginTransaction();
        }

        try {
            $filesSql = 'SELECT a.storage_name FROM catch_attachments a '
                . 'JOIN catch_captures c ON c.id = a.capture_id '
                . 'WHERE c.user_id = :user AND c.deleted_at IS NOT NULL '
                . 'AND c.id IN (' . implode(',', $placeholders) . ') FOR UPDATE';
            $files = $this->db->prepare($filesSql);
            $files->execute($params);
            $storageNames = array_values(array_filter(
                $files->fetchAll(PDO::FETCH_COLUMN),
                'is_string',
            ));

            $deleteSql = 'DELETE FROM catch_captures '
                . 'WHERE user_id = :user AND deleted_at IS NOT NULL '
                . 'AND id IN (' . implode(',', $placeholders) . ')';
            $delete = $this->db->prepare($deleteSql);
            $delete->execute($params);

            if ($ownsTransaction) {
                $this->db->commit();
            }

            return [
                'deleted' => $delete->rowCount(),
                'storage_names' => $storageNames,
            ];
        } catch (Throwable $error) {
            if ($ownsTransaction && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $error;
        }
    }

    public function findAttachment(string $id, string $userId): ?array
    {
        $sql = <<<'SQL'
            SELECT a.*
            FROM catch_attachments a
            JOIN catch_captures c ON c.id = a.capture_id
            WHERE a.id = :id AND c.user_id = :user
            LIMIT 1
            SQL;
        $query = $this->db->prepare($sql);
        $query->execute(['id' => $id, 'user' => $userId]);

        return $query->fetch() ?: null;
    }

    private function hydrate(array $capture): array
    {
        $metadata = json_decode((string) ($capture['metadata_json'] ?? ''), true);
        $capture['metadata'] = is_array($metadata) ? $metadata : [];

        return $capture;
    }

    private function withTags(array $captures, string $userId): array
    {
        foreach ($captures as &$capture) {
            $capture['tags'] = $this->tagsForCapture((string) $capture['id'], $userId);
        }
        unset($capture);

        return $captures;
    }

    private function tagsForCapture(string $id, string $userId): array
    {
        $sql = <<<'SQL'
            SELECT t.id, t.name
            FROM catch_tags t
            JOIN catch_capture_tags ct ON ct.tag_id = t.id
            JOIN catch_captures c ON c.id = ct.capture_id
            WHERE ct.capture_id = :capture AND c.user_id = :user
            ORDER BY t.name
            SQL;
        $query = $this->db->prepare($sql);
        $query->execute(['capture' => $id, 'user' => $userId]);

        return array_map(static function (array $tag): array {
            $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', (string) $tag['name'])
                ?: $tag['name'];
            $tag['slug'] = trim(
                (string) preg_replace('/[^a-z0-9]+/', '-', mb_strtolower((string) $ascii)),
                '-',
            ) ?: 'tag';
            $tag['url'] = '/tags/' . rawurlencode((string) $tag['id'])
                . '-' . $tag['slug'] . '/captures';

            return $tag;
        }, $query->fetchAll());
    }

    private function listsForCapture(string $id, string $userId): array
    {
        $sql = <<<'SQL'
            SELECT l.id, l.title
            FROM catch_lists l
            JOIN catch_capture_lists cl ON cl.list_id = l.id
            JOIN catch_captures c ON c.id = cl.capture_id
            WHERE cl.capture_id = :capture AND c.user_id = :user
            ORDER BY l.title
            SQL;
        $query = $this->db->prepare($sql);
        $query->execute(['capture' => $id, 'user' => $userId]);

        return array_map(static function (array $list): array {
            $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', (string) $list['title'])
                ?: $list['title'];
            $list['slug'] = trim(
                (string) preg_replace('/[^a-z0-9]+/', '-', mb_strtolower((string) $ascii)),
                '-',
            ) ?: 'list';
            $list['url'] = '/lists/' . rawurlencode((string) $list['id'])
                . '-' . $list['slug'] . '/captures';

            return $list;
        }, $query->fetchAll());
    }

    /** @return array{0: array<int, string>, 1: array<string, string>} */
    private function captureIdParams(string $userId, array $ids): array
    {
        $ids = array_values(array_unique(array_filter(
            $ids,
            static fn (mixed $id): bool => is_string($id)
                && preg_match('/^[0-9a-f-]{36}$/i', $id) === 1,
        )));
        $ids = array_slice($ids, 0, 200);

        $params = ['user' => $userId];
        $placeholders = [];
        foreach ($ids as $index => $id) {
            $key = 'capture_' . $index;
            $placeholders[] = ':' . $key;
            $params[$key] = $id;
        }

        return [$placeholders, $params];
    }
}
