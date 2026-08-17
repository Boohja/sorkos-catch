<?php

declare(strict_types=1);

use Catch\Core\Config;
use Catch\Core\Database;
use Catch\Repositories\EmailImportRepository;
use Catch\Repositories\EmailInboxRepository;
use Catch\Services\CaptureService;
use Catch\Services\EmailContentSanitizer;
use Catch\Services\EmailImporter;
use Catch\Services\EmailMessageReader;
use Catch\Services\UploadService;
use Catch\Validation\CaptureValidator;

$root = dirname(__DIR__);
require $root . '/app/bootstrap.php';

$lock = fopen($root . '/storage/tmp/import-mail.lock', 'c');
if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
    fwrite(STDERR, "Another email importer process is already running.\n");
    exit(0);
}

try {
    $config = Config::load($root);
    $database = new Database($config);
    $pdo = $database->connection();
    $captures = new CaptureService(
        $database,
        new CaptureValidator(),
        new UploadService($config, $root . '/storage/uploads'),
    );
    $importer = new EmailImporter(
        $config,
        new EmailInboxRepository($pdo, $config),
        new EmailImportRepository($pdo),
        $captures,
        new EmailMessageReader(new EmailContentSanitizer()),
        $root . '/storage/logs/import-mail.log',
    );
    $counts = $importer->run();
    fwrite(STDOUT, sprintf(
        "Email import complete: %d processed, %d discarded, %d failed.\n",
        $counts['processed'],
        $counts['discarded'],
        $counts['failed'],
    ));
    exit($counts['failed'] > 0 ? 2 : 0);
} catch (Throwable $error) {
    @error_log(sprintf("[%s] Email importer failed: %s\n", gmdate(DATE_ATOM), $error->getMessage()), 3, $root . '/storage/logs/import-mail.log');
    fwrite(STDERR, "Email importer failed: {$error->getMessage()}\n");
    exit(1);
} finally {
    if (is_resource($lock)) {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}
