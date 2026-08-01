<?php
declare(strict_types=1);

namespace Catch\Repositories;

use Catch\Core\Id;
use Catch\Services\SecretBox;
use PDO;

final class DeviceRepository
{
    public function __construct(private readonly PDO $db,private readonly SecretBox $secrets){}

    public function all(string $userId): array
    {
        $query=$this->db->prepare('SELECT d.*,t.last_used_at FROM catch_devices d LEFT JOIN catch_device_tokens t ON t.device_id=d.id WHERE d.user_id=:user ORDER BY d.created_at DESC');
        $query->execute(['user'=>$userId]);return $query->fetchAll();
    }

    public function find(string $deviceId,string $userId): ?array
    {
        $query=$this->db->prepare('SELECT d.*,p.code_encrypted,t.last_used_at FROM catch_devices d LEFT JOIN catch_device_pairing_codes p ON p.device_id=d.id LEFT JOIN catch_device_tokens t ON t.device_id=d.id WHERE d.id=:id AND d.user_id=:user LIMIT 1');
        $query->execute(['id'=>$deviceId,'user'=>$userId]);$device=$query->fetch()?:null;
        if($device&&$device['code_encrypted'])$device['pairing_code']=$this->secrets->decrypt($device['code_encrypted']);
        if($device)unset($device['code_encrypted']);
        return $device;
    }

    public function create(string $userId,string $name,string $kind,string $platform): array
    {
        $id=Id::uuid();$name=mb_substr(trim($name),0,120);
        $query=$this->db->prepare('INSERT INTO catch_devices (id,user_id,name,kind,platform,status,created_at) VALUES (:id,:user,:name,:kind,:platform,\'setup\',UTC_TIMESTAMP(6))');
        $query->execute(['id'=>$id,'user'=>$userId,'name'=>$name,'kind'=>$kind,'platform'=>$platform]);
        return ['id'=>$id,'name'=>$name,'kind'=>$kind,'platform'=>$platform,'status'=>'setup'];
    }

    public function createPairingCode(string $deviceId,string $userId): ?string
    {
        $device=$this->find($deviceId,$userId);if(!$device||$device['status']!=='setup')return null;
        [$plain,$display]=$this->newCode();
        $this->db->beginTransaction();
        try{
            $this->db->prepare('DELETE FROM catch_device_pairing_codes WHERE device_id=:device')->execute(['device'=>$deviceId]);
            $this->db->prepare('INSERT INTO catch_device_pairing_codes (device_id,code_hash,code_encrypted,created_at) VALUES (:device,:hash,:encrypted,UTC_TIMESTAMP(6))')->execute(['device'=>$deviceId,'hash'=>hash('sha256',$plain),'encrypted'=>$this->secrets->encrypt($display)]);
            $this->db->commit();return $display;
        }catch(\Throwable $error){if($this->db->inTransaction())$this->db->rollBack();throw $error;}
    }

    public function delete(string $deviceId,string $userId): bool
    {
        $query=$this->db->prepare('DELETE FROM catch_devices WHERE id=:id AND user_id=:user');
        $query->execute(['id'=>$deviceId,'user'=>$userId]);return $query->rowCount()===1;
    }

    public function status(string $deviceId,string $userId): ?array
    {
        $query=$this->db->prepare('SELECT status,connected_at,last_seen_at FROM catch_devices WHERE id=:id AND user_id=:user LIMIT 1');
        $query->execute(['id'=>$deviceId,'user'=>$userId]);return $query->fetch()?:null;
    }

    public function pair(string $code): ?array
    {
        $normalized=strtoupper(preg_replace('/[^A-Z0-9]/','',$code)??'');if(strlen($normalized)!==16)return null;
        $this->db->beginTransaction();
        try{
            $query=$this->db->prepare('SELECT d.id,d.user_id FROM catch_device_pairing_codes p JOIN catch_devices d ON d.id=p.device_id WHERE p.code_hash=:hash AND d.status=\'setup\' LIMIT 1 FOR UPDATE');
            $query->execute(['hash'=>hash('sha256',$normalized)]);$device=$query->fetch()?:null;
            if(!$device){$this->db->rollBack();return null;}
            $token='catch_device_'.rtrim(strtr(base64_encode(random_bytes(32)),'+/','-_'),'=');$tokenId=Id::uuid();
            $this->db->prepare('INSERT INTO catch_device_tokens (id,device_id,token_hash,token_scope,created_at) VALUES (:id,:device,:hash,\'capture:write\',UTC_TIMESTAMP(6))')->execute(['id'=>$tokenId,'device'=>$device['id'],'hash'=>hash('sha256',$token)]);
            $this->db->prepare('DELETE FROM catch_device_pairing_codes WHERE device_id=:device')->execute(['device'=>$device['id']]);
            $this->db->prepare('UPDATE catch_devices SET status=\'connected\',connected_at=UTC_TIMESTAMP(6) WHERE id=:device')->execute(['device'=>$device['id']]);
            $this->db->commit();return ['device_token'=>$token,'device_id'=>$device['id']];
        }catch(\Throwable $error){if($this->db->inTransaction())$this->db->rollBack();throw $error;}
    }

    public function userForToken(string $token,string $requiredScope='capture:write'): ?array
    {
        if(!str_starts_with($token,'catch_device_'))return null;
        $query=$this->db->prepare('SELECT u.id,u.email,u.display_name,d.id device_id,t.id token_id,t.token_scope FROM catch_device_tokens t JOIN catch_devices d ON d.id=t.device_id JOIN catch_users u ON u.id=d.user_id WHERE t.token_hash=:hash LIMIT 1');
        $query->execute(['hash'=>hash('sha256',$token)]);$user=$query->fetch()?:null;
        if($user&&$requiredScope==='full'&&$user['token_scope']!=='full')return null;
        if($user){$this->db->prepare('UPDATE catch_device_tokens SET last_used_at=UTC_TIMESTAMP(6) WHERE id=:token')->execute(['token'=>$user['token_id']]);$this->db->prepare('UPDATE catch_devices SET last_seen_at=UTC_TIMESTAMP(6) WHERE id=:device')->execute(['device'=>$user['device_id']]);}
        return $user;
    }

    private function newCode(): array
    {
        $alphabet='ABCDEFGHJKLMNPQRSTUVWXYZ23456789';$plain='';
        for($i=0;$i<16;$i++)$plain.=$alphabet[random_int(0,strlen($alphabet)-1)];
        return [$plain,implode('-',str_split($plain,4))];
    }
}
