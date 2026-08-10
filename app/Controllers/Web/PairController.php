<?php

declare(strict_types=1);

namespace Catch\Controllers\Web;

use Catch\Core\View;
use Catch\Http\Response;
use Catch\Repositories\DeviceRepository;
use Catch\Services\AuthService;
use Catch\Services\Csrf;

final class PairController
{
    public function __construct(private readonly View $view,private readonly AuthService $auth,private readonly DeviceRepository $devices,private readonly Csrf $csrf){}

    public function show(): void
    {
        $request=(string)($_GET['request']??'');$user=$this->auth->user();
        if(!$user){$_SESSION['after_login_path']=$this->returnPath($request);Response::redirect('/auth/start');}
        $pairing=$this->devices->extensionPairingRequest($request);
        $this->view->render('pair/index',['title'=>'Connect browser','user'=>$user,'pairing'=>$pairing,'request'=>$request,'csrf'=>$this->csrf->token()],$pairing?200:404);
    }

    public function approve(): void
    {
        $request=(string)($_POST['request']??'');$user=$this->auth->user();
        if(!$user){$_SESSION['after_login_path']=$this->returnPath($request);Response::redirect('/auth/start');}
        if(!$this->csrf->valid($_POST['_csrf']??null)){Response::redirect($this->returnPath($request));}
        try{$connected=$this->devices->approveExtensionPairingRequest($request,$user['id'],(string)($_SERVER['HTTP_USER_AGENT']??''));}catch(\Throwable){$connected=null;}
        $this->view->render('pair/index',['title'=>$connected?'Browser connected':'Connection failed','user'=>$user,'pairing'=>null,'request'=>$request,'connected'=>$connected,'csrf'=>$this->csrf->token()],$connected?200:410);
    }

    private function returnPath(string $request): string
    {
        return '/pair?'.http_build_query(['request'=>preg_match('/^[0-9a-f]{48}$/',$request)?$request:''],arg_separator:'&',encoding_type:PHP_QUERY_RFC3986);
    }
}
