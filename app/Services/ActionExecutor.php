<?php

declare(strict_types=1);

namespace Catch\Services;

use Catch\Repositories\ActionRepository;
use Catch\Repositories\CaptureRepository;
use Catch\Repositories\TagRepository;
use Catch\Repositories\TargetRepository;
use RuntimeException;

final class ActionExecutor
{
    public function __construct(
        private readonly ActionRepository $actions,
        private readonly TargetRepository $targets,
        private readonly CaptureRepository $captures,
        private readonly TagRepository $tags,
        private readonly PrsmTaskClient $prsm,
    ) {
    }

    public function execute(string $actionId, string $captureId, string $userId): array
    {
        $action = $this->actions->find($actionId, $userId);
        $capture = $this->captures->find($captureId, $userId);
        if (!$action || !$capture) {
            throw new RuntimeException('The action or capture could not be found.');
        }
        if (!empty($capture['deleted_at'])) {
            throw new RuntimeException('Actions cannot run on captures in Trash.');
        }

        $status = (string) $capture['status'];
        foreach ($action['steps'] as $step) {
            $config = $step['config'];
            switch ($step['type']) {
                case 'send_capture_to_target':
                    $this->sendToTarget((string) ($config['target_id'] ?? ''), $capture, $userId);
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

        return [
            'capture_status' => $status,
            'message' => '“' . $action['name'] . '” completed.',
        ];
    }

    private function sendToTarget(string $targetId, array $capture, string $userId): void
    {
        $target = $this->targets->find($targetId, $userId, true);
        if (!$target) {
            throw new RuntimeException('The target used by this action no longer exists.');
        }
        match ($target['type']) {
            'prsm_task' => $this->prsm->createTask($target, $capture),
            default => throw new RuntimeException('The target type is not supported.'),
        };
    }

    private function addTag(string $captureId, string $name, string $userId): void
    {
        if ($name === '' || !$this->tags->assignByName($captureId, $name, $userId)) {
            throw new RuntimeException('The action tag could not be added.');
        }
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
