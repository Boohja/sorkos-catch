<?php
declare(strict_types=1);
namespace Catch\Controllers\Web;
use Catch\Core\AccessPolicy;
use Catch\Core\View;
use Catch\Http\Response;
use Catch\Services\AuthService;
use Catch\Services\Csrf;

final class AuthController
{
    public function __construct(private readonly View $view,private readonly AuthService $auth,private readonly Csrf $csrf,private readonly AccessPolicy $access) {}
    public function show(): void
    {
        if($this->auth->user())Response::redirect('/inbox');
        $this->view->render('auth/login',['title'=>'Anmelden','csrf'=>$this->csrf->token(),'configured'=>$this->auth->configured(),'error'=>$_SESSION['auth_error']??null]);unset($_SESSION['auth_error']);
    }
    public function start(): never
    {
        try{Response::redirect($this->auth->authorizationUrl());}catch(\Throwable $error){$this->logFailure('authorization start',$error);$_SESSION['auth_error']='Sorkos Login ist noch nicht konfiguriert.';Response::redirect('/login');}
    }
    public function callback(): never
    {
        if(($_GET['error']??'')==='access_denied'){$_SESSION['auth_error']='Die Anmeldung wurde abgebrochen.';Response::redirect('/login');}
        try{$user=$this->auth->complete((string)($_GET['code']??''),(string)($_GET['state']??''));if(!$this->access->allowsSorkosUserId((string)($user['sorkos_user_id']??''))){$this->auth->logout();Response::redirect('/coming-soon?access=denied');}$this->auth->establishSession($user['id']);Response::redirect('/inbox');}catch(\Throwable $error){$this->logFailure('authorization callback',$error);$_SESSION['auth_error']='Die Anmeldung konnte nicht abgeschlossen werden.';Response::redirect('/login');}
    }
    public function logout(): never
    {
        $url='/login';if($this->csrf->valid($_POST['_csrf']??null)){$url=$this->auth->configured()?$this->auth->logoutUrl():'/login';$this->auth->logout();}Response::redirect($url);
    }

    private function logFailure(string $stage,\Throwable $error): void
    {
        error_log(sprintf("[%s] Sorkos %s failed: %s\n",gmdate(DATE_ATOM),$stage,$error->getMessage()),3,dirname(__DIR__,3).'/storage/logs/auth.log');
    }
}
