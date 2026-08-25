<?php

declare(strict_types=1);

namespace Catch\Core;

use Catch\Controllers\Api\CaptureController as ApiCaptures;
use Catch\Controllers\Api\CliController as ApiCli;
use Catch\Controllers\Api\ExtensionController as ApiExtension;
use Catch\Controllers\Api\ShortcutController as ApiShortcut;
use Catch\Controllers\Technical\EmailImportController;
use Catch\Controllers\Web\AccountController;
use Catch\Controllers\Web\AuthController;
use Catch\Controllers\Web\CaptureController as WebCaptures;
use Catch\Controllers\Web\CliAuthController;
use Catch\Controllers\Web\ComingSoonController;
use Catch\Controllers\Web\DeviceController;
use Catch\Controllers\Web\HelpController;
use Catch\Controllers\Web\ListController;
use Catch\Controllers\Web\PairController;
use Catch\Controllers\Web\TagController;
use Catch\Repositories\CaptureRepository;
use Catch\Repositories\CliAuthRepository;
use Catch\Repositories\DeviceRepository;
use Catch\Repositories\EmailImportRepository;
use Catch\Repositories\EmailInboxRepository;
use Catch\Repositories\ListRepository;
use Catch\Repositories\TagRepository;
use Catch\Repositories\UserRepository;
use Catch\Services\AuthService;
use Catch\Services\CaptureDebugService;
use Catch\Services\CaptureService;
use Catch\Services\Csrf;
use Catch\Services\EmailContentSanitizer;
use Catch\Services\EmailImporter;
use Catch\Services\EmailImportRunner;
use Catch\Services\EmailMessageReader;
use Catch\Services\RemoteContentService;
use Catch\Services\SecretBox;
use Catch\Services\UploadService;
use Catch\Validation\CaptureValidator;

