<?php

declare(strict_types=1);

namespace Catch\Controllers\Web;

use Catch\Core\Config;
use Catch\Core\View;
use Catch\Http\Request;
use Catch\Http\Response;
use Catch\Repositories\ActionRepository;
use Catch\Repositories\TargetRepository;
use Catch\Services\ActionExecutor;
use Catch\Services\AuthService;
use Catch\Services\Csrf;
use InvalidArgumentException;
use Throwable;

final class AutomationController
{
    public function __construct(
        private readonly View $view,
        private readonly AuthService $auth,
        private readonly TargetRepository $targets,
        private readonly ActionRepository $actions,
        private readonly ActionExecutor $executor,
        private readonly Config $config,
        private readonly Csrf $csrf,
    ) {
    }

    public function targets(): void
    {
        $user = $this->user();
        $this->view->render('account/settings', [
            'title' => 'Settings · Targets',
            'user' => $user,
            'settingsTab' => 'targets',
            'targets' => $this->targets->all($user['id']),
            'prsmBaseUrl' => rtrim((string) $this->config->get('prsm.base_url', ''), '/'),
            'csrf' => $this->csrf->token(),
        ]);
    }

    public function createTarget(): never
    {
        $user = $this->user();
        if (!$this->csrf->valid($_POST['_csrf'] ?? null)) {
            Response::redirect('/settings/targets');
        }

        try {
            $this->targets->createPrsmTask(
                $user['id'],
                (string) ($_POST['token'] ?? ''),
            );
            $_SESSION['flash_success'] = 'Prsm target created.';
        } catch (InvalidArgumentException $error) {
            $_SESSION['flash_error'] = $error->getMessage();
        } catch (Throwable) {
            $_SESSION['flash_error'] = 'The target could not be created.';
        }

        Response::redirect('/settings/targets');
    }

    public function deleteTarget(\Base $f3, array $params): never
    {
        $user = $this->user();
        $id = substr((string) ($params['target'] ?? ''), 0, 36);
        if ($this->csrf->valid($_POST['_csrf'] ?? null)) {
            if ($this->actions->usesTarget($id, $user['id'])) {
                $_SESSION['flash_error'] = 'Delete the actions that use this target first.';
            } elseif ($this->targets->delete($id, $user['id'])) {
                $_SESSION['flash_success'] = 'Target deleted.';
            }
        }

        Response::redirect('/settings/targets');
    }

    public function actions(): void
    {
        $user = $this->user();
        $this->view->render('account/settings', [
            'title' => 'Settings · Actions',
            'user' => $user,
            'settingsTab' => 'actions',
            'targets' => $this->targets->all($user['id']),
            'actions' => $this->actions->all($user['id']),
            'csrf' => $this->csrf->token(),
        ]);
    }

    public function createAction(): never
    {
        $user = $this->user();
        if (!$this->csrf->valid($_POST['_csrf'] ?? null)) {
            Response::redirect('/settings/actions');
        }

        try {
            $targetId = substr((string) ($_POST['target_id'] ?? ''), 0, 36);
            if (!$this->targets->find($targetId, $user['id'])) {
                throw new InvalidArgumentException('Choose a target for this action.');
            }
            $steps = [[
                'type' => 'send_capture_to_target',
                'config' => ['target_id' => $targetId],
            ]];
            $tag = trim((string) ($_POST['tag'] ?? ''));
            if ($tag !== '') {
                $steps[] = ['type' => 'add_tag', 'config' => ['name' => $tag]];
            }
            $after = (string) ($_POST['after'] ?? 'keep');
            if ($after === 'archive') {
                $steps[] = ['type' => 'archive_capture', 'config' => []];
            } elseif ($after === 'trash') {
                $steps[] = ['type' => 'delete_capture', 'config' => []];
            } elseif ($after !== 'keep') {
                throw new InvalidArgumentException('Choose what happens after the target step.');
            }

            $this->actions->create($user['id'], (string) ($_POST['name'] ?? ''), $steps);
            $_SESSION['flash_success'] = 'Action created.';
        } catch (InvalidArgumentException $error) {
            $_SESSION['flash_error'] = $error->getMessage();
        } catch (Throwable) {
            $_SESSION['flash_error'] = 'The action could not be created.';
        }

        Response::redirect('/settings/actions');
    }

    public function deleteAction(\Base $f3, array $params): never
    {
        $user = $this->user();
        $id = substr((string) ($params['action'] ?? ''), 0, 36);
        if ($this->csrf->valid($_POST['_csrf'] ?? null) && $this->actions->delete($id, $user['id'])) {
            $_SESSION['flash_success'] = 'Action deleted.';
        }

        Response::redirect('/settings/actions');
    }

    public function execute(\Base $f3, array $params): never
    {
        $user = $this->user();
        $captureId = substr((string) ($params['id'] ?? ''), 0, 36);
        if (!$this->csrf->valid($_POST['_csrf'] ?? null)) {
            if (Request::wantsJson()) {
                Response::json(['error' => 'Your session expired. Refresh and try again.'], 419);
            }
            Response::redirect('/captures/' . rawurlencode($captureId));
        }

        try {
            $result = $this->executor->execute(
                substr((string) ($params['action'] ?? ''), 0, 36),
                $captureId,
                $user['id'],
            );
            if (Request::wantsJson()) {
                Response::json($result);
            }
            $_SESSION['flash_success'] = $result['message'];
        } catch (Throwable $error) {
            $message = trim($error->getMessage()) ?: 'The action could not be completed.';
            if (Request::wantsJson()) {
                Response::json(['error' => $message], 422);
            }
            $_SESSION['flash_error'] = $message;
        }

        Response::redirect('/captures/' . rawurlencode($captureId));
    }

    private function user(): array
    {
        $user = $this->auth->user();
        if (!$user) {
            Response::redirect('/login');
        }

        return $user;
    }
}
