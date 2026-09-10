<?php

declare(strict_types=1);

namespace Catch\Services;

use Catch\Repositories\ActionRepository;
use Catch\Repositories\CaptureRepository;
use Catch\Repositories\TagRepository;
use Catch\Repositories\TargetRepository;
use Catch\Repositories\UserRepository;
use RuntimeException;

final class ActionExecutor
{
    private array $activeExecutions = [];

    public function __construct(
        private readonly ActionRepository $actions,
        private readonly TargetRepository $targets,
        private readonly CaptureRepository $captures,
        private readonly TagRepository $tags,
        private readonly PrsmTaskClient $prsm,
        private readonly GenericWebhookClient $webhooks,
        private readonly UserRepository $users,
    ) {
    }

    public function execute(string $actionId, string $captureId, string $userId): array
    {
        $action = $this->actions->find($actionId, $userId);
        $capture = $this->captures->find($captureId, $userId);
        $user = $this->users->find($userId);
        if (!$action || !$capture || !$user) {
            throw new RuntimeException('The action or capture could not be found.');
        }
        if (!empty($capture['deleted_at'])) {
            throw new RuntimeException('Actions cannot run on captures in Trash.');
        }

        $executionKey = $captureId . ':' . $actionId;
        $this->activeExecutions[$executionKey] = true;
        try {
            $status = (string) $capture['status'];
            foreach ($action['steps'] as $step) {
                $config = $step['config'];
                switch ($step['type']) {
                    case 'send_capture_to_target':
                        $this->sendToTarget((string) ($config['target_id'] ?? ''), $capture, $step, $user);
                        break;
                    case 'add_tag':
                        $this->addTag($captureId, (string) ($config['name'] ?? ''), $userId);
                        break;
                    case 'archive_capture':
                        $this->archive($captureId, $userId);
                        break;
                    case 'delete_capture':
                        $this->trash($captureId, $userId);
                        break;
                    default:
                        throw new RuntimeException('The action contains an unsupported step.');
                }
                if ($step['type'] === 'archive_capture') {
                    $status = 'archived';
                } elseif ($step['type'] === 'delete_capture') {
                    $status = 'trash';
                }
            }
        } finally {
            unset($this->activeExecutions[$executionKey]);
        }

        return [
            'capture_status' => $status,
            'message' => '“' . $action['name'] . '” completed.',
        ];
    }

    public function executeForAssignedTag(array $tag, string $captureId, string $userId): ?array
    {
        $actionId = substr((string) ($tag['action_id'] ?? ''), 0, 36);
        $executionKey = $captureId . ':' . $actionId;
        if (empty($tag['newly_assigned']) || $actionId === '' || isset($this->activeExecutions[$executionKey])) {
            return null;
        }

        return $this->execute($actionId, $captureId, $userId);
    }

    private function sendToTarget(string $targetId, array $capture, array $step, array $user): void
    {
        $target = $this->targets->find($targetId, (string) $user['id'], true);
        if (!$target) {
            throw new RuntimeException('The target used by this action no longer exists.');
        }
        match ($target['type']) {
            'prsm_task' => $this->prsm->createTask($target, $capture),
            'generic_webhook' => $this->webhooks->send($target, $capture, $step, $user),
            default => throw new RuntimeException('The target type is not supported.'),
        };
    }

    private function addTag(string $captureId, string $name, string $userId): void
    {
        $tag = $name === '' ? null : $this->tags->assignByName($captureId, $name, $userId);
        if (!$tag) {
            throw new RuntimeException('The action tag could not be added.');
        }
        $this->executeForAssignedTag($tag, $captureId, $userId);
    }

    private function archive(string $captureId, string $userId): void
    {
        if (!$this->captures->setStatus($captureId, $userId, 'archived')) {
            throw new RuntimeException('The capture could not be archived.');
        }
    }

    private function trash(string $captureId, string $userId): void
    {
        if (!$this->captures->trash($captureId, $userId)) {
            throw new RuntimeException('The capture could not be moved to Trash.');
        }
    }
}
