<?php

declare(strict_types=1);

namespace Catch\Controllers\Web;

use Catch\Core\View;
use Catch\Services\AuthService;
use Catch\Services\Csrf;

final class ComingSoonController
{
    public function __construct(
        private readonly View $view,
        private readonly AuthService $auth,
        private readonly Csrf $csrf,
    ) {}

    public function show(): void
    {
        header('Cache-Control: no-store');
        $this->view->render('coming-soon', [
            'title' => 'Coming soon',
            'user' => $this->auth->user(),
            'csrf' => $this->csrf->token(),
            'configured' => $this->auth->configured(),
        ]);
    }
}
