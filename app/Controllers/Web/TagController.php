<?php

declare(strict_types=1);

namespace Catch\Controllers\Web;

use Catch\Core\View;
use Catch\Http\Response;
use Catch\Repositories\ActionRepository;
use Catch\Repositories\CaptureRepository;
use Catch\Repositories\TagRepository;
use Catch\Services\ActionExecutor;
use Catch\Services\AuthService;
use Catch\Services\Csrf;

final class TagController
{
    public function __construct(private readonly View $view, private readonly AuthService $auth, private readonly TagRepository $tags, private readonly CaptureRepository $captures, private readonly ActionRepository $actionRepository, private readonly ActionExecutor $actions, private readonly Csrf $csrf)
    {
    }
    private function user(): array
    {
        $u = $this->auth->user();
        if (!$u) {
            Response::redirect('/login');
        }return $u;
    }
    public function index(): void
    {
        $u = $this->user();
        $form = is_array($_SESSION['tag_form'] ?? null) && empty($_SESSION['tag_form']['tag_id'])
            ? $_SESSION['tag_form']
            : [];
        $form += ['tag_id' => '', 'name' => '', 'action_id' => ''];
        $this->view->render('tags/index', ['title' => 'Tags','user' => $u,'tags' => $this->tags->list($u['id']),'actions' => $this->actionRepository->all($u['id']),'tagForm' => $form,'csrf' => $this->csrf->token()]);
    }
    public function create(): never
    {
        $u = $this->user();
        $this->guard();
        $form = ['tag_id' => '', 'name' => (string)($_POST['name'] ?? ''), 'action_id' => (string)($_POST['action_id'] ?? '')];
        try {
            $this->tags->create($u['id'], $form['name'], $form['action_id']);
            unset($_SESSION['tag_form']);
            $_SESSION['flash_success'] = 'Tag created.';
        } catch (\InvalidArgumentException $e) {
            $_SESSION['tag_form'] = $form;
            $_SESSION['flash_error'] = $e->getMessage();
        }Response::redirect('/tags');
    }
    public function edit(\Base $f, array $p): void
    {
        $u = $this->user();
        $tag = $this->get($p, $u['id']);
        if (!$tag) {
            $this->view->render('errors/404', ['title' => 'Not found','user' => $u], 404);
            return;
        }
        $form = is_array($_SESSION['tag_form'] ?? null) && ($_SESSION['tag_form']['tag_id'] ?? null) === $tag['id']
            ? $_SESSION['tag_form']
            : [];
        $form += ['tag_id' => $tag['id'], 'name' => $tag['name'], 'action_id' => (string)($tag['action_id'] ?? '')];
        $this->view->render('tags/edit', ['title' => 'Edit ' . $tag['name'],'user' => $u,'tag' => $tag,'actions' => $this->actionRepository->all($u['id']),'tagForm' => $form,'csrf' => $this->csrf->token()]);
    }
    public function update(\Base $f, array $p): never
    {
        $u = $this->user();
        $this->guard();
        $tagId = $this->tags->idFromRoute((string)$p['tag']);
        $form = ['tag_id' => $tagId, 'name' => (string)($_POST['name'] ?? ''), 'action_id' => (string)($_POST['action_id'] ?? '')];
        try {
            $this->tags->update($tagId, $u['id'], $form['name'], $form['action_id']);
            unset($_SESSION['tag_form']);
            $_SESSION['flash_success'] = 'Tag updated.';
        } catch (\InvalidArgumentException $e) {
            $_SESSION['tag_form'] = $form;
            $_SESSION['flash_error'] = $e->getMessage();
            Response::redirect('/tags/' . rawurlencode((string)$p['tag']) . '/edit');
        }Response::redirect('/tags');
    }
    public function delete(\Base $f, array $p): never
    {
        $u = $this->user();
        $this->guard();
        $this->tags->delete($this->tags->idFromRoute((string)$p['tag']), $u['id']);
        Response::redirect('/tags');
    }
    public function captures(\Base $f, array $p): void
    {
        $u = $this->user();
        $tag = $this->get($p, $u['id']);
        if (!$tag) {
            $this->view->render('errors/404', ['title' => 'Not found','user' => $u], 404);
            return;
        }$s = in_array(($r = (string)($_GET['status'] ?? 'inbox')), ['inbox','archived'], true) ? $r : 'inbox';
        $this->view->render('tags/captures', ['title' => $tag['name'],'user' => $u,'tag' => $tag,'captures' => $this->captures->listByTag($u['id'], $tag['id'], $s),'status' => $s,'enableCaptureActionMenu' => true,'csrf' => $this->csrf->token()]);
    }
    public function assign(\Base $f, array $p): never
    {
        $u = $this->user();
        $this->guard(true);
        try {
            $name = trim((string)($_POST['name'] ?? ''));
            $tag = $name !== ''
                ? $this->tags->assignByName((string)$p['id'], $name, $u['id'])
                : $this->tags->assign((string)$p['id'], (string)($_POST['tag_id'] ?? ''), $u['id']);
        } catch (\InvalidArgumentException $e) {
            Response::json(['error' => $e->getMessage()], 422);
        }
        if (!$tag) {
            Response::json(['error' => 'Capture or tag not found.'], 404);
        }
        try {
            $this->actions->executeForAssignedTag($tag, (string)$p['id'], $u['id']);
        } catch (\Throwable $error) {
            Response::json(['error' => trim($error->getMessage()) ?: 'The tag action could not be completed.'], 422);
        }
        Response::json(['tag' => $tag]);
    }
    public function unassign(\Base $f, array $p): never
    {
        $u = $this->user();
        $this->guard(true);
        $tag = $this->tags->unassign((string)$p['id'], $this->tags->idFromRoute((string)$p['tag']), $u['id']);
        if (!$tag) {
            Response::json(['error' => 'Capture or tag not found.'], 404);
        }Response::json(['tag' => $tag]);
    }
    private function get(array $p, string $u): ?array
    {
        return $this->tags->find($this->tags->idFromRoute((string)$p['tag']), $u);
    }
    private function guard(bool $json = false): void
    {
        if ($this->csrf->valid($_POST['_csrf'] ?? null)) {
            return;
        }if ($json) {
            Response::json(['error' => 'Your session expired. Refresh and try again.'], 419);
        }Response::redirect('/tags?error=csrf');
    }
}
