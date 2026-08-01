<?php
declare(strict_types=1);
namespace Catch\Core;
use Catch\Controllers\Api\CaptureController as ApiCaptures;
use Catch\Controllers\Web\AuthController;
use Catch\Controllers\Web\ComingSoonController;
use Catch\Controllers\Web\CaptureController as WebCaptures;
use Catch\Controllers\Web\HelpController;
use Catch\Controllers\Web\DeviceController;
use Catch\Controllers\Api\ShortcutController as ApiShortcut;
use Catch\Repositories\CaptureRepository;
use Catch\Repositories\DeviceRepository;
use Catch\Repositories\UserRepository;
use Catch\Services\AuthService;
use Catch\Services\CaptureService;
use Catch\Services\Csrf;
use Catch\Services\SecretBox;
use Catch\Services\UploadService;
use Catch\Validation\CaptureValidator;

final class Application
{
    public function __construct(private readonly string $root){}
    public function run(): void
    {
        $config=Config::load($this->root);date_default_timezone_set((string)$config->get('app.timezone','UTC'));
        $this->startSession($config);$f3=\Base::instance();$f3->set('DEBUG',$config->bool('app.debug')?3:0);$f3->set('UI',$this->root.'/app/Views/');
        $db=new Database($config);$view=new View($this->root.'/app/Views');
        $f3->route('GET /health',fn()=>\Catch\Http\Response::json(['status'=>'ok','database'=>$db->available()?'connected':'unavailable','time'=>gmdate(DATE_ATOM)]));
        if(!$db->available()){$f3->route('GET /*',fn()=> $view->render('errors/setup',['title'=>'Einrichtung erforderlich','configured'=>$config->databaseConfigured()],503));$f3->route('POST /*',fn()=>\Catch\Http\Response::json(['error'=>['code'=>'database_unavailable','message'=>'Database is not configured or unavailable.']],503));$f3->run();return;}
        $pdo=$db->connection();$users=new UserRepository($pdo);$devices=new DeviceRepository($pdo,new SecretBox($config));$captures=new CaptureRepository($pdo);$auth=new AuthService($users,$config);$csrf=new Csrf();$access=new AccessPolicy($config);$service=new CaptureService($db,new CaptureValidator(),new UploadService($config,$this->root.'/storage/uploads'));
        $currentUser=$auth->user();if($currentUser&&!$access->allowsUser($currentUser)){$auth->logout();$currentUser=null;}$path=(string)(parse_url($_SERVER['REQUEST_URI']??'/',PHP_URL_PATH)?:'/');$publicPaths=['/coming-soon','/login','/auth/start','/auth/callback','/logout','/health'];$isApi=$path==='/api'||str_starts_with($path,'/api/');if($access->isPrerelease()&&!$currentUser&&!$isApi&&!in_array($path,$publicPaths,true)){\Catch\Http\Response::redirect('/coming-soon');}
        $authController=new AuthController($view,$auth,$csrf,$access);$comingSoon=new ComingSoonController($view,$auth,$csrf);$web=new WebCaptures($view,$auth,$captures,$service,$csrf);$deviceController=new DeviceController($view,$auth,$devices,$csrf,$config);$help=new HelpController($view,$auth,$config);$api=new ApiCaptures($devices,$captures,$service);$apiShortcut=new ApiShortcut($devices,$config);
        $f3->route('GET /',fn()=>\Catch\Http\Response::redirect($auth->user()?'/inbox':($access->isPrerelease()?'/coming-soon':'/login')));
        $f3->route('GET /coming-soon',[$comingSoon,'show']);
        $f3->route('GET /login',[$authController,'show']);$f3->route('GET /auth/start',[$authController,'start']);$f3->route('GET /auth/callback',[$authController,'callback']);$f3->route('POST /logout',[$authController,'logout']);
        $f3->route('GET /inbox',[$web,'index']);$f3->route('POST /captures',[$web,'create']);$f3->route('GET /captures/@id',[$web,'show']);$f3->route('POST /captures/@id/archive',[$web,'archive']);$f3->route('POST /captures/@id/delete',[$web,'delete']);
        $f3->route('GET /devices',[$deviceController,'index']);$f3->route('GET /devices/new',[$deviceController,'new']);$f3->route('POST /devices',[$deviceController,'create']);$f3->route('GET /devices/@device',[$deviceController,'show']);$f3->route('POST /devices/@device/pairing-code',[$deviceController,'createPairingCode']);$f3->route('GET /devices/@device/status',[$deviceController,'status']);$f3->route('POST /devices/@device/delete',[$deviceController,'delete']);$f3->route('GET /help',[$help,'show']);
        $f3->route('POST /api/devices/pair',[$apiShortcut,'pair']);$f3->route('POST /api/shortcut/pair',[$apiShortcut,'pair']);$f3->route('POST /api/shortcut/captures',[$api,'create']);
        $f3->route('GET /api/v1/captures',[$api,'index']);$f3->route('POST /api/v1/captures',[$api,'create']);$f3->route('GET /api/v1/captures/@id',[$api,'show']);$f3->route('POST /api/v1/captures/@id/archive',[$api,'archive']);$f3->route('DELETE /api/v1/captures/@id',[$api,'delete']);
        $f3->set('ONERROR',function(\Base $f3)use($view){$error=(array)$f3->get('ERROR');$code=(int)($error['code']??500);error_log(sprintf("[%s] HTTP %d: %s at %s:%s\n",gmdate(DATE_ATOM),$code,(string)($error['text']??'Unknown error'),(string)($error['trace'][0]['file']??'unknown'),(string)($error['trace'][0]['line']??'?')),3,$this->root.'/storage/logs/app.log');if(\Catch\Http\Request::wantsJson())\Catch\Http\Response::json(['error'=>['code'=>'http_error','message'=>$code===404?'Not found.':'The request could not be completed.']],$code);$view->render('errors/404',['title'=>$code===404?'Nicht gefunden':'Fehler'],max($code,400));});
        $f3->run();
    }
    private function startSession(Config $config): void
    {
        ini_set('session.use_only_cookies','1');ini_set('session.use_strict_mode','1');
        session_name('catch_session');session_save_path($this->root.'/storage/sessions');session_set_cookie_params(['lifetime'=>0,'path'=>'/','secure'=>$config->bool('session.secure',true),'httponly'=>true,'samesite'=>'Lax']);session_start();
    }
}
