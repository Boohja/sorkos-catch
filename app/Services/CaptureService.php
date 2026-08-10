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
    public function __construct(private readonly Database $database,private readonly CaptureValidator $validator,private readonly UploadService $uploads,private readonly ?RemoteContentService $remote=null) {}
    public function create(string $userId,array $input,array $files=[],?string $deviceId=null): array
    {
        $metadata=is_array($input['metadata']??null)?$input['metadata']:[];
        $source=(string)($input['source']??'web');
        $url=trim((string)($input['url']??''));
        if(empty(trim((string)($input['title']??'')))&&$url!==''&&($input['type']??'')==='url'){
            $input['title']=$this->remote?->pageTitle($url)?:$this->nullable($metadata['link_text']??null);
        }
        if($url!==''&&empty($metadata['source_url']))$metadata['source_url']=$url;
        if(!empty($metadata['source_url'])&&empty($metadata['source_domain']))$metadata['source_domain']=(string)(parse_url((string)$metadata['source_url'],PHP_URL_HOST)??'');
        if(!empty($metadata['source_url'])&&$metadata['source_url']===$url&&empty($metadata['source_title'])&&!empty($input['title']))$metadata['source_title']=(string)$input['title'];
        if(empty($metadata['capture_method']))$metadata['capture_method']=$source==='browser-extension'?(str_contains((string)($metadata['browser_context']??''),'context-menu')?'browser-extension-context-menu':'browser-extension'):$source;
        $input['metadata']=$metadata;
        $errors=$this->validator->validate($input);
        if ($errors) throw new InvalidArgumentException(json_encode($errors));
        $repo=new CaptureRepository($this->database->connection());
        if ($existing=$repo->findByClientId((string)$input['client_capture_id'],$userId)) return ['capture'=>$existing,'created'=>false];
        $remoteImage=!empty($input['remote_attachment_url'])&&$this->remote?$this->remote->image((string)$input['remote_attachment_url']):null;
        if(!empty($input['remote_attachment_url'])&&!$remoteImage)throw new InvalidArgumentException(json_encode(['attachment'=>$this->remote?->lastError()?:'The image could not be retrieved from the source page.']));
        if($remoteImage&&empty(trim((string)($input['title']??''))))$input['title']=$remoteImage['name'];
        $id=Id::uuid();
        $data=['id'=>$id,'user_id'=>$userId,'device_id'=>$deviceId,'client_capture_id'=>(string)$input['client_capture_id'],'type'=>(string)$input['type'],'title'=>$this->nullable($input['title']??null),'text'=>$this->nullable($input['text']??null),'url'=>$this->nullable($input['url']??null),'extracted_text'=>$this->nullable($input['extracted_text']??null),'source'=>$source,'metadata_json'=>json_encode($input['metadata'],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)];
        $stored=[];
        $this->database->transaction(function() use($repo,&$data,$files,$id,&$stored,$remoteImage) {
            $data['catch_number']=$repo->nextCatchNumber($data['user_id']);
            $repo->insert($data);
            foreach ($this->normalizeFiles($files) as $file) { $attachment=$this->uploads->store($file,$id); $repo->addAttachment($attachment); $stored[]=$attachment['storage_name']; }
            if($remoteImage){$attachment=$this->uploads->storeContents($remoteImage['contents'],$remoteImage['name'],$remoteImage['type'],$id);$repo->addAttachment($attachment);$stored[]=$attachment['storage_name'];}
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
