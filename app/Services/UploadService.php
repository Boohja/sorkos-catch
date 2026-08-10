<?php
declare(strict_types=1);
namespace Catch\Services;
use Catch\Core\Config;
use Catch\Core\Id;
use RuntimeException;

final class UploadService
{
    private array $allowed;
    public function __construct(private readonly Config $config,private readonly string $path)
    {
        $this->allowed=array_filter(array_map('trim',explode(',',(string)$config->get('uploads.allowed_mime','image/jpeg,image/png,image/webp,application/pdf,text/plain'))));
    }
    public function store(array $file,string $captureId): array
    {
        if (($file['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK) throw new RuntimeException('The attachment could not be uploaded.');
        $size=(int)$file['size']; if ($size>(int)$this->config->get('uploads.max_bytes',15728640)) throw new RuntimeException('The attachment exceeds the upload limit.');
        $mime=(new \finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']) ?: 'application/octet-stream';
        if (!in_array($mime,$this->allowed,true)) throw new RuntimeException('This attachment type is not allowed.');
        $storageName=Id::uuid(); $directory=$this->path.'/'.gmdate('Y/m');
        if (!is_dir($directory) && !mkdir($directory,0700,true) && !is_dir($directory)) throw new RuntimeException('Upload storage is unavailable.');
        $target=$directory.'/'.$storageName;
        if (!move_uploaded_file($file['tmp_name'],$target)) throw new RuntimeException('The attachment could not be stored.');
        chmod($target,0600); $width=$height=null;
        if (str_starts_with($mime,'image/')) { $image=@getimagesize($target); if ($image) {[$width,$height]=$image;} }
        return ['id'=>Id::uuid(),'capture_id'=>$captureId,'original_name'=>basename((string)$file['name']),'storage_name'=>gmdate('Y/m').'/'.$storageName,'mime_type'=>$mime,'size_bytes'=>$size,'width'=>$width,'height'=>$height,'checksum'=>hash_file('sha256',$target)];
    }

    /** @return array{mime:string,size:int} */
    public function inspectUnknownAttachment(array $file): array
    {
        if (($file['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK) throw new RuntimeException('The attachment could not be uploaded.');
        $path=(string)($file['tmp_name']??'');$actualSize=$path!==''&&is_file($path)?filesize($path):false;
        $size=$actualSize===false?(int)($file['size']??0):(int)$actualSize;
        if($size<=0)throw new RuntimeException('Empty attachments are not accepted.');
        if($size>$this->maxBytes())throw new RuntimeException('The attachment exceeds the upload limit.');
        $mime=$path!==''?(new \finfo(FILEINFO_MIME_TYPE))->file($path):false;$mime=$mime?:'application/octet-stream';
        $safeKind=str_starts_with($mime,'image/')||$mime==='application/pdf';
        if(!$safeKind||!in_array($mime,$this->allowed,true))throw new RuntimeException('Unknown captures accept only configured image formats and PDF files.');
        return ['mime'=>$mime,'size'=>$size];
    }

    public function maxBytes(): int
    {
        return (int)$this->config->get('uploads.max_bytes',15728640);
    }

    public function storeContents(string $contents,string $name,string $mime,string $captureId): array
    {
        $size=strlen($contents);if($size===0||$size>(int)$this->config->get('uploads.max_bytes',15728640))throw new RuntimeException('The remote attachment exceeds the upload limit.');
        if(!in_array($mime,$this->allowed,true))throw new RuntimeException('This remote attachment type is not allowed.');
        $storageName=Id::uuid();$relative=gmdate('Y/m').'/'.$storageName;$directory=$this->path.'/'.gmdate('Y/m');
        if(!is_dir($directory)&&!mkdir($directory,0700,true)&&!is_dir($directory))throw new RuntimeException('Upload storage is unavailable.');
        $target=$this->path.'/'.$relative;if(file_put_contents($target,$contents,LOCK_EX)===false)throw new RuntimeException('The remote attachment could not be stored.');
        chmod($target,0600);$width=$height=null;if(str_starts_with($mime,'image/')){$image=@getimagesize($target);if($image){[$width,$height]=$image;}}
        return ['id'=>Id::uuid(),'capture_id'=>$captureId,'original_name'=>basename($name),'storage_name'=>$relative,'mime_type'=>$mime,'size_bytes'=>$size,'width'=>$width,'height'=>$height,'checksum'=>hash('sha256',$contents)];
    }
}
