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
        $this->view->render('auth/login',['title'=>'Log in','csrf'=>$this->csrf->token(),'configured'=>$this->auth->configured(),'error'=>$_SESSION['auth_error']??null]);unset($_SESSION['auth_error']);
    }
    public function start(): never
    {
        try{Response::redirect($this->auth->authorizationUrl());}catch(\Throwable $error){$this->logFailure('authorization start',$error);$_SESSION['auth_error']='Sorkos Login is not configured yet.';Response::redirect('/login');}
    }
    public function callback(): never
    {
        if(($_GET['error']??'')==='access_denied'){$_SESSION['auth_error']='Login was cancelled.';Response::redirect('/login');}
        try{$user=$this->auth->complete((string)($_GET['code']??''),(string)($_GET['state']??''));if(!$this->access->allowsSorkosUserId((string)($user['sorkos_user_id']??''))){$this->auth->logout();Response::redirect('/coming-soon?access=denied');}$return=$this->returnPath();$this->auth->establishSession($user['id']);Response::redirect($return);}catch(\Throwable $error){$this->logFailure('authorization callback',$error);$_SESSION['auth_error']='Login could not be completed.';Response::redirect('/login');}
    }
    public function logout(): never
    {
        $url='/login';if($this->csrf->valid($_POST['_csrf']??null)){$url=$this->auth->configured()?$this->auth->logoutUrl():'/login';$this->auth->logout();}Response::redirect($url);
    }

    private function logFailure(string $stage,\Throwable $error): void
    {
        @error_log(sprintf("[%s] Sorkos %s failed: %s\n",gmdate(DATE_ATOM),$stage,$error->getMessage()),3,dirname(__DIR__,3).'/storage/logs/auth.log');
    }

    private function returnPath(): string
    {
        $path=(string)($_SESSION['after_login_path']??'/inbox');unset($_SESSION['after_login_path']);
        return str_starts_with($path,'/')&&!str_starts_with($path,'//')?$path:'/inbox';
    }
}
