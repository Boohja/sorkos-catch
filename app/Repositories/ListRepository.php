<?php

declare(strict_types=1);

namespace Catch\Repositories;

use Catch\Core\Id;
use PDO;

final class ListRepository
{
    public function __construct(private readonly PDO $db)
    {
    }

    public function list(string $userId): array
    {
        $sql = "SELECT l.*,
            (SELECT COUNT(*) FROM catch_capture_lists cl JOIN catch_captures c ON c.id=cl.capture_id WHERE cl.list_id=l.id AND c.deleted_at IS NULL) capture_count,
            (SELECT COALESCE(NULLIF(c.title,''),NULLIF(c.text,''),NULLIF(c.url,''),'Attachment') FROM catch_capture_lists cl JOIN catch_captures c ON c.id=cl.capture_id WHERE cl.list_id=l.id AND c.deleted_at IS NULL ORDER BY cl.assigned_at DESC,c.created_at DESC LIMIT 1) top_capture_title
            FROM catch_lists l WHERE l.user_id=:user ORDER BY l.updated_at DESC,l.title";
        $query = $this->db->prepare($sql);
        $query->execute(['user' => $userId]);
        return array_map([$this,'hydrate'], $query->fetchAll());
    }

    public function find(string $id, string $userId): ?array
    {
        $query = $this->db->prepare('SELECT l.*,(SELECT COUNT(*) FROM catch_capture_lists cl JOIN catch_captures c ON c.id=cl.capture_id WHERE cl.list_id=l.id AND c.deleted_at IS NULL) capture_count FROM catch_lists l WHERE l.id=:id AND l.user_id=:user');
        $query->execute(['id' => $id,'user' => $userId]);
        $list = $query->fetch();
        return $list ? $this->hydrate($list) : null;
    }

    public function create(string $userId, string $title): array
    {
        $title = $this->title($title);
        $id = Id::uuid();
        try {
            $this->db->prepare('INSERT INTO catch_lists (id,user_id,title,created_at,updated_at) VALUES (:id,:user,:title,UTC_TIMESTAMP(6),UTC_TIMESTAMP(6))')->execute(['id' => $id,'user' => $userId,'title' => $title]);
        } catch (\PDOException $error) {
            if ((string)$error->getCode() === '23000') {
                throw new \InvalidArgumentException('A list with this title already exists.');
            }throw $error;
        }
        return $this->find($id, $userId) ?? throw new \RuntimeException('The list could not be created.');
    }

    public function update(string $id, string $userId, string $title): ?array
    {
        $title = $this->title($title);
        try {
            $query = $this->db->prepare('UPDATE catch_lists SET title=:title,updated_at=UTC_TIMESTAMP(6) WHERE id=:id AND user_id=:user');
            $query->execute(['title' => $title,'id' => $id,'user' => $userId]);
        } catch (\PDOException $error) {
            if ((string)$error->getCode() === '23000') {
                throw new \InvalidArgumentException('A list with this title already exists.');
            }throw $error;
        }
        return $this->find($id, $userId);
    }

    public function delete(string $id, string $userId): bool
    {
        $query = $this->db->prepare('DELETE FROM catch_lists WHERE id=:id AND user_id=:user');
        $query->execute(['id' => $id,'user' => $userId]);
        return $query->rowCount() > 0;
    }

    public function assign(string $captureId, string $listId, string $userId): ?array
    {
        $list = $this->find($listId, $userId);
        if (!$list || !$this->ownsCapture($captureId, $userId)) {
            return null;
        }
        $ownsTransaction = !$this->db->inTransaction();
        if ($ownsTransaction) {
            $this->db->beginTransaction();
        }
        try {
            $query = $this->db->prepare('INSERT IGNORE INTO catch_capture_lists (capture_id,list_id,assigned_at) VALUES (:capture,:list,UTC_TIMESTAMP(6))');
            $query->execute(['capture' => $captureId,'list' => $listId]);
            $this->db->prepare("UPDATE catch_captures SET status='archived',archived_at=COALESCE(archived_at,UTC_TIMESTAMP(6)),deleted_at=NULL,updated_at=UTC_TIMESTAMP(6) WHERE id=:capture AND user_id=:user")->execute(['capture' => $captureId,'user' => $userId]);
            $this->db->prepare('UPDATE catch_lists SET updated_at=UTC_TIMESTAMP(6) WHERE id=:list')->execute(['list' => $listId]);
            if ($ownsTransaction) {
                $this->db->commit();
            }return $list;
        } catch (\Throwable $error) {
            if ($ownsTransaction && $this->db->inTransaction()) {
                $this->db->rollBack();
            }throw $error;
        }
    }

