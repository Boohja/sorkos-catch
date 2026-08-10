<?php
declare(strict_types=1);
namespace Catch\Controllers\Web;
use Catch\Core\Id;
use Catch\Core\View;
use Catch\Http\Response;
use Catch\Repositories\CaptureRepository;
use Catch\Repositories\TagRepository;
use Catch\Services\AuthService;
use Catch\Services\CaptureService;
use Catch\Services\Csrf;

final class CaptureController
{
    public function __construct(private readonly View $view,private readonly AuthService $auth,private readonly CaptureRepository $captures,private readonly TagRepository $tags,private readonly CaptureService $service,private readonly Csrf $csrf,private readonly string $uploadsPath,private readonly ?string $webDeviceId=null) {}
    private function user(): array { $user=$this->auth->user(); if(!$user) Response::redirect('/login'); return $user; }
    public function index(): void
    {
        $user=$this->user();$requestedStatus=(string)($_GET['status']??'inbox');$status=in_array($requestedStatus,['inbox','archived'],true)?$requestedStatus:'inbox';
        $this->view->render('captures/index',['title'=>'Inbox','user'=>$user,'captures'=>$this->captures->list($user['id'],$status),'status'=>$status,'csrf'=>$this->csrf->token()]);
    }
    public function create(): void
    {
        $user=$this->user(); if(!$this->csrf->valid($_POST['_csrf']??null)) Response::redirect('/inbox?error=csrf');
        $_POST['client_capture_id']=$_POST['client_capture_id']??Id::uuid(); $_POST['source']='web';
        if(($_POST['type']??'')==='url'){$_POST['url']=$_POST['text']??null;$_POST['text']=null;}
        try{$result=$this->service->create($user['id'],$_POST,$_FILES,$this->webDeviceId); Response::redirect('/captures/'.$result['capture']['id']);}
        catch(\Throwable $e){$_SESSION['flash_error']=$e instanceof \InvalidArgumentException?'Add content, a URL, or a file.':'The capture could not be saved.'; Response::redirect('/inbox');}
    }
    public function show(\Base $f3,array $params): void
    {
        $user=$this->user(); $capture=$this->captures->find((string)$params['id'],$user['id']);
        if(!$capture){$this->view->render('errors/404',['title'=>'Not found','user'=>$user],404);return;}
        foreach($capture['attachments'] as &$attachment){$stored=$this->captures->findAttachment($attachment['id'],$user['id']);$attachment['available']=$stored&&is_file($this->uploadsPath.'/'.$stored['storage_name']);}unset($attachment);
        $this->view->render('captures/show',['title'=>$capture['title']?:'Capture','user'=>$user,'capture'=>$capture,'availableTags'=>$this->tags->list($user['id']),'csrf'=>$this->csrf->token()]);
    }
    public function attachment(\Base $f3,array $params): never
    {
        $user=$this->user();$attachment=$this->captures->findAttachment((string)$params['id'],$user['id']);
        if(!$attachment){http_response_code(404);exit;}
        $base=realpath($this->uploadsPath);$path=realpath($this->uploadsPath.'/'.$attachment['storage_name']);
        if($base===false||$path===false||!str_starts_with($path,$base.DIRECTORY_SEPARATOR)||!is_file($path)){http_response_code(404);exit;}
        header('Content-Type: '.$attachment['mime_type']);header('Content-Length: '.filesize($path));header('X-Content-Type-Options: nosniff');header('Cache-Control: private, max-age=3600');
        $disposition=str_starts_with((string)$attachment['mime_type'],'image/')?'inline':'attachment';header('Content-Disposition: '.$disposition.'; filename="'.addcslashes((string)$attachment['original_name'],"\"\\").'"');
        readfile($path);exit;
    }
    public function archive(\Base $f3,array $params): never { $user=$this->user(); if($this->csrf->valid($_POST['_csrf']??null))$this->captures->setStatus((string)$params['id'],$user['id'],'archived'); Response::redirect('/inbox'); }
    public function delete(\Base $f3,array $params): never { $user=$this->user(); if($this->csrf->valid($_POST['_csrf']??null))$this->captures->setStatus((string)$params['id'],$user['id'],'deleted'); Response::redirect('/inbox'); }
}
