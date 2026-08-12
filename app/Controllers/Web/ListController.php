<?php

declare(strict_types=1);

namespace Catch\Controllers\Web;

use Catch\Core\View;
use Catch\Http\Response;
use Catch\Repositories\CaptureRepository;
use Catch\Repositories\ListRepository;
use Catch\Services\AuthService;
use Catch\Services\Csrf;

final class ListController
{
    public function __construct(private readonly View $view, private readonly AuthService $auth, private readonly ListRepository $lists, private readonly CaptureRepository $captures, private readonly Csrf $csrf)
    {
    }
    private function user(): array
    {
        $user = $this->auth->user();
        if (!$user) {
            Response::redirect('/login');
        }return $user;
    }
    public function index(): void
    {
        $user = $this->user();
        $this->view->render('lists/index', ['title' => 'Lists','user' => $user,'lists' => $this->lists->list($user['id']),'csrf' => $this->csrf->token()]);
    }
    public function create(): never
    {
        $user = $this->user();
        $this->guard();
        try {
            $this->lists->create($user['id'], (string)($_POST['title'] ?? ''));
        } catch (\InvalidArgumentException $error) {
            $_SESSION['flash_error'] = $error->getMessage();
        }Response::redirect('/lists');
    }
    public function edit(\Base $f3, array $params): void
    {
        $user = $this->user();
        $list = $this->get($params, $user['id']);
        if (!$list) {
            $this->view->render('errors/404', ['title' => 'Not found','user' => $user], 404);
            return;
        }$this->view->render('lists/edit', ['title' => 'Edit ' . $list['title'],'user' => $user,'list' => $list,'csrf' => $this->csrf->token()]);
    }
    public function update(\Base $f3, array $params): never
    {
        $user = $this->user();
        $this->guard();
        try {
            $this->lists->update($this->lists->idFromRoute((string)$params['list']), $user['id'], (string)($_POST['title'] ?? ''));
        } catch (\InvalidArgumentException $error) {
            $_SESSION['flash_error'] = $error->getMessage();
            Response::redirect('/lists/' . rawurlencode((string)$params['list']) . '/edit');
        }Response::redirect('/lists');
    }
    public function delete(\Base $f3, array $params): never
    {
        $user = $this->user();
        $this->guard();
        $this->lists->delete($this->lists->idFromRoute((string)$params['list']), $user['id']);
        Response::redirect('/lists');
    }
    public function captures(\Base $f3, array $params): void
    {
        $user = $this->user();
        $list = $this->get($params, $user['id']);
        if (!$list) {
            $this->view->render('errors/404', ['title' => 'Not found','user' => $user], 404);
            return;
        }$this->view->render('lists/captures', ['title' => $list['title'],'user' => $user,'list' => $list,'captures' => $this->captures->listByList($user['id'], $list['id']),'status' => 'archived','availableLists' => $this->lists->list($user['id']),'enableListDialog' => true,'enableCaptureActionMenu' => true,'csrf' => $this->csrf->token()]);
    }
    public function assign(\Base $f3, array $params): never
    {
        $user = $this->user();
        $this->guard(true);
        $list = $this->lists->assign((string)$params['id'], (string)($_POST['list_id'] ?? ''), $user['id']);
        if (!$list) {
            Response::json(['error' => 'Capture or list not found.'], 404);
        }Response::json(['list' => $list,'capture_status' => 'archived']);
    }
    public function unassign(\Base $f3, array $params): never
    {
        $user = $this->user();
        $this->guard(true);
        $captureId = (string)$params['id'];
        $list = $this->lists->unassign($captureId, $this->lists->idFromRoute((string)$params['list']), $user['id']);
        if (!$list) {
            Response::json(['error' => 'Capture or list not found.'], 404);
        }$capture = $this->captures->find($captureId, $user['id']);
        Response::json(['list' => $list,'capture_status' => $capture['status'] ?? 'inbox']);
    }
    public function sync(\Base $f3, array $params): never
    {
        $user = $this->user();
        $this->guard(true);
        $result = $this->lists->syncAssignments((string)$params['id'], $user['id'], is_array($_POST['list_ids'] ?? null) ? $_POST['list_ids'] : []);
        if ($result === null) {
            Response::json(['error' => 'Capture or list not found.'], 404);
        }Response::json($result);
    }
    public function bulkAssign(): never
    {
        $user = $this->user();
        $this->guard(true);
        $captureIds = is_array($_POST['capture_ids'] ?? null) ? $_POST['capture_ids'] : [];
        $listIds = is_array($_POST['list_ids'] ?? null) ? $_POST['list_ids'] : [];
        $assigned = $this->lists->assignMany($user['id'], $captureIds, $listIds);
        if ($assigned === null) {
            Response::json(['error' => 'A selected capture or list could not be found.'], 404);
        }
        Response::json(['assigned' => $assigned, 'capture_status' => 'archived']);
    }
    private function get(array $params, string $userId): ?array
    {
        return $this->lists->find($this->lists->idFromRoute((string)$params['list']), $userId);
    }
    private function guard(bool $json = false): void
    {
        if ($this->csrf->valid($_POST['_csrf'] ?? null)) {
            return;
        }if ($json) {
            Response::json(['error' => 'Your session expired. Refresh and try again.'], 419);
        }Response::redirect('/lists?error=csrf');
    }
}