    public function unassign(string $captureId, string $listId, string $userId): ?array
    {
        $list = $this->find($listId, $userId);
        if (!$list || !$this->ownsCapture($captureId, $userId)) {
            return null;
        }
        $ownsTransaction = !$this->db->inTransaction();
        if ($ownsTransaction) {
            $this->db->beginTransaction();
        }
        try {
            $query = $this->db->prepare('DELETE FROM catch_capture_lists WHERE capture_id=:capture AND list_id=:list');
            $query->execute(['capture' => $captureId,'list' => $listId]);
            $this->db->prepare("UPDATE catch_captures c SET status='inbox',archived_at=NULL,updated_at=UTC_TIMESTAMP(6) WHERE c.id=:capture AND c.user_id=:user AND c.deleted_at IS NULL AND NOT EXISTS(SELECT 1 FROM catch_capture_lists cl WHERE cl.capture_id=c.id)")->execute(['capture' => $captureId,'user' => $userId]);
            if ($ownsTransaction) {
                $this->db->commit();
            }return $list;
        } catch (\Throwable $error) {
            if ($ownsTransaction && $this->db->inTransaction()) {
                $this->db->rollBack();
            }throw $error;
        }
    }

    public function syncAssignments(string $captureId, string $userId, array $listIds): ?array
    {
        if (!$this->ownsCapture($captureId, $userId)) {
            return null;
        }
        $listIds = array_values(array_unique(array_filter($listIds, static fn (mixed $id): bool => is_string($id) && preg_match('/^[0-9a-f-]{36}$/i', $id) === 1)));
        $owned = [];
        if ($listIds) {
            $params = ['user' => $userId];
            $slots = [];
            foreach ($listIds as $index => $id) {
                $key = 'list_' . $index;
                $slots[] = ':' . $key;
                $params[$key] = $id;
            }$query = $this->db->prepare('SELECT id FROM catch_lists WHERE user_id=:user AND id IN (' . implode(',', $slots) . ')');
            $query->execute($params);
            $owned = $query->fetchAll(PDO::FETCH_COLUMN);
            if (count($owned) !== count($listIds)) {
                return null;
            }
        }
        $ownsTransaction = !$this->db->inTransaction();
        if ($ownsTransaction) {
            $this->db->beginTransaction();
        }
        try {
            $this->db->prepare('DELETE FROM catch_capture_lists WHERE capture_id=:capture')->execute(['capture' => $captureId]);
            $insert = $this->db->prepare('INSERT INTO catch_capture_lists (capture_id,list_id,assigned_at) VALUES (:capture,:list,UTC_TIMESTAMP(6))');
            foreach ($owned as $listId) {
                $insert->execute(['capture' => $captureId,'list' => $listId]);
            }
            $status = $owned ? 'archived' : 'inbox';
            $this->db->prepare("UPDATE catch_captures SET status=:status,archived_at=CASE WHEN :status_check='archived' THEN COALESCE(archived_at,UTC_TIMESTAMP(6)) ELSE NULL END,updated_at=UTC_TIMESTAMP(6) WHERE id=:capture AND user_id=:user")->execute(['status' => $status,'status_check' => $status,'capture' => $captureId,'user' => $userId]);
            if ($ownsTransaction) {
                $this->db->commit();
            }
            $lists = [];
            foreach ($owned as $listId) {
                $list = $this->find((string)$listId, $userId);
                if ($list) {
                    $lists[] = $list;
                }
            }
            return ['lists' => $lists,'capture_status' => $status];
        } catch (\Throwable $error) {
            if ($ownsTransaction && $this->db->inTransaction()) {
                $this->db->rollBack();
            }throw $error;
        }
    }

    public function idFromRoute(string $value): string
    {
        return substr($value, 0, 36);
    }
    public function url(array $list): string
    {
        return '/lists/' . rawurlencode((string)$list['id']) . '-' . $this->slug((string)$list['title']) . '/captures';
    }
    private function ownsCapture(string $id, string $userId): bool
    {
        $query = $this->db->prepare('SELECT 1 FROM catch_captures WHERE id=:id AND user_id=:user AND deleted_at IS NULL');
        $query->execute(['id' => $id,'user' => $userId]);
        return (bool)$query->fetchColumn();
    }
    private function title(string $title): string
    {
        $title = trim(preg_replace('/\s+/u', ' ', $title) ?? '');
        if ($title === '' || mb_strlen($title) > 160) {
            throw new \InvalidArgumentException('Enter a list title of up to 160 characters.');
        }return $title;
    }
    private function slug(string $title): string
    {
        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $title) ?: $title;
        $slug = trim((string)preg_replace('/[^a-z0-9]+/', '-', mb_strtolower($ascii)), '-');
        return $slug ?: 'list';
    }
    private function hydrate(array $list): array
    {
        $list['slug'] = $this->slug((string)$list['title']);
        $list['url'] = $this->url($list);
        return $list;
    }
}
