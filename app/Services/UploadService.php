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
}