final class Application
{
    public function __construct(private readonly string $root)
    {
    }
    public function run(): void
    {
        $config = Config::load($this->root);
        date_default_timezone_set((string)$config->get('app.timezone', 'UTC'));
        $this->startSession($config);
        $f3 = \Base::instance();
        $f3->set('DEBUG', $config->bool('app.debug') ? 3 : 0);
        $f3->set('UI', $this->root . '/app/Views/');
        $db = new Database($config);
        $view = new View($this->root . '/app/Views');
        $f3->route('GET /health', fn () => \Catch\Http\Response::json(['status' => 'ok','database' => $db->available() ? 'connected' : 'unavailable','time' => gmdate(DATE_ATOM)]));
        if (!$db->available()) {
            $f3->route('GET /*', fn () => $view->render('errors/setup', ['title' => 'Setup required','configured' => $config->databaseConfigured()], 503));
            $f3->route('POST /*', fn () => \Catch\Http\Request::isShortcutApi() ? \Catch\Http\Response::shortcut('Database is not configured or unavailable.', '', 503) : \Catch\Http\Response::json(['error' => ['code' => 'database_unavailable','message' => 'Database is not configured or unavailable.']], 503));
            $f3->run();
            return;
        }
        $pdo = $db->connection();
        $users = new UserRepository($pdo);
        $devices = new DeviceRepository($pdo, new SecretBox($config));
        $captures = new CaptureRepository($pdo);
        $tags = new TagRepository($pdo);
        $lists = new ListRepository($pdo);
        $auth = new AuthService($users, $config);
        $csrf = new Csrf();
        $access = new AccessPolicy($config);
        $uploads = new UploadService($config, $this->root . '/storage/uploads');
        $remote = new RemoteContentService((int)$config->get('uploads.max_bytes', 15728640));
        $service = new CaptureService($db, new CaptureValidator(), $uploads, $remote);
        $captureDebug = new CaptureDebugService($pdo, $config);
        $currentUser = $auth->user();
        if ($currentUser && !$access->allowsUser($currentUser)) {
            $auth->logout();
            $currentUser = null;
        }$webDeviceId = null;
        if ($currentUser) {
            $webDevice = $devices->ensureWebDevice($currentUser['id'], isset($_SESSION['catch_web_device_id']) ? (string)$_SESSION['catch_web_device_id'] : null, (string)($_SERVER['HTTP_USER_AGENT'] ?? ''));
            $_SESSION['catch_web_device_id'] = $webDevice['id'];
            $webDeviceId = $webDevice['id'];
        }$path = (string)(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');
        $publicPaths = ['/coming-soon','/login','/auth/start','/auth/callback','/logout','/health','/pair','/share','/cli/authorize','/cron/import-mail'];
        $isApi = $path === '/api' || str_starts_with($path, '/api/');
        if ($access->isPrerelease() && !$currentUser && !$isApi && !in_array($path, $publicPaths, true)) {
            \Catch\Http\Response::redirect('/coming-soon');
        }
        $authController = new AuthController($view, $auth, $csrf, $access);
        $accountController = new AccountController(
            $view,
            $auth,
            $devices,
            new EmailInboxRepository($pdo, $config),
            $captures,
            $config,
            $csrf,
        );
        $comingSoon = new ComingSoonController($view, $auth, $csrf);
        $web = new WebCaptures($view, $auth, $captures, $tags, $lists, $service, $captureDebug, $csrf, $this->root . '/storage/uploads', $webDeviceId);
        $tagController = new TagController($view, $auth, $tags, $lists, $captures, $csrf);
        $listController = new ListController($view, $auth, $lists, $captures, $csrf);
        $deviceController = new DeviceController($view, $auth, $devices, $captures, $csrf, $config, $captureDebug);
        $pairController = new PairController($view, $auth, $devices, $csrf);
        $cliAuth = new CliAuthRepository($pdo);
        $cliAuthController = new CliAuthController($view, $auth, $cliAuth, $csrf);
        $help = new HelpController($view, $auth, $config);
        $emailImport = new EmailImportController(
            $config,
            new EmailImportRunner(
                new EmailImporter(
                    $config,
                    new EmailInboxRepository($pdo, $config),
                    new EmailImportRepository($pdo),
                    $service,
                    new EmailMessageReader(new EmailContentSanitizer()),
                    $this->root . '/storage/logs/import-mail.log',
                ),
                $this->root . '/storage/tmp/import-mail.lock',
            ),
            $this->root . '/storage/logs/import-mail.log',
        );
        $api = new ApiCaptures($devices, $captures, $tags, $service, $captureDebug);
        $apiShortcut = new ApiShortcut($devices, $config);
        $apiExtension = new ApiExtension($devices, $config);
        $apiCli = new ApiCli($cliAuth, $devices, $config);
        $f3->route('GET /', fn () => \Catch\Http\Response::redirect($auth->user() ? '/inbox' : ($access->isPrerelease() ? '/coming-soon' : '/login')));
        $f3->route('GET /coming-soon', [$comingSoon,'show']);
        $f3->route('GET /login', [$authController,'show']);
        $f3->route('GET /auth/start', [$authController,'start']);
        $f3->route('GET /auth/callback', [$authController,'callback']);
        $f3->route('POST /logout', [$authController,'logout']);
        $f3->route('GET /cron/import-mail', [$emailImport, 'run']);
        $f3->route('GET /profile', [$accountController, 'profile']);
        $f3->route('GET /settings', [$accountController, 'settings']);
        $f3->route('GET /settings/devices', [$accountController, 'devices']);
        $f3->route('GET /settings/email', [$accountController, 'email']);
        $f3->route('GET /settings/email/new', [$accountController, 'newEmail']);
        $f3->route('POST /settings/email', [$accountController, 'createEmail']);
        $f3->route('GET /settings/email/@inbox', [$accountController, 'showEmail']);
        $f3->route('POST /settings/email/@inbox/name', [$accountController, 'renameEmail']);
        $f3->route('GET /settings/email/@inbox/vcard', [$accountController, 'emailVcard']);
        $f3->route('POST /settings/email/@inbox/revoke', [$accountController, 'revokeEmail']);
        $f3->route('GET /inbox', [$web,'index']);
        $f3->route('GET /archive', [$web,'archiveIndex']);
        $f3->route('GET /trash', [$web,'trashIndex']);
        $f3->route('GET /share', [$web,'shareTarget']);
        $f3->route('POST /share', [$web,'shareTarget']);
        $f3->route('POST /captures', [$web,'create']);
        $f3->route('POST /captures/bulk-delete', [$web,'bulkDelete']);
        $f3->route('POST /captures/bulk-archive', [$web,'bulkArchive']);
        $f3->route('POST /captures/bulk-later', [$web,'bulkLater']);
        $f3->route('POST /captures/bulk-lists', [$listController,'bulkAssign']);
        $f3->route('GET /captures/poll', [$web, 'poll']);
        $f3->route('GET /captures/@id', [$web,'show']);
        $f3->route('POST /captures/@id', [$web,'update']);
        $f3->route('POST /captures/@id/preview', [$web, 'preview']);
        $f3->route('GET /attachments/@id', [$web,'attachment']);
        $f3->route('POST /captures/@id/archive', [$web,'archive']);
        $f3->route('POST /captures/@id/later', [$web,'later']);
        $f3->route('POST /captures/@id/restore', [$web,'restore']);
        $f3->route('POST /captures/@id/delete', [$web,'delete']);
        $f3->route('GET /tags', [$tagController,'index']);
        $f3->route('POST /tags', [$tagController,'create']);
        $f3->route('GET /tags/@tag/edit', [$tagController,'edit']);
        $f3->route('POST /tags/@tag/edit', [$tagController,'update']);
        $f3->route('POST /tags/@tag/delete', [$tagController,'delete']);
        $f3->route('GET /tags/@tag/captures', [$tagController,'captures']);
        $f3->route('POST /captures/@id/tags', [$tagController,'assign']);
        $f3->route('POST /captures/@id/tags/@tag/delete', [$tagController,'unassign']);
        $f3->route('GET /lists', [$listController,'index']);
        $f3->route('POST /lists', [$listController,'create']);
        $f3->route('GET /lists/@list/edit', [$listController,'edit']);
        $f3->route('POST /lists/@list/edit', [$listController,'update']);
        $f3->route('POST /lists/@list/delete', [$listController,'delete']);
        $f3->route('GET /lists/@list/captures', [$listController,'captures']);
        $f3->route('POST /captures/@id/lists', [$listController,'assign']);
        $f3->route('POST /captures/@id/lists/sync', [$listController,'sync']);
        $f3->route('POST /captures/@id/lists/@list/delete', [$listController,'unassign']);
        $f3->route('GET /devices', [$deviceController,'index']);
        $f3->route('GET /devices/new', [$deviceController,'new']);
        $f3->route('GET /devices/shortcuts', [$deviceController,'shortcuts']);
        $f3->route('POST /devices', [$deviceController,'create']);
        $f3->route('GET /devices/@device', [$deviceController,'show']);
        $f3->route('POST /devices/@device/rename', [$deviceController,'rename']);
        $f3->route('POST /devices/@device/pairing-code', [$deviceController,'createPairingCode']);
        $f3->route('GET /devices/@device/status', [$deviceController,'status']);
        $f3->route('POST /devices/@device/delete', [$deviceController,'delete']);
        $f3->route('GET /help', [$help,'show']);
        $f3->route('GET /pair', [$pairController,'show']);
        $f3->route('POST /pair', [$pairController,'approve']);
        $f3->route('GET /cli/authorize', [$cliAuthController,'show']);
        $f3->route('POST /cli/authorize', [$cliAuthController,'approve']);
        $f3->route('POST /api/devices/pair', [$apiShortcut,'pairDevice']);
        $f3->route('POST /api/shortcut/pair', [$apiShortcut,'pairShortcut']);
        $f3->route('POST /api/shortcut/captures', [$api,'createShortcut']);
        $f3->route('POST /api/extension/pairing-requests', [$apiExtension,'startPairing']);
        $f3->route('POST /api/extension/pairing-requests/@request/exchange', [$apiExtension,'exchangePairing']);
        $f3->route('GET /api/extension/connection', [$apiExtension,'connection']);
        $f3->route('POST /api/extension/disconnect', [$apiExtension,'disconnect']);
        $f3->route('POST /api/cli/auth/start', [$apiCli,'start']);
        $f3->route('POST /api/cli/auth/status/@login', [$apiCli,'status']);
        $f3->route('GET /api/cli/whoami', [$apiCli,'whoami']);
        $f3->route('POST /api/cli/logout', [$apiCli,'logout']);
        $f3->route('GET /api/v1/captures', [$api,'index']);
        $f3->route('POST /api/v1/captures', [$api,'create']);
        $f3->route('GET /api/v1/captures/@id', [$api,'show']);
        $f3->route('POST /api/v1/captures/@id/archive', [$api,'archive']);
        $f3->route('DELETE /api/v1/captures/@id', [$api,'delete']);
        $f3->set('ONERROR', function (\Base $f3) use ($view) {
            $error = (array)$f3->get('ERROR');
            $code = (int)($error['code'] ?? 500);
            $status = $code >= 400 && $code <= 599 ? $code : 500;
            @error_log(sprintf("[%s] HTTP %d: %s at %s:%s\n", gmdate(DATE_ATOM), $code, (string)($error['text'] ?? 'Unknown error'), (string)($error['trace'][0]['file'] ?? 'unknown'), (string)($error['trace'][0]['line'] ?? '?')), 3, $this->root . '/storage/logs/app.log');
            if (\Catch\Http\Request::isShortcutApi()) {
                \Catch\Http\Response::shortcut($code === 404 ? 'Endpoint not found.' : 'The request could not be completed.', '', $status);
            }if (\Catch\Http\Request::wantsJson()) {
                \Catch\Http\Response::json(['error' => ['code' => 'http_error','message' => $code === 404 ? 'Not found.' : 'The request could not be completed.']], $status);
            }$notFound = $code === 404;
            $view->render($notFound ? 'errors/404' : 'errors/error', ['title' => $notFound ? 'Not found' : 'Error'], $status);
        });
        $f3->run();
    }
    private function startSession(Config $config): void
    {
        ini_set('session.use_only_cookies', '1');
        ini_set('session.use_strict_mode', '1');
        session_name('catch_session');
        session_save_path($this->root . '/storage/sessions');
        session_set_cookie_params(['lifetime' => 0,'path' => '/','secure' => $config->bool('session.secure', true),'httponly' => true,'samesite' => 'Lax']);
        session_start();
    }
}
