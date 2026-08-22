<?php

declare(strict_types=1);

namespace Catch\Controllers\Technical;

use Catch\Core\Config;
use Catch\Http\Response;
use Catch\Services\EmailImportRunner;
use Throwable;

final class EmailImportController
{
    public function __construct(
        private readonly Config $config,
        private readonly EmailImportRunner $runner,
        private readonly string $logFile,
    ) {
    }

    public function run(): never
    {
        $expected = trim((string) $this->config->get('mail.cron_secret', ''));
        if ($expected === '') {
            Response::json([
                'error' => [
                    'code' => 'cron_disabled',
                    'message' => 'The email import endpoint is not configured.',
                ],
            ], 404);
        }

        $provided = $_GET['secret'] ?? null;
        if (!is_string($provided) || !hash_equals($expected, $provided)) {
            Response::json([
                'error' => [
                    'code' => 'unauthorized',
                    'message' => 'A valid cron secret is required.',
                ],
            ], 401);
        }

        try {
            $counts = $this->runner->run();
        } catch (Throwable $error) {
            @error_log(sprintf(
                "[%s] HTTP email importer failed: %s\n",
                gmdate(DATE_ATOM),
                $error->getMessage(),
            ), 3, $this->logFile);
            Response::json([
                'error' => [
                    'code' => 'import_failed',
                    'message' => 'The email import could not be completed.',
                ],
            ], 500);
        }

        if ($counts === null) {
            Response::json([
                'status' => 'busy',
                'message' => 'Another email importer process is already running.',
            ], 409);
        }

        Response::json(['status' => 'ok'] + $counts);
    }
}
