<?php
declare(strict_types=1);
namespace Catch\Services;
use Catch\Core\Database;
use Catch\Core\Id;
use Catch\Repositories\CaptureRepository;
use Catch\Validation\CaptureValidator;
use InvalidArgumentException;

final class CaptureService
{
    public function __construct(private readonly Database $database,private readonly CaptureValidator $validator,private readonly UploadService $uploads) {}
    public function create(string $userId,array $input,array $files=[]): array
    {
        $errors=$this->validator->validate($input);
        if ($errors) throw new InvalidArgumentException(json_encode($errors));
        $repo=new CaptureRepository($this->database->connection());
        if ($existing=$repo->findByClientId((string)$input['client_capture_id'],$userId)) return ['capture'=>$existing,'created'=>false];
        $id=Id::uuid();
        $data=['id'=>$id,'user_id'=>$userId,'client_capture_id'=>(string)$input['client_capture_id'],'type'=>(string)$input['type'],'title'=>$this->nullable($input['title']??null),'text'=>$this->nullable($input['text']??null),'url'=>$this->nullable($input['url']??null),'extracted_text'=>$this->nullable($input['extracted_text']??null),'source'=>(string)($input['source']??'web'),'metadata_json'=>json_encode($input['metadata']??[],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)];
        $stored=[];
        $this->database->transaction(function() use($repo,$data,$files,$id,&$stored) {
            $repo->insert($data);
            foreach ($this->normalizeFiles($files) as $file) { $attachment=$this->uploads->store($file,$id); $repo->addAttachment($attachment); $stored[]=$attachment['storage_name']; }
        });
        return ['capture'=>$repo->find($id,$userId),'created'=>true];
    }
    private function nullable(mixed $value): ?string { $value=trim((string)$value); return $value===''?null:$value; }
    private function normalizeFiles(array $files): array
    {
        $field=$files['attachments']??$files['attachment']??null; if (!$field || !isset($field['name'])) return [];
        if (!is_array($field['name'])) return [$field]; $result=[];
        foreach ($field['name'] as $i=>$name) if (($field['error'][$i]??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_NO_FILE) $result[]=['name'=>$name,'type'=>$field['type'][$i]??'','tmp_name'=>$field['tmp_name'][$i],'error'=>$field['error'][$i],'size'=>$field['size'][$i]];
        return $result;
    }
}
