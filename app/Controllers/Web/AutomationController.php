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
use Catch\Services\GenericWebhookClient;
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
        $this->renderTargets();
    }

    public function createTarget(): never
    {
        $user = $this->user();
        if (!$this->csrf->valid($_POST['_csrf'] ?? null)) {
            Response::redirect('/settings/targets');
        }

        $form = $this->targetFormFromRequest();
        try {
            $type = $form['type'];
            if ($type === 'prsm_task') {
                $this->targets->createPrsmTask(
                    $user['id'],
                    (string) ($_POST['token'] ?? ''),
                );
                $_SESSION['flash_success'] = 'Prsm target created.';
            } elseif ($type === 'generic_webhook') {
                $this->targets->createGenericWebhook(
                    $user['id'],
                    $form['name'],
                    $form['url'],
                    $form['method'],
                    $form['auth_type'],
                    (string) ($_POST['secret'] ?? ''),
                    $form['auth_header'],
                );
                $_SESSION['flash_success'] = 'Endpoint target created.';
            } else {
                throw new InvalidArgumentException('Choose a supported target type.');
            }
            unset($_SESSION['target_form']);
        } catch (InvalidArgumentException $error) {
            $_SESSION['target_form'] = $form;
            $_SESSION['flash_error'] = $error->getMessage();
        } catch (Throwable) {
            $_SESSION['target_form'] = $form;
            $_SESSION['flash_error'] = 'The target could not be created.';
        }

        Response::redirect('/settings/targets');
    }

    public function editTarget(\Base $f3, array $params): void
    {
        $user = $this->user();
        $target = $this->targets->find(substr((string) ($params['target'] ?? ''), 0, 36), $user['id']);
        if (!$target) {
            $this->view->render('errors/404', ['title' => 'Not found', 'user' => $user], 404);
            return;
        }
        $this->renderTargets($target);
    }

    public function updateTarget(\Base $f3, array $params): never
    {
        $user = $this->user();
        $id = substr((string) ($params['target'] ?? ''), 0, 36);
        if (!$this->csrf->valid($_POST['_csrf'] ?? null)) {
            Response::redirect('/settings/targets/' . rawurlencode($id) . '/edit');
        }
        $target = $this->targets->find($id, $user['id']);
        if (!$target) {
            Response::redirect('/settings/targets');
        }
        $form = $this->targetFormFromRequest($id, (string) $target['type']);
        try {
            if ($target['type'] === 'prsm_task') {
                $this->targets->updatePrsmTask($id, $user['id'], (string) ($_POST['token'] ?? ''));
            } else {
                $this->targets->updateGenericWebhook(
                    $id,
                    $user['id'],
                    $form['name'],
                    $form['url'],
                    $form['method'],
                    $form['auth_type'],
                    (string) ($_POST['secret'] ?? ''),
                    $form['auth_header'],
                );
            }
            unset($_SESSION['target_form']);
            $_SESSION['flash_success'] = 'Target updated.';
            Response::redirect('/settings/targets');
        } catch (InvalidArgumentException $error) {
            $_SESSION['target_form'] = $form;
            $_SESSION['flash_error'] = $error->getMessage();
        } catch (Throwable) {
            $_SESSION['target_form'] = $form;
            $_SESSION['flash_error'] = 'The target could not be updated.';
        }

        Response::redirect('/settings/targets/' . rawurlencode($id) . '/edit');
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
        $this->renderActions();
    }

    public function createAction(): never
    {
        $user = $this->user();
        if (!$this->csrf->valid($_POST['_csrf'] ?? null)) {
            Response::redirect('/settings/actions');
        }

        $form = $this->actionFormFromRequest();
        try {
            $this->actions->create($user['id'], $form['name'], $this->stepsFromForm($form, $user['id']));
            unset($_SESSION['action_form']);
            $_SESSION['flash_success'] = 'Action created.';
        } catch (InvalidArgumentException $error) {
            $_SESSION['action_form'] = $form;
            $_SESSION['flash_error'] = $error->getMessage();
        } catch (Throwable) {
            $_SESSION['action_form'] = $form;
            $_SESSION['flash_error'] = 'The action could not be created.';
        }

        Response::redirect('/settings/actions');
    }

    public function editAction(\Base $f3, array $params): void
    {
        $user = $this->user();
        $action = $this->actions->find(substr((string) ($params['action'] ?? ''), 0, 36), $user['id']);
        if (!$action) {
            $this->view->render('errors/404', ['title' => 'Not found', 'user' => $user], 404);
            return;
        }
        $this->renderActions($action);
    }

    public function updateAction(\Base $f3, array $params): never
    {
        $user = $this->user();
        $id = substr((string) ($params['action'] ?? ''), 0, 36);
        if (!$this->csrf->valid($_POST['_csrf'] ?? null)) {
            Response::redirect('/settings/actions/' . rawurlencode($id) . '/edit');
        }
        if (!$this->actions->find($id, $user['id'])) {
            Response::redirect('/settings/actions');
        }
        $form = $this->actionFormFromRequest($id);
        try {
            $this->actions->update($id, $user['id'], $form['name'], $this->stepsFromForm($form, $user['id']));
            unset($_SESSION['action_form']);
            $_SESSION['flash_success'] = 'Action updated.';
            Response::redirect('/settings/actions');
        } catch (InvalidArgumentException $error) {
            $_SESSION['action_form'] = $form;
            $_SESSION['flash_error'] = $error->getMessage();
        } catch (Throwable) {
            $_SESSION['action_form'] = $form;
            $_SESSION['flash_error'] = 'The action could not be updated.';
        }

        Response::redirect('/settings/actions/' . rawurlencode($id) . '/edit');
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

    private function renderTargets(?array $editingTarget = null): void
    {
        $user = $this->user();
        $targetId = (string) ($editingTarget['id'] ?? '');
        $stored = is_array($_SESSION['target_form'] ?? null) ? $_SESSION['target_form'] : [];
        if ((string) ($stored['target_id'] ?? '') !== $targetId) {
            $stored = [];
        }
        $config = is_array($editingTarget['config'] ?? null) ? $editingTarget['config'] : [];
        $form = $stored + [
            'target_id' => $targetId,
            'type' => (string) ($editingTarget['type'] ?? 'prsm_task'),
            'name' => (string) ($editingTarget['name'] ?? ''),
            'url' => (string) ($config['url'] ?? ''),
            'method' => (string) ($config['method'] ?? 'POST'),
            'auth_type' => (string) ($config['auth']['type'] ?? 'none'),
            'auth_header' => (string) ($config['auth']['header'] ?? 'X-API-Key'),
        ];
        $this->view->render('account/settings', [
            'title' => $editingTarget ? 'Settings · Edit target' : 'Settings · Targets',
            'user' => $user,
            'settingsTab' => 'targets',
            'targets' => $this->targets->all($user['id']),
            'editingTarget' => $editingTarget,
            'targetForm' => $form,
            'prsmBaseUrl' => rtrim((string) $this->config->get('prsm.base_url', ''), '/'),
            'webhookMethods' => GenericWebhookClient::methods(),
            'csrf' => $this->csrf->token(),
        ]);
    }

    private function renderActions(?array $editingAction = null): void
    {
        $user = $this->user();
        $actionId = (string) ($editingAction['id'] ?? '');
        $stored = is_array($_SESSION['action_form'] ?? null) ? $_SESSION['action_form'] : [];
        if ((string) ($stored['action_id'] ?? '') !== $actionId) {
            $stored = [];
        }
        $actionForm = $stored ?: ($editingAction ? $this->actionFormFromAction($editingAction) : []);
        $actionForm += [
            'action_id' => $actionId,
            'name' => '',
            'target_id' => '',
            'content_type' => 'application/json',
            'body_template' => GenericWebhookClient::defaultTemplateJson(),
            'omit_empty' => true,
            'tag' => '',
            'after' => 'keep',
        ];
        $this->view->render('account/settings', [
            'title' => $editingAction ? 'Settings · Edit action' : 'Settings · Actions',
            'user' => $user,
            'settingsTab' => 'actions',
            'targets' => $this->targets->all($user['id']),
            'actions' => $this->actions->all($user['id']),
            'editingAction' => $editingAction,
            'webhookContentTypes' => GenericWebhookClient::contentTypes(),
            'webhookDefaultJson' => GenericWebhookClient::defaultTemplateJson(),
            'webhookDefaultText' => GenericWebhookClient::defaultTemplateText(),
            'webhookDefaultMultipart' => GenericWebhookClient::defaultTemplateMultipart(),
            'webhookVariables' => GenericWebhookClient::variables(),
            'actionForm' => $actionForm,
            'csrf' => $this->csrf->token(),
        ]);
    }

    private function targetFormFromRequest(string $targetId = '', ?string $fixedType = null): array
    {
        return [
            'target_id' => $targetId,
            'type' => $fixedType ?? (string) ($_POST['type'] ?? 'prsm_task'),
            'name' => (string) ($_POST['name'] ?? ''),
            'url' => (string) ($_POST['url'] ?? ''),
            'method' => (string) ($_POST['method'] ?? 'POST'),
            'auth_type' => (string) ($_POST['auth_type'] ?? 'none'),
            'auth_header' => (string) ($_POST['auth_header'] ?? 'X-API-Key'),
        ];
    }

    private function actionFormFromRequest(string $actionId = ''): array
    {
        return [
            'action_id' => $actionId,
            'name' => (string) ($_POST['name'] ?? ''),
            'target_id' => (string) ($_POST['target_id'] ?? ''),
            'content_type' => (string) ($_POST['content_type'] ?? 'application/json'),
            'body_template' => (string) ($_POST['body_template'] ?? ''),
            'omit_empty' => isset($_POST['omit_empty']),
            'tag' => (string) ($_POST['tag'] ?? ''),
            'after' => (string) ($_POST['after'] ?? 'keep'),
        ];
    }

    private function stepsFromForm(array $form, string $userId): array
    {
        $targetId = substr((string) $form['target_id'], 0, 36);
        $target = $this->targets->find($targetId, $userId);
        if (!$target) {
            throw new InvalidArgumentException('Choose a target for this action.');
        }
        $sendConfig = ['target_id' => $targetId];
        if ($target['type'] === 'generic_webhook') {
            $contentType = GenericWebhookClient::validateContentType((string) $form['content_type']);
            $sendConfig['content_type'] = $contentType;
            $sendConfig['body_template'] = GenericWebhookClient::parseBodyTemplate(
                (string) $form['body_template'],
                $contentType,
            );
            $sendConfig['omit_empty'] = (bool) $form['omit_empty'];
        }
        $steps = [['type' => 'send_capture_to_target', 'config' => $sendConfig]];
        $tag = trim((string) $form['tag']);
        if ($tag !== '') {
            $steps[] = ['type' => 'add_tag', 'config' => ['name' => $tag]];
        }
        $after = (string) $form['after'];
        if ($after === 'archive') {
            $steps[] = ['type' => 'archive_capture', 'config' => []];
        } elseif ($after === 'trash') {
            $steps[] = ['type' => 'delete_capture', 'config' => []];
        } elseif ($after !== 'keep') {
            throw new InvalidArgumentException('Choose what happens after the target step.');
        }

        return $steps;
    }

    private function actionFormFromAction(array $action): array
    {
        $form = ['action_id' => (string) $action['id'], 'name' => (string) $action['name']];
        foreach ($action['steps'] as $step) {
            $config = is_array($step['config'] ?? null) ? $step['config'] : [];
            if ($step['type'] === 'send_capture_to_target') {
                $body = $config['body_template'] ?? GenericWebhookClient::defaultTemplateJson();
                $form += [
                    'target_id' => (string) ($config['target_id'] ?? ''),
                    'content_type' => (string) ($config['content_type'] ?? 'application/json'),
                    'body_template' => is_string($body) ? $body : (string) json_encode(
                        $body,
                        JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
                    ),
                    'omit_empty' => (bool) ($config['omit_empty'] ?? true),
                ];
            } elseif ($step['type'] === 'add_tag') {
                $form['tag'] = (string) ($config['name'] ?? '');
            } elseif ($step['type'] === 'archive_capture') {
                $form['after'] = 'archive';
            } elseif ($step['type'] === 'delete_capture') {
                $form['after'] = 'trash';
            }
        }

        return $form;
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
