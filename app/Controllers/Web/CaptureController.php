<?php
declare(strict_types=1);
namespace Catch\Controllers\Web;
use Catch\Core\Id;
use Catch\Core\View;
use Catch\Http\Response;
use Catch\Repositories\CaptureRepository;
use Catch\Services\AuthService;
use Catch\Services\CaptureService;
use Catch\Services\Csrf;

final class CaptureController
{
    public function __construct(private readonly View $view,private readonly AuthService $auth,private readonly CaptureRepository $captures,private readonly CaptureService $service,private readonly Csrf $csrf) {}
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
        try{$result=$this->service->create($user['id'],$_POST,$_FILES); Response::redirect('/captures/'.$result['capture']['id']);}
        catch(\Throwable $e){$_SESSION['flash_error']=$e instanceof \InvalidArgumentException?'Bitte Inhalt, URL oder Datei angeben.':'Der Capture konnte nicht gespeichert werden.'; Response::redirect('/inbox');}
    }
    public function show(\Base $f3,array $params): void
    {
        $user=$this->user(); $capture=$this->captures->find((string)$params['id'],$user['id']);
        if(!$capture){$this->view->render('errors/404',['title'=>'Nicht gefunden','user'=>$user],404);return;}
        $this->view->render('captures/show',['title'=>$capture['title']?:'Capture','user'=>$user,'capture'=>$capture,'csrf'=>$this->csrf->token()]);
    }
    public function archive(\Base $f3,array $params): never { $user=$this->user(); if($this->csrf->valid($_POST['_csrf']??null))$this->captures->setStatus((string)$params['id'],$user['id'],'archived'); Response::redirect('/inbox'); }
    public function delete(\Base $f3,array $params): never { $user=$this->user(); if($this->csrf->valid($_POST['_csrf']??null))$this->captures->setStatus((string)$params['id'],$user['id'],'deleted'); Response::redirect('/inbox'); }
}
