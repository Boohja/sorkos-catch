<?php

declare(strict_types=1);

namespace Catch\Repositories;

use Catch\Core\Id;
use PDO;

final class TagRepository
{
    public function __construct(private readonly PDO $db)
    {
    }

    public function list(string $userId): array
    {
        $q = $this->db->prepare('SELECT t.*,(SELECT COUNT(*) FROM catch_capture_tags ct JOIN catch_captures c ON c.id=ct.capture_id WHERE ct.tag_id=t.id AND c.deleted_at IS NULL) capture_count FROM catch_tags t WHERE t.user_id=:user ORDER BY t.name');
        $q->execute(['user' => $userId]);
        return array_map([$this,'hydrate'], $q->fetchAll());
    }
    public function find(string $id, string $userId): ?array
    {
        $q = $this->db->prepare('SELECT t.*,(SELECT COUNT(*) FROM catch_capture_tags ct JOIN catch_captures c ON c.id=ct.capture_id WHERE ct.tag_id=t.id AND c.deleted_at IS NULL) capture_count FROM catch_tags t WHERE t.id=:id AND t.user_id=:user');
        $q->execute(['id' => $id,'user' => $userId]);
        $tag = $q->fetch();
        return $tag ? $this->hydrate($tag) : null;
    }
    public function create(string $userId, string $name): array
    {
        $name = $this->name($name);
        $id = Id::uuid();
        try {
            $this->db->prepare('INSERT INTO catch_tags (id,user_id,name,created_at) VALUES (:id,:user,:name,UTC_TIMESTAMP(6))')->execute(['id' => $id,'user' => $userId,'name' => $name]);
        } catch (\PDOException $e) {
            if ((string)$e->getCode() === '23000') {
                throw new \InvalidArgumentException('A tag with this name already exists.');
            }throw $e;
        }
        return $this->find($id, $userId) ?? throw new \RuntimeException('The tag could not be created.');
    }
    public function update(string $id, string $userId, string $name): ?array
    {
        $name = $this->name($name);
        try {
            $q = $this->db->prepare('UPDATE catch_tags SET name=:name WHERE id=:id AND user_id=:user');
            $q->execute(['name' => $name,'id' => $id,'user' => $userId]);
        } catch (\PDOException $e) {
            if ((string)$e->getCode() === '23000') {
                throw new \InvalidArgumentException('A tag with this name already exists.');
            }throw $e;
        }
        return $this->find($id, $userId);
    }
    public function delete(string $id, string $userId): bool
    {
        $q = $this->db->prepare('DELETE FROM catch_tags WHERE id=:id AND user_id=:user');
        $q->execute(['id' => $id,'user' => $userId]);
        return $q->rowCount() > 0;
    }
    public function assign(string $captureId, string $tagId, string $userId): ?array
    {
        $tag = $this->find($tagId, $userId);
        if (!$tag || !$this->ownsCapture($captureId, $userId)) {
            return null;
        }
        $q = $this->db->prepare('INSERT IGNORE INTO catch_capture_tags (capture_id,tag_id) VALUES (:capture,:tag)');
        $q->execute(['capture' => $captureId,'tag' => $tagId]);
        return $tag;
    }
    public function assignByName(string $captureId, string $name, string $userId): ?array
    {
        if (!$this->ownsCapture($captureId, $userId)) {
            return null;
        }
        $name = $this->name($name);
        $tag = $this->findByName($name, $userId);
        if (!$tag) {
            try {
                $tag = $this->create($userId, $name);
            } catch (\InvalidArgumentException) {
                $tag = $this->findByName($name, $userId);
                if (!$tag) {
                    throw new \InvalidArgumentException('The tag could not be created.');
                }
            }
        }
        return $this->assign($captureId, (string)$tag['id'], $userId);
    }
    public function unassign(string $captureId, string $tagId, string $userId): ?array
    {
        $tag = $this->find($tagId, $userId);
        if (!$tag || !$this->ownsCapture($captureId, $userId)) {
            return null;
        }
        $q = $this->db->prepare('DELETE FROM catch_capture_tags WHERE capture_id=:capture AND tag_id=:tag');
        $q->execute(['capture' => $captureId,'tag' => $tagId]);
        return $tag;
    }
    public function forCapture(string $captureId, string $userId): array
    {
        $q = $this->db->prepare('SELECT t.* FROM catch_tags t JOIN catch_capture_tags ct ON ct.tag_id=t.id JOIN catch_captures c ON c.id=ct.capture_id WHERE ct.capture_id=:capture AND c.user_id=:user ORDER BY t.name');
        $q->execute(['capture' => $captureId,'user' => $userId]);
        return array_map([$this,'hydrate'], $q->fetchAll());
    }
    public function url(array $tag): string
    {
        return '/tags/' . rawurlencode((string)$tag['id']) . '-' . $this->slug((string)$tag['name']) . '/captures';
    }
    public function idFromRoute(string $value): string
    {
        return substr($value, 0, 36);
    }
    private function ownsCapture(string $id, string $userId): bool
    {
        $q = $this->db->prepare('SELECT 1 FROM catch_captures WHERE id=:id AND user_id=:user');
        $q->execute(['id' => $id,'user' => $userId]);
        return (bool)$q->fetchColumn();
    }
    private function findByName(string $name, string $userId): ?array
    {
        $q = $this->db->prepare('SELECT t.*,(SELECT COUNT(*) FROM catch_capture_tags ct JOIN catch_captures c ON c.id=ct.capture_id WHERE ct.tag_id=t.id AND c.deleted_at IS NULL) capture_count FROM catch_tags t WHERE t.name=:name AND t.user_id=:user LIMIT 1');
        $q->execute(['name' => $name,'user' => $userId]);
        $tag = $q->fetch();
        return $tag ? $this->hydrate($tag) : null;
    }
    private function name(string $name): string
    {
        $name = trim(preg_replace('/\s+/u', ' ', $name) ?? '');
        if ($name === '' || mb_strlen($name) > 100) {
            throw new \InvalidArgumentException('Enter a tag name of up to 100 characters.');
        }return $name;
    }
    private function slug(string $name): string
    {
        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name) ?: $name;
        $slug = trim((string)preg_replace('/[^a-z0-9]+/', '-', mb_strtolower($ascii)), '-');
        return $slug ?: 'tag';
    }
    private function hydrate(array $tag): array
    {
        $tag['slug'] = $this->slug((string)$tag['name']);
        $tag['url'] = $this->url($tag);
        return $tag;
    }
}
