<?php
declare(strict_types=1);

namespace Catch\Controllers\Web;

use Catch\Core\Config;
use Catch\Core\View;
use Catch\Http\Response;
use Catch\Repositories\DeviceRepository;
use Catch\Services\AuthService;
use Catch\Services\Csrf;

final class DeviceController
{
    public function __construct(private readonly View $view,private readonly AuthService $auth,private readonly DeviceRepository $devices,private readonly Csrf $csrf,private readonly Config $config){}
    private function user(): array{$user=$this->auth->user();if(!$user)Response::redirect('/login');return $user;}
    private function id(string $routeValue): string{return substr($routeValue,0,36);}
    private function url(array $device): string{$slug=strtolower(trim(preg_replace('/[^a-z0-9]+/i','-',iconv('UTF-8','ASCII//TRANSLIT',$device['name'])?:$device['name']),'-'));return '/devices/'.$device['id'].'-'.($slug?:'device');}

    public function index(): void
    {
        $user=$this->user();$devices=$this->devices->all($user['id']);foreach($devices as &$device)$device['url']=$this->url($device);
        $this->view->render('devices/index',['title'=>'Devices','user'=>$user,'devices'=>$devices,'csrf'=>$this->csrf->token()]);
    }

    public function new(): void{$this->view->render('devices/new',['title'=>'Add device','user'=>$this->user(),'error'=>$_SESSION['device_error']??null,'csrf'=>$this->csrf->token()]);unset($_SESSION['device_error']);}

    public function create(): never
    {
        $user=$this->user();if(!$this->csrf->valid($_POST['_csrf']??null))Response::redirect('/devices/new');
        $kind=(string)($_POST['kind']??'');$platform=(string)($_POST['platform']??'');$name=trim((string)($_POST['name']??''));
        if($kind!=='mobile'||!in_array($platform,['ios','ipados'],true)||$name===''){$_SESSION['device_error']='Choose Mobile and either iOS or iPadOS, then enter a device name.';Response::redirect('/devices/new');}
        $device=$this->devices->create($user['id'],$name,$kind,$platform);Response::redirect($this->url($device));
    }

    public function show(\Base $f3,array $params): void
    {
        $user=$this->user();$device=$this->devices->find($this->id((string)$params['device']),$user['id']);if(!$device)Response::redirect('/devices');
        $appUrl=rtrim((string)$this->config->get('app.url'),'/');
        $this->view->render('devices/show',['title'=>$device['name'],'user'=>$user,'device'=>$device,'csrf'=>$this->csrf->token(),'deviceUrl'=>$this->url($device),'shortcutUrl'=>$appUrl.'/assets/shortcuts/Catch%20Setup.shortcut']);
    }

    public function createPairingCode(\Base $f3,array $params): never
    {
        $user=$this->user();$id=$this->id((string)$params['device']);if(!$this->csrf->valid($_POST['_csrf']??null))Response::redirect('/devices');
        $device=$this->devices->find($id,$user['id']);if(!$device)Response::redirect('/devices');
        $this->devices->createPairingCode($id,$user['id']);Response::redirect($this->url($device));
    }

    public function status(\Base $f3,array $params): never
    {
        $user=$this->user();$status=$this->devices->status($this->id((string)$params['device']),$user['id']);if(!$status)Response::json(['error'=>['code'=>'not_found','message'=>'Device not found.']],404);Response::json($status);
    }

    public function delete(\Base $f3,array $params): never
    {
        $user=$this->user();if(!$this->csrf->valid($_POST['_csrf']??null))Response::redirect('/devices');$this->devices->delete($this->id((string)$params['device']),$user['id']);Response::redirect('/devices');
    }
}
