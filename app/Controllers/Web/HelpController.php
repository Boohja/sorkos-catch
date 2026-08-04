<?php
declare(strict_types=1);
namespace Catch\Controllers\Web;
use Catch\Core\Config;
use Catch\Core\View;
use Catch\Services\AuthService;

final class HelpController
{
    public function __construct(private readonly View $view,private readonly AuthService $auth,private readonly Config $config){}
    public function show(): void{$this->view->render('help/index',['title'=>'Shortcut help','user'=>$this->auth->user(),'appUrl'=>rtrim((string)$this->config->get('app.url'),'/')]);}
}
