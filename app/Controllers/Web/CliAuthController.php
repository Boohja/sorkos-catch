<?php

declare(strict_types=1);

namespace Catch\Controllers\Web;

use Catch\Core\View;
use Catch\Http\Response;
use Catch\Repositories\CliAuthRepository;
use Catch\Services\AuthService;
use Catch\Services\Csrf;

final class CliAuthController
{
    public function __construct(private readonly View $view, private readonly AuthService $auth, private readonly CliAuthRepository $cli, private readonly Csrf $csrf)
    {
    }

    public function show(): void
    {
        $login = (string) ($_GET['login'] ?? '');
        $user = $this->auth->user();
        if (!$user) {
            $_SESSION['after_login_path'] = $this->returnPath($login);
            Response::redirect('/auth/start');
        }
        $request = $this->cli->find($login);
        $this->view->render('cli/authorize', ['title' => 'Authorize Catch CLI', 'user' => $user, 'request' => $request, 'login' => $login, 'csrf' => $this->csrf->token()], $request ? 200 : 404);
    }

    public function approve(): void
    {
        $login = (string) ($_POST['login'] ?? '');
        $user = $this->auth->user();
        if (!$user) {
            $_SESSION['after_login_path'] = $this->returnPath($login);
            Response::redirect('/auth/start');
        }
        if (!$this->csrf->valid($_POST['_csrf'] ?? null)) {
            Response::redirect($this->returnPath($login));
        }
        try {
            $connected = $this->cli->approve($login, $user['id']);
        } catch (\Throwable) {
            $connected = null;
        }
        $this->view->render('cli/authorize', ['title' => $connected ? 'Catch CLI authorized' : 'Authorization failed', 'user' => $user, 'request' => null, 'login' => $login, 'connected' => $connected, 'csrf' => $this->csrf->token()], $connected ? 200 : 410);
    }

    private function returnPath(string $login): string
    {
        return '/cli/authorize?' . http_build_query(['login' => preg_match('/^[0-9a-f]{48}$/', $login) ? $login : ''], arg_separator: '&', encoding_type: PHP_QUERY_RFC3986);
    }
}
