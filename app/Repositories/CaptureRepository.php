<?php
declare(strict_types=1);
namespace Catch\Repositories;
use PDO;

final class CaptureRepository
{
    public function __construct(private readonly PDO $db) {}
    public function list(string $userId, string $status='inbox', int $limit=100): array
    {
        $query=$this->db->prepare('SELECT c.*, (SELECT COUNT(*) FROM catch_attachments a WHERE a.capture_id=c.id) attachment_count FROM catch_captures c WHERE user_id=:user AND status=:status ORDER BY created_at DESC LIMIT '.max(1,min($limit,200)));
        $query->execute(['user'=>$userId,'status'=>$status]);
        return $query->fetchAll();
    }
    public function find(string $id,string $userId): ?array
    {
        $query=$this->db->prepare('SELECT * FROM catch_captures WHERE id=:id AND user_id=:user LIMIT 1');
        $query->execute(['id'=>$id,'user'=>$userId]);
        $capture=$query->fetch() ?: null;
        if ($capture) {
            $a=$this->db->prepare('SELECT id,original_name,mime_type,size_bytes,width,height,created_at FROM catch_attachments WHERE capture_id=:id ORDER BY created_at');
            $a->execute(['id'=>$id]); $capture['attachments']=$a->fetchAll();
        }
        return $capture;
    }
    public function findByClientId(string $clientId,string $userId): ?array
    {
        $query=$this->db->prepare('SELECT * FROM catch_captures WHERE client_capture_id=:client AND user_id=:user LIMIT 1');
        $query->execute(['client'=>$clientId,'user'=>$userId]); return $query->fetch() ?: null;
    }
    public function insert(array $data): void
    {
        $sql='INSERT INTO catch_captures (id,user_id,client_capture_id,type,title,text,url,extracted_text,source,metadata_json,status,created_at,updated_at) VALUES (:id,:user_id,:client_capture_id,:type,:title,:text,:url,:extracted_text,:source,:metadata_json,\'inbox\',UTC_TIMESTAMP(6),UTC_TIMESTAMP(6))';
        $this->db->prepare($sql)->execute($data);
    }
    public function addAttachment(array $data): void
    {
        $this->db->prepare('INSERT INTO catch_attachments (id,capture_id,original_name,storage_name,mime_type,size_bytes,width,height,checksum,created_at) VALUES (:id,:capture_id,:original_name,:storage_name,:mime_type,:size_bytes,:width,:height,:checksum,UTC_TIMESTAMP(6))')->execute($data);
    }
    public function setStatus(string $id,string $userId,string $status): bool
    {
        $field=$status==='archived'?'archived_at':'deleted_at';
        $query=$this->db->prepare("UPDATE catch_captures SET status=:status,$field=UTC_TIMESTAMP(6),updated_at=UTC_TIMESTAMP(6) WHERE id=:id AND user_id=:user");
        $query->execute(['status'=>$status,'id'=>$id,'user'=>$userId]); return $query->rowCount()>0;
    }
}
