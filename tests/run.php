<?php

declare(strict_types=1);
$root = dirname(__DIR__);
require $root . '/app/bootstrap.php';
$failures = 0;
$test = function (string $name, callable $case) use (&$failures): void {
    try {
        $case();
        echo "PASS $name\n";
    } catch (Throwable $e) {
        $failures++;
        echo "FAIL $name: {$e->getMessage()}\n";
    }
};
$test('UUID format', function () {
    if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', Catch\Core\Id::uuid())) {
        throw new RuntimeException('Invalid UUID');
    }
});
$test('Capture validation requires content', function () {
    $errors = (new Catch\Validation\CaptureValidator())->validate(['client_capture_id' => 'test','type' => 'text']);
    if (!isset($errors['content'])) {
        throw new RuntimeException('Content accepted');
    }
});
$test('Capture validation accepts text', function () {
    $errors = (new Catch\Validation\CaptureValidator())->validate(['client_capture_id' => 'test','type' => 'text','text' => 'Hello']);
    if ($errors) {
        throw new RuntimeException(json_encode($errors));
    }
});
$test('Unknown captures resolve content and reject unsafe attachments', function () use ($root) {
    $config = Catch\Core\Config::load($root);
    $uploads = new Catch\Services\UploadService($config, sys_get_temp_dir());
    $service = new Catch\Services\CaptureService(new Catch\Core\Database($config), new Catch\Validation\CaptureValidator(), $uploads);
    $normalize = (new ReflectionClass($service))->getMethod('normalizeUnknownInput');
    $url = $normalize->invoke($service, ['type' => 'unknown','text' => 'https://example.com/article','extracted_text' => 'https://example.com/article'], []);
    if ($url['type'] !== 'url' || $url['url'] !== 'https://example.com/article' || $url['text'] !== null) {
        throw new RuntimeException('A lone URL was not promoted');
    }
    $text = $normalize->invoke($service, ['type' => 'unknown','text' => 'First line' . PHP_EOL . 'Second line','extracted_text' => 'First line Second line'], []);
    if ($text['type'] !== 'text' || $text['title'] !== 'First line' || $text['extracted_text'] !== 'First line Second line') {
        throw new RuntimeException('Text was not classified without destroying OCR output');
    }
    $ocr = $normalize->invoke($service, ['type' => 'unknown','extracted_text' => 'Recognized date: tomorrow'], []);
    if ($ocr['type'] !== 'text' || $ocr['text'] !== 'Recognized date: tomorrow') {
        throw new RuntimeException('OCR-only input was not preserved as text');
    }
    $png = tempnam(sys_get_temp_dir(), 'catch-unknown-png-');
    $pdf = tempnam(sys_get_temp_dir(), 'catch-unknown-pdf-');
    $wav = tempnam(sys_get_temp_dir(), 'catch-unknown-wav-');
    $plain = tempnam(sys_get_temp_dir(), 'catch-unknown-txt-');
    try {
        file_put_contents($png, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII='));
        file_put_contents($pdf, "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\n%%EOF");
        file_put_contents($wav, base64_decode('UklGRiQAAABXQVZFZm10IBAAAAABAAEAQB8AAEAfAAABAAgAZGF0YQAAAAA='));
        file_put_contents($plain, 'not an image');
        $imageFile = ['attachment' => ['name' => 'pixel.png','tmp_name' => $png,'error' => UPLOAD_ERR_OK,'size' => filesize($png)]];
        $image = $normalize->invoke($service, ['type' => 'unknown','extracted_text' => 'pixel'], $imageFile);
        if ($image['type'] !== 'image' || $image['title'] !== 'pixel.png') {
            throw new RuntimeException('Image input was not classified');
        }
        $mixed = $normalize->invoke($service, ['type' => 'unknown','text' => 'Context'], $imageFile);
        if ($mixed['type'] !== 'mixed') {
            throw new RuntimeException('Text plus image was not classified as mixed');
        }
        $pdfFile = ['attachment' => ['name' => 'paper.pdf','tmp_name' => $pdf,'error' => UPLOAD_ERR_OK,'size' => filesize($pdf)]];
        $document = $normalize->invoke($service, ['type' => 'unknown','extracted_text' => 'Thesis OCR'], $pdfFile);
        if ($document['type'] !== 'file' || $document['title'] !== 'paper.pdf') {
            throw new RuntimeException('PDF input was not classified');
        }
        $audioFile = ['attachment' => ['name' => 'memo.wav','tmp_name' => $wav,'error' => UPLOAD_ERR_OK,'size' => filesize($wav)]];
        $audio = $normalize->invoke($service, ['type' => 'unknown','extracted_text' => 'Call Alice tomorrow'], $audioFile);
        if ($audio['type'] !== 'audio' || $audio['title'] !== 'memo.wav' || $audio['extracted_text'] !== 'Call Alice tomorrow' || isset($audio['text'])) {
            throw new RuntimeException('Audio input was not classified without preserving the recording as the source');
        }
        $unsafe = ['attachment' => ['name' => 'notes.txt','tmp_name' => $plain,'error' => UPLOAD_ERR_OK,'size' => filesize($plain)]];
        try {
            $normalize->invoke($service, ['type' => 'unknown','text' => 'Context'], $unsafe);
            throw new RuntimeException('Unsafe attachment was accepted');
        } catch (InvalidArgumentException $error) {
            $fields = json_decode($error->getMessage(), true);
            $message = (string)($fields['attachment'] ?? '');
            if (!str_contains($message, 'text/plain') || !str_contains($message, '.txt')) {
                throw $error;
            }
        }
        $tinyRoot = sys_get_temp_dir() . '/catch-unknown-config-' . bin2hex(random_bytes(4));
        mkdir($tinyRoot . '/config', 0777, true);
        file_put_contents($tinyRoot . '/config/config.ini', "[uploads]\nmax_bytes=10\nallowed_mime=\"image/png,application/pdf\"\n");
        try {
            $tinyUploads = new Catch\Services\UploadService(Catch\Core\Config::load($tinyRoot), sys_get_temp_dir());
            $allowedProperty = (new ReflectionClass($tinyUploads))->getProperty('allowed');
            if (!in_array('audio/x-m4a', $allowedProperty->getValue($tinyUploads), true)) {
                throw new RuntimeException('Apple M4A audio was removed by a configured MIME override');
            }
            try {
                $tinyUploads->inspectUnknownAttachment($imageFile['attachment']);
                throw new RuntimeException('Oversized attachment was accepted');
            } catch (RuntimeException $error) {
                if (!str_contains($error->getMessage(), 'upload limit')) {
                    throw $error;
                }
            }
        } finally {
            @unlink($tinyRoot . '/config/config.ini');
            @rmdir($tinyRoot . '/config');
            @rmdir($tinyRoot);
        }
    } finally {
        @unlink($png);
        @unlink($pdf);
        @unlink($wav);
        @unlink($plain);
    }
    $spec = json_decode((string)file_get_contents($root . '/public/docs/api/openapi.json'), true, 512, JSON_THROW_ON_ERROR);
    if (!in_array('unknown', $spec['components']['schemas']['CaptureType']['enum'] ?? [], true) || in_array('unknown', $spec['components']['schemas']['StoredCaptureType']['enum'] ?? [], true)) {
        throw new RuntimeException('OpenAPI does not distinguish unknown input from stored types');
    }
});
$test('Prerelease access permits only the configured Sorkos user', function () use ($root) {
    $directory = sys_get_temp_dir() . '/catch-access-' . bin2hex(random_bytes(6));
    mkdir($directory . '/config', 0777, true);
    file_put_contents($directory . '/config/config.ini', "[access]\nprerelease=true\nallowed_sorkos_user_id=usr_allowed\n");
    $policy = new Catch\Core\AccessPolicy(Catch\Core\Config::load($directory));
    if (!$policy->allowsSorkosUserId('usr_allowed') || $policy->allowsSorkosUserId('usr_other') || $policy->allowsSorkosUserId('')) {
        throw new RuntimeException('Prerelease allowlist failed');
    }unlink($directory . '/config/config.ini');
    rmdir($directory . '/config');
    rmdir($directory);
});
$test('Released access does not require an allowlist', function () use ($root) {
    $directory = sys_get_temp_dir() . '/catch-access-' . bin2hex(random_bytes(6));
    mkdir($directory . '/config', 0777, true);
    file_put_contents($directory . '/config/config.ini', "[access]\nprerelease=false\n");
    $policy = new Catch\Core\AccessPolicy(Catch\Core\Config::load($directory));
    if (!$policy->allowsSorkosUserId('usr_any')) {
        throw new RuntimeException('Released access was denied');
    }unlink($directory . '/config/config.ini');
    rmdir($directory . '/config');
    rmdir($directory);
});
$test('Auth exchange is compatible with PHP 8.5', function () use ($root) {
    $source = (string)file_get_contents($root . '/app/Services/AuthService.php');
    if (str_contains($source, 'curl_' . 'close(')) {
        throw new RuntimeException('Deprecated cURL close call found');
    }
});
$test('Shortcut responses expose exactly one flat string', function () {
    $success = Catch\Http\Response::shortcutPayload('', 'capture-id');
    $failure = Catch\Http\Response::shortcutPayload('Failed.', '');
    if ($success !== ['result' => 'capture-id'] || $failure !== ['error' => 'Failed.']) {
        throw new RuntimeException('Invalid shortcut response envelope');
    }foreach ([['',''],['Failed.','capture-id']] as [$error,$result]) {
        try {
            Catch\Http\Response::shortcutPayload($error, $result);
            throw new RuntimeException('Ambiguous shortcut response accepted');
        } catch (LogicException) {
        }
    }
});
$test('Capture ID fallback preserves client precedence and rejects device tokens', function () {
    $class = new ReflectionClass(Catch\Controllers\Api\CaptureController::class);
    $controller = $class->newInstanceWithoutConstructor();
    $method = $class->getMethod('clientCaptureId');
    if ($method->invoke($controller, 'manual-id', 'header-id') !== 'manual-id') {
        throw new RuntimeException('Body capture ID did not take precedence');
    }if ($method->invoke($controller, null, 'header-id') !== 'header-id') {
        throw new RuntimeException('Idempotency key was not used');
    }foreach ([[null,null],['',''],['catch_device_secret',null],[null,'catch_device_secret']] as $values) {
        $generated = $method->invoke($controller, ...$values);
        if (!preg_match('/^client_capture_[0-9a-f]{32}$/', $generated)) {
            throw new RuntimeException('Safe random capture ID was not generated');
        }
    }
});
$test('Pairing codes are ten numeric digits', function () {
    $class = new ReflectionClass(Catch\Repositories\DeviceRepository::class);
    $repository = $class->newInstanceWithoutConstructor();
    $generate = $class->getMethod('newCode');
    $normalize = $class->getMethod('normalizeCode');
    for ($i = 0;$i < 100;$i++) {
        [$plain,$display] = $generate->invoke($repository);
        if (!preg_match('/^[1-9]\d{9}$/', $plain) || !preg_match('/^[1-9]\d{4} \d{5}$/', $display) || $normalize->invoke($repository, $display) !== $plain) {
            throw new RuntimeException('Invalid numeric pairing code');
        }
    }foreach (['1234567890','12345 67890','12345-67890'] as $valid) {
        if ($normalize->invoke($repository, $valid) !== '1234567890') {
            throw new RuntimeException('Valid pairing code rejected');
        }
    }foreach (['0123456789','123456789','12345678901','12345A6789'] as $invalid) {
        if ($normalize->invoke($repository, $invalid) !== null) {
            throw new RuntimeException('Invalid pairing code accepted');
        }
    }if (Catch\Repositories\DeviceRepository::PAIRING_CODE_TTL_MINUTES !== 15) {
        throw new RuntimeException('Unexpected pairing code lifetime');
    }
});
$test('Extension pairing keeps tokens out of approval URLs', function () use ($root) {
    $migration = (string)file_get_contents($root . '/database/migrations/003_extension_pairing.sql');
    foreach (['code_challenge CHAR(43)','token_encrypted TEXT','expires_at DATETIME(6)'] as $required) {
        if (!str_contains($migration, $required)) {
            throw new RuntimeException('Extension pairing migration is missing ' . $required);
        }
    }
    $controller = (string)file_get_contents($root . '/app/Controllers/Api/ExtensionController.php');
    if (
        !str_contains($controller, "http_build_query(['request' => \$pairing['request_id']]")
        || str_contains($controller, "http_build_query(['device_token' =>")
    ) {
        throw new RuntimeException('Approval URL does not contain only the short-lived request ID');
    }
    $background = (string)file_get_contents($root . '/extension/src/background.js');
    $browser = (string)file_get_contents($root . '/extension/src/shared/browser.js');
    if (!str_contains($background, "crypto.subtle.digest('SHA-256'") || !str_contains($browser, 'storage.local')) {
        throw new RuntimeException('Extension verifier or extension storage is missing');
    }
});
$test('CLI authorization issues hash-only read tokens', function () use ($root) {
    $migration = (string) file_get_contents($root . '/database/migrations/012_cli_tokens.sql');
    foreach (["'cli'", 'expires_at DATETIME(6)', 'revoked_at DATETIME(6)', 'code_challenge CHAR(43)'] as $required) {
        if (!str_contains($migration, $required)) {
            throw new RuntimeException('CLI token migration is missing ' . $required);
        }
    }
    if (str_contains($migration, 'token_encrypted')) {
        throw new RuntimeException('CLI bearer tokens must not be stored reversibly');
    }
    $repository = (string) file_get_contents($root . '/app/Repositories/CliAuthRepository.php');
    foreach (["'catch_cli_'", "hash('sha256', \$token)", 'capture:read'] as $required) {
        if (!str_contains($repository, $required)) {
            throw new RuntimeException('CLI authorization repository is missing ' . $required);
        }
    }
    if (str_contains($repository, 'SecretBox') || str_contains($repository, 'token_encrypted')) {
        throw new RuntimeException('CLI bearer token is stored reversibly');
    }
    $controller = (string) file_get_contents($root . '/app/Controllers/Api/CliController.php');
    if (!str_contains($controller, "http_build_query(['login' => \$request['login_id']]") || str_contains($controller, "http_build_query(['token' =>")) {
        throw new RuntimeException('CLI approval URL may expose more than the temporary login ID');
    }
});
$test('OpenAPI documents every public machine route', function () use ($root) {
    $source = (string)file_get_contents($root . '/app/Core/Application.php');
    preg_match_all("/\\\$f3->route\\('([A-Z]+) ([^']+)'/", $source, $matches, PREG_SET_ORDER);
    $routes = [];
    foreach ($matches as $match) {
        if ($match[2] !== '/health' && !str_starts_with($match[2], '/api/')) {
            continue;
        }$path = preg_replace('/@([A-Za-z_][A-Za-z0-9_]*)/', '{$1}', $match[2]);
        $routes[] = $match[1] . ' ' . $path;
    }$spec = json_decode((string)file_get_contents($root . '/public/docs/api/openapi.json'), true, 512, JSON_THROW_ON_ERROR);
    $documented = [];
    foreach ($spec['paths'] as $path => $methods) {
        foreach ($methods as $method => $operation) {
            if (in_array($method, ['get','post','put','patch','delete'], true)) {
                $documented[] = strtoupper($method) . ' ' . $path;
            }
        }
    }sort($routes);
    sort($documented);
    if ($routes !== $documented) {
        throw new RuntimeException('OpenAPI route inventory differs from Application routes: ' . json_encode(['routes' => $routes,'documented' => $documented]));
    }
});
$test('Capture aliases document their respective multipart attachment shapes', function () use ($root) {
    $spec = json_decode((string)file_get_contents($root . '/public/docs/api/openapi.json'), true, 512, JSON_THROW_ON_ERROR);
    $shortcut = $spec['paths']['/api/shortcut/captures']['post']['requestBody']['$ref'] ?? null;
    $versioned = $spec['paths']['/api/v1/captures']['post']['requestBody']['$ref'] ?? null;
    if ($shortcut !== '#/components/requestBodies/ShortcutCaptureRequest' || $versioned !== '#/components/requestBodies/CaptureRequest') {
        throw new RuntimeException('Capture aliases use the wrong request bodies');
    }$shortcutContent = $spec['components']['requestBodies']['ShortcutCaptureRequest']['content'] ?? [];
    $versionedContent = $spec['components']['requestBodies']['CaptureRequest']['content'] ?? [];
    if (array_key_first($shortcutContent) !== 'multipart/form-data' || array_key_first($versionedContent) !== 'multipart/form-data') {
        throw new RuntimeException('Multipart form data is not the default documented capture format');
    }$shortcutProperties = $shortcutContent['multipart/form-data']['schema']['allOf'][1]['properties'] ?? [];
    $versionedProperties = $versionedContent['multipart/form-data']['schema']['allOf'][1]['properties'] ?? [];
    if (($shortcutProperties['attachment']['format'] ?? null) !== 'binary' || isset($shortcutProperties['attachments[]']) || !isset($versionedProperties['attachments[]'])) {
        throw new RuntimeException('Shortcut and versioned attachment fields are not distinct');
    }$controllerClass = new ReflectionClass(Catch\Controllers\Api\CaptureController::class);
    $controller = $controllerClass->newInstanceWithoutConstructor();
    $count = $controllerClass->getMethod('uploadedFileCount');
    $two = ['attachments' => ['error' => [UPLOAD_ERR_OK,UPLOAD_ERR_OK]]];
    if ($count->invoke($controller, $two) !== 2) {
        throw new RuntimeException('Shortcut multi-file enforcement cannot count uploads');
    }$tagNames = $controllerClass->getMethod('tagNames');
    if ($tagNames->invoke($controller, ' Work,follow up,WORK,, ') !== ['work','follow up']) {
        throw new RuntimeException('Capture tags are not normalized and deduplicated');
    }$tags = $spec['components']['schemas']['CaptureInput']['properties']['tags'] ?? null;
    if (($tags['type'] ?? null) !== 'string' || !str_contains((string) ($tags['description'] ?? ''), 'comma-separated')) {
        throw new RuntimeException('Capture tags are not documented as a comma-separated string');
    }$typeDescription = $spec['components']['schemas']['CaptureType']['description'] ?? '';
    $textDescription = $spec['components']['schemas']['CaptureInput']['properties']['text']['description'] ?? '';
    $extractedDescription = $spec['components']['schemas']['CaptureInput']['properties']['extracted_text']['description'] ?? '';
    if (!str_contains($typeDescription, 'voice-to-text') || !str_contains($textDescription, 'voice-to-text') || !str_contains($extractedDescription, 'does not satisfy')) {
        throw new RuntimeException('Voice-to-text field semantics are not documented');
    }
});
$test('OpenAPI documents verifier-protected extension pairing', function () use ($root) {
    $spec = json_decode((string)file_get_contents($root . '/public/docs/api/openapi.json'), true, 512, JSON_THROW_ON_ERROR);
    foreach (['/api/extension/pairing-requests','/api/extension/pairing-requests/{request}/exchange','/api/extension/disconnect'] as $path) {
        if (!isset($spec['paths'][$path]['post'])) {
            throw new RuntimeException('Missing extension endpoint ' . $path);
        }
    }$challenge = $spec['components']['schemas']['ExtensionPairingStartRequest']['properties']['code_challenge'] ?? [];
    if (($challenge['minLength'] ?? 0) !== 43 || ($challenge['maxLength'] ?? 0) !== 43) {
        throw new RuntimeException('Code challenge constraints are missing');
    }
});
$test('Extension pairing failures are logged and service-safe', function () use ($root) {
    $controller = (string)file_get_contents($root . '/app/Controllers/Api/ExtensionController.php');
    foreach (['pairing_unavailable','storage/logs/extension.log','503'] as $required) {
        if (!str_contains($controller, $required)) {
            throw new RuntimeException('Extension pairing failure handling is missing ' . $required);
        }
    }
});
$test('Capture provenance migration links devices without deleting history', function () use ($root) {
    $migration = (string)file_get_contents($root . '/database/migrations/004_capture_provenance.sql');
    foreach (['client_type ENUM','user_agent VARCHAR(500)','device_id CHAR(36)','ON DELETE SET NULL'] as $required) {
        if (!str_contains($migration, $required)) {
            throw new RuntimeException('Capture provenance migration is missing ' . $required);
        }
    }$repository = (string)file_get_contents($root . '/app/Repositories/DeviceRepository.php');
    foreach (['DELETE FROM catch_device_tokens','UPDATE catch_devices SET status=','ensureWebDevice'] as $required) {
        if (!str_contains($repository, $required)) {
            throw new RuntimeException('Device revocation or web registration is missing ' . $required);
        }
    }
});
$test('Extension validates revoked connections and supports images', function () use ($root) {
    $application = (string)file_get_contents($root . '/app/Core/Application.php');
    $background = (string)file_get_contents($root . '/extension/src/background.js');
    $storage = (string)file_get_contents($root . '/extension/src/shared/storage.js');
    $manifest = json_decode((string)file_get_contents($root . '/extension/manifest.firefox.json'), true, 512, JSON_THROW_ON_ERROR);
    if (!str_contains($application, 'GET /api/extension/connection')) {
        throw new RuntimeException('Connection validation endpoint is missing');
    }foreach (['catch-image','feedback.toast','remoteAttachmentUrl','source_url','source_title','capture_method','extension_version','history.get'] as $required) {
        if (!str_contains($background, $required)) {
            throw new RuntimeException('Extension capture feedback, image handling, provenance, or history is missing ' . $required);
        }
    }foreach (['recordCaptureEvent','getCaptureHistory','slice(0, 20)'] as $required) {
        if (!str_contains($storage, $required)) {
            throw new RuntimeException('Extension history storage is missing ' . $required);
        }
    }if (($manifest['action']['default_area'] ?? null) !== 'navbar') {
        throw new RuntimeException('Firefox action does not default to the toolbar');
    }
});
$test('Remote Reddit previews resolve to their canonical original', function () {
    $service = new Catch\Services\RemoteContentService();
    $method = (new ReflectionClass($service))->getMethod('canonicalImageUrl');
    $preview = 'https://preview.redd.it/example-v0-ols9a6zsjoqg1.png?width=307';
    if ($method->invoke($service, $preview) !== 'https://i.redd.it/ols9a6zsjoqg1.png') {
        throw new RuntimeException('Reddit preview URL was not normalized');
    }
});
$test('Browser labels distinguish common desktop clients', function () {
    $firefox = Catch\Services\BrowserInfo::fromUserAgent('Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:149.0) Gecko/20100101 Firefox/149.0');
    $edge = Catch\Services\BrowserInfo::fromUserAgent('Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/150.0 Safari/537.36 Edg/150.0');
    if ($firefox['label'] !== 'Firefox on Windows' || $edge['label'] !== 'Microsoft Edge on Windows') {
        throw new RuntimeException('Browser labels are not specific enough');
    }
});
$test('Catch numbers are durable per-user references', function () use ($root) {
    $migration = (string)file_get_contents($root . '/database/migrations/002_capture_numbers.sql');
    foreach (['next_catch_number','ROW_NUMBER() OVER (PARTITION BY user_id ORDER BY created_at, id)','UNIQUE KEY uq_captures_user_number (user_id, catch_number)'] as $required) {
        if (!str_contains($migration, $required)) {
            throw new RuntimeException('Capture number migration is incomplete: ' . $required);
        }
    }$repository = (string)file_get_contents($root . '/app/Repositories/CaptureRepository.php');
    if (!str_contains($repository, 'FOR UPDATE') || !str_contains($repository, 'next_catch_number = next_catch_number + 1')) {
        throw new RuntimeException('Catch number allocation is not transaction-safe');
    }$controller = (string)file_get_contents($root . '/app/Controllers/Api/CaptureController.php');
    if (!str_contains($controller, "Response::shortcut('', (string) \$capture['catch_number']") || !str_contains($controller, "'catch_number' => (int) \$capture['catch_number']")) {
        throw new RuntimeException('Capture APIs do not return catch numbers');
    }$spec = json_decode((string)file_get_contents($root . '/public/docs/api/openapi.json'), true, 512, JSON_THROW_ON_ERROR);
    if (($spec['components']['schemas']['CatchNumber']['readOnly'] ?? false) !== true || !in_array('catch_number', $spec['components']['schemas']['CreateCaptureResponse']['required'] ?? [], true)) {
        throw new RuntimeException('OpenAPI does not expose catch numbers');
    }
});
$test('Inbox bulk delete is confirmed and permanently removes related data', function () use ($root) {
    $application = (string)file_get_contents($root . '/app/Core/Application.php');
    $controller = (string)file_get_contents($root . '/app/Controllers/Web/CaptureController.php');
    $repository = (string)file_get_contents($root . '/app/Repositories/CaptureRepository.php');
    $view = (string)file_get_contents($root . '/app/Views/captures/index.html');
    $list = (string)file_get_contents($root . '/app/Views/captures/_list.html');
    $item = (string)file_get_contents($root . '/app/Views/captures/_item.html');
    $script = (string)file_get_contents($root . '/public/assets/js/capture-bulk.js');
    $style = (string)file_get_contents($root . '/public/assets/css/capture-bulk.css');
    foreach (['POST /captures/bulk-delete','bulkDelete'] as $required) {
        if (!str_contains($application, $required)) {
            throw new RuntimeException('Bulk delete route is missing ' . $required);
        }
    }foreach (['attachmentFilesDeletable','removeAttachmentFiles','unlink(','flash_success'] as $required) {
        if (!str_contains($controller, $required)) {
            throw new RuntimeException('Bulk deletion does not safely remove attachment files or report success: ' . $required);
        }
    }foreach (['DELETE FROM catch_captures','FOR UPDATE','beginTransaction','rollBack'] as $required) {
        if (!str_contains($repository, $required)) {
            throw new RuntimeException('Bulk database deletion is not permanent or transactional: ' . $required);
        }
    }foreach (['data-bulk-delete-dialog','This action cannot be undone','data-bulk-actions'] as $required) {
        if (!str_contains($view, $required)) {
            throw new RuntimeException('Bulk confirmation UI is missing ' . $required);
        }
    }foreach (['name="capture_ids[]"','form="{{ @bulkFormId }}"'] as $required) {
        if (!str_contains($list . $item, $required)) {
            throw new RuntimeException('Capture selections are not associated with the bulk form');
        }
    }foreach (['showModal','form.hidden = total === 0','requestSubmit'] as $required) {
        if (!str_contains($script, $required)) {
            throw new RuntimeException('Bulk visibility or confirmation behavior is missing ' . $required);
        }
    }if (!str_contains($style, 'position:sticky') || !str_contains($style, 'justify-content:flex-end')) {
        throw new RuntimeException('Bulk action bar is not sticky and right aligned');
    }
});
$test('Lists group captures many-to-many and appear in capture details', function () use ($root) {
    $migration = (string)file_get_contents($root . '/database/migrations/005_lists.sql');
    $application = (string)file_get_contents($root . '/app/Core/Application.php');
    $repository = (string)file_get_contents($root . '/app/Repositories/ListRepository.php');
    $captureRepository = (string)file_get_contents($root . '/app/Repositories/CaptureRepository.php');
    $detail = (string)file_get_contents($root . '/app/Views/captures/show.html');
    $dialog = (string)file_get_contents($root . '/app/Views/captures/_list_dialog.html');
    $index = (string)file_get_contents($root . '/app/Views/lists/index.html');
    foreach (['catch_lists','catch_capture_lists','PRIMARY KEY (capture_id, list_id)','ON DELETE CASCADE'] as $required) {
        if (!str_contains($migration, $required)) {
            throw new RuntimeException('List migration is incomplete: ' . $required);
        }
    }foreach (['GET /lists','POST /captures/@id/lists','ListController'] as $required) {
        if (!str_contains($application, $required)) {
            throw new RuntimeException('List routes are incomplete: ' . $required);
        }
    }foreach (['top_capture_title','capture_count','INSERT IGNORE INTO catch_capture_lists'] as $required) {
        if (!str_contains($repository, $required)) {
            throw new RuntimeException('List repository is incomplete: ' . $required);
        }
    }if (!str_contains($captureRepository, 'listByList') || !str_contains($captureRepository, 'listsForCapture')) {
        throw new RuntimeException('Captures do not expose their lists');
    }foreach (['data-capture-lists','Add to list','availableLists'] as $required) {
        if (!str_contains($detail . $dialog, $required)) {
            throw new RuntimeException('Capture list selection is incomplete: ' . $required);
        }
    }foreach (['list-grid','top_capture_title','capture_count'] as $required) {
        if (!str_contains($index, $required)) {
            throw new RuntimeException('List cards are incomplete: ' . $required);
        }
    }
});
$test('Audio attachments retain transcripts and report rejected MIME details', function () use ($root) {
    $service = (string)file_get_contents($root . '/app/Services/CaptureService.php');
    $upload = (string)file_get_contents($root . '/app/Services/UploadService.php');
    $detail = (string)file_get_contents($root . '/app/Views/captures/show.html');
    foreach (['hasAudio','extracted_text','UnsupportedAttachmentException'] as $required) {
        if (!str_contains($service, $required)) {
            throw new RuntimeException('Audio capture behavior is incomplete: ' . $required);
        }
    }foreach (['audio/x-m4a','audio/x-caf','Detected MIME type','file extension'] as $required) {
        if (!str_contains($upload, $required)) {
            throw new RuntimeException('Audio MIME support or diagnostics are incomplete: ' . $required);
        }
    }
    if (!str_contains($detail, '<audio controls') || !str_contains($detail, "'Transcript'")) {
        throw new RuntimeException('Audio detail playback or transcript rendering is missing');
    }
});
$test('Capture detail keeps extracted text compact and manages tags in a modal', function () use ($root) {
    $detail = (string)file_get_contents($root . '/app/Views/captures/show.html');
    $dialog = (string)file_get_contents($root . '/app/Views/captures/_tag_dialog.html');
    $layout = (string)file_get_contents($root . '/app/Views/layout.html');
    $script = (string)file_get_contents($root . '/public/assets/js/capture-tags.js');
    $controller = (string)file_get_contents($root . '/app/Controllers/Web/TagController.php');
    $repository = (string)file_get_contents($root . '/app/Repositories/TagRepository.php');
    $style = (string)file_get_contents($root . '/public/assets/css/capture-detail.css');

    foreach (['<details class="extracted-text-card">','data-capture-field="extracted_text"','data-open-tag-dialog','glyph-tag','data-heading-tags','data-heading-tag-id'] as $required) {
        if (!str_contains($detail, $required)) {
            throw new RuntimeException('Capture detail refinement is incomplete: ' . $required);
        }
    }
    if (str_contains($detail, 'capture-tags-panel')) {
        throw new RuntimeException('The old inline tag panel remains');
    }
    foreach (['data-tag-dialog','data-tag-input','<datalist','data-assigned-tags','data-remove-tag'] as $required) {
        if (!str_contains($dialog, $required)) {
            throw new RuntimeException('Tag dialog is incomplete: ' . $required);
        }
    }
    if (str_contains($dialog, 'data-tag-status')) {
        throw new RuntimeException('Tag feedback still renders inline instead of using toasts');
    }
    foreach (['enableTagDialog','_tag_dialog.html'] as $required) {
        if (!str_contains($detail . $layout, $required)) {
            throw new RuntimeException('Tag dialog is not enabled: ' . $required);
        }
    }
    foreach (['dialog.showModal()', "input.value = ''", 'input.focus()', 'renderTag(json.tag)', 'pill.remove()', 'window.Catch?.notify', 'data-heading-tag-id', "event.key !== 'Enter'", 'form?.requestSubmit()'] as $required) {
        if (!str_contains($script, $required)) {
            throw new RuntimeException('Tag dialog behavior is incomplete: ' . $required);
        }
    }
    if (!str_contains($controller, 'assignByName') || !str_contains($repository, 'function assignByName')) {
        throw new RuntimeException('Tag names cannot be created and assigned in one action');
    }
    foreach (['.extracted-text-card','.tag-dialog-field','.tag-dialog-assigned'] as $required) {
        if (!str_contains($style, $required)) {
            throw new RuntimeException('Capture detail styling is incomplete: ' . $required);
        }
    }
});
$test('Attachment playback supports byte ranges and bypasses the service worker', function () use ($root) {
    $cases = [
        ['bytes=0-1', 100, ['start' => 0, 'end' => 1, 'length' => 2]],
        ['bytes=10-', 100, ['start' => 10, 'end' => 99, 'length' => 90]],
        ['bytes=-10', 100, ['start' => 90, 'end' => 99, 'length' => 10]],
        ['bytes=0-999', 100, ['start' => 0, 'end' => 99, 'length' => 100]],
    ];
    foreach ($cases as [$header, $size, $expected]) {
        if (Catch\Http\ByteRange::parse($header, $size) !== $expected) {
            throw new RuntimeException('Byte range was parsed incorrectly: ' . $header);
        }
    }
    if (Catch\Http\ByteRange::parse(null, 100) !== null) {
        throw new RuntimeException('A missing Range header should request the full file');
    }
    foreach (['bytes=100-101', 'bytes=20-10', 'bytes=0-1,5-6', 'bytes=-0'] as $header) {
        try {
            Catch\Http\ByteRange::parse($header, 100);
            throw new RuntimeException('Invalid byte range was accepted: ' . $header);
        } catch (InvalidArgumentException) {
            // Expected.
        }
    }

    $controller = (string)file_get_contents($root . '/app/Controllers/Web/CaptureController.php');
    foreach (['Accept-Ranges: bytes', 'Content-Range: bytes */', 'http_response_code(206)', "'audio/mp4'"] as $required) {
        if (!str_contains($controller, $required)) {
            throw new RuntimeException('Attachment streaming is incomplete: ' . $required);
        }
    }
    $worker = (string)file_get_contents($root . '/public/service-worker.js');
    foreach (["url.pathname.startsWith('/attachments/')", "request.destination==='audio'", "request.destination==='video'"] as $required) {
        if (!str_contains($worker, $required)) {
            throw new RuntimeException('Media requests are still intercepted: ' . $required);
        }
    }
});
$test('Trash is timestamp-based, recoverable, and expires after 30 days', function () use ($root) {
    $migration = (string)file_get_contents($root . '/database/migrations/006_capture_trash.sql');
    $repository = (string)file_get_contents($root . '/app/Repositories/CaptureRepository.php');
    $controller = (string)file_get_contents($root . '/app/Controllers/Web/CaptureController.php');
    $view = (string)file_get_contents($root . '/app/Views/captures/index.html');
    foreach (["status='archived'","ENUM('inbox','archived')",'idx_captures_user_deleted'] as $required) {
        if (!str_contains($migration, $required)) {
            throw new RuntimeException('Trash migration is incomplete: ' . $required);
        }
    }
    foreach (['listTrash','trashMany','restore','expiredTrashIds','DATE_SUB(UTC_TIMESTAMP(6), INTERVAL','deleted_at IS NOT NULL'] as $required) {
        if (!str_contains($repository, $required)) {
            throw new RuntimeException('Trash repository behavior is incomplete: ' . $required);
        }
    }foreach (['purgeExpiredTrash','purgeCaptures','Move to Trash','30 days'] as $required) {
        if (!str_contains($controller . $view, $required)) {
            throw new RuntimeException('Trash lifecycle or UI is incomplete: ' . $required);
        }
    }
});
$test('List membership controls the active capture state', function () use ($root) {
    $repository = (string)file_get_contents($root . '/app/Repositories/ListRepository.php');
    $migration = (string)file_get_contents($root . '/database/migrations/007_reconcile_list_capture_states.sql');
    foreach (["status='archived'",'archived_at=COALESCE','NOT EXISTS(SELECT 1 FROM catch_capture_lists',"status='inbox'",'archived_at=NULL'] as $required) {
        if (!str_contains($repository, $required)) {
            throw new RuntimeException('List state transition is incomplete: ' . $required);
        }
    }if (!str_contains($migration, 'EXISTS (SELECT 1 FROM catch_capture_lists')) {
        throw new RuntimeException('Existing list members are not reconciled');
    }
});
$test('Capture detail supports quiet in-place editing and global request progress', function () use ($root) {
    $application = (string)file_get_contents($root . '/app/Core/Application.php');
    $repository = (string)file_get_contents($root . '/app/Repositories/CaptureRepository.php');
    $view = (string)file_get_contents($root . '/app/Views/captures/show.html');
    $editing = (string)file_get_contents($root . '/public/assets/js/capture-edit.js');
    $progress = (string)file_get_contents($root . '/public/assets/js/request-progress.js');
    $style = (string)file_get_contents($root . '/public/assets/css/capture-detail.css');
    if (!str_contains($application, 'POST /captures/@id') || !str_contains($repository, 'updateEditableField')) {
        throw new RuntimeException('Capture update endpoint is missing');
    }foreach (['data-capture-field="title"','data-capture-field="text"','data-capture-field="extracted_text"','data-capture-field="url"'] as $required) {
        if (!str_contains($view, $required)) {
            throw new RuntimeException('Editable detail field is missing: ' . $required);
        }
    }foreach (["event.key === 'Enter'",'addEventListener(\'blur\'','fetch(`/captures/','setValue(element, before)'] as $required) {
        if (!str_contains($editing, $required)) {
            throw new RuntimeException('In-place save behavior is incomplete: ' . $required);
        }
    }foreach (['initialValueOf', "clone.querySelectorAll('br')", 'clone.textContent'] as $required) {
        if (!str_contains($editing, $required)) {
            throw new RuntimeException('Hidden editable fields lose their initial value: ' . $required);
        }
    }if (!str_contains($progress, 'window.fetch=async') || !str_contains($style, 'position:fixed;inset:0 0 auto') || !str_contains($style, 'height:4px')) {
        throw new RuntimeException('Global async progress indicator is incomplete');
    }
});
$test('Capture lists use membership truth and a batch assignment dialog', function () use ($root) {
    $repository = (string)file_get_contents($root . '/app/Repositories/CaptureRepository.php');
    $lists = (string)file_get_contents($root . '/app/Repositories/ListRepository.php');
    $controller = (string)file_get_contents($root . '/app/Controllers/Web/ListController.php');
    $view = (string)file_get_contents($root . '/app/Views/captures/show.html');
    $dialog = (string)file_get_contents($root . '/app/Views/captures/_list_dialog.html');
    $script = (string)file_get_contents($root . '/public/assets/js/capture-lists.js');
    if (str_contains($repository, 'c.status=:status AND c.deleted_at IS NULL AND cl.list_id=:list')) {
        throw new RuntimeException('List detail still hides members by capture status');
    }foreach (['syncAssignments','list_ids','capture_status'] as $required) {
        if (!str_contains($lists . $controller, $required)) {
            throw new RuntimeException('Batch list assignment is incomplete: ' . $required);
        }
    }foreach (['data-list-dialog','data-list-form','data-assigned-lists','Add to list'] as $required) {
        if (!str_contains($view . $dialog, $required)) {
            throw new RuntimeException('List assignment dialog is incomplete: ' . $required);
        }
    }if (!str_contains($script, 'renderLists') || !str_contains($script, 'showModal')) {
        throw new RuntimeException('List dialog behavior is incomplete');
    }
});
$test('Devices expose capture counts, last use time, and capture history', function () use ($root) {
    $devices = (string)file_get_contents($root . '/app/Repositories/DeviceRepository.php');
    $captures = (string)file_get_contents($root . '/app/Repositories/CaptureRepository.php');
    $controller = (string)file_get_contents($root . '/app/Controllers/Web/DeviceController.php');
    $index = (string)file_get_contents($root . '/app/Views/devices/index.html')
        . (string)file_get_contents($root . '/app/Views/devices/_table.html');
    $detail = (string)file_get_contents($root . '/app/Views/devices/show.html');
    foreach (['capture_count','capture_last_used_at','MAX(c.created_at)'] as $required) {
        if (!str_contains($devices, $required)) {
            throw new RuntimeException('Device summary query is incomplete: ' . $required);
        }
    }if (!str_contains($captures, 'listByDevice') || !str_contains($controller, 'listByDevice')) {
        throw new RuntimeException('Device capture history query is missing');
    }foreach (['capture_count','last_seen_at','Last used:'] as $required) {
        if (!str_contains($index, $required)) {
            throw new RuntimeException('Device list summary is missing: ' . $required);
        }
    }if (!str_contains($detail, 'Captures from this device')) {
        throw new RuntimeException('Device detail capture history is missing');
    }
});
$test('Device types drive icons and remain editable', function () use ($root) {
    $migration = (string)file_get_contents($root . '/database/migrations/008_device_types.sql') . (string)file_get_contents($root . '/database/migrations/009_refine_device_type_guesses.sql');
    $repository = (string)file_get_contents($root . '/app/Repositories/DeviceRepository.php');
    $controller = (string)file_get_contents($root . '/app/Controllers/Web/DeviceController.php');
    $index = (string)file_get_contents($root . '/app/Views/devices/index.html')
        . (string)file_get_contents($root . '/app/Views/devices/_table.html');
    $detail = (string)file_get_contents($root . '/app/Views/devices/show.html');
    foreach (["ENUM('laptop','phone','pc','tablet')","DEFAULT 'pc'",'%iphone%',"'%ipad%'"] as $required) {
        if (!str_contains($migration, $required)) {
            throw new RuntimeException('Device type migration is incomplete: ' . $required);
        }
    }
    foreach (['deviceType(', 'device_type = :device_type'] as $required) {
        if (!str_contains($repository, $required)) {
            throw new RuntimeException('Device type inference or update is missing: ' . $required);
        }
    }if (!str_contains($controller, "\$_POST['device_type']")) {
        throw new RuntimeException('Device type is not accepted by the rename action');
    }
    foreach (['glyph-{{ @device.view.deviceType }}','Last used:','capture_count'] as $required) {
        if (!str_contains($index, $required)) {
            throw new RuntimeException('Device list type or usage UI is incomplete: ' . $required);
        }
    }foreach (['device-type-picker','name="device_type"','Last used','Captures from this device'] as $required) {
        if (!str_contains($detail, $required)) {
            throw new RuntimeException('Device detail type or usage UI is incomplete: ' . $required);
        }
    }
});
$test('Requested interface icons and danger outline are used consistently', function () use ($root) {
    $capture = (string)file_get_contents($root . '/app/Views/captures/show.html');
    $inbox = (string)file_get_contents($root . '/app/Views/captures/index.html');
    $list = (string)file_get_contents($root . '/app/Views/lists/captures.html');
    $views = '';
    foreach (glob($root . '/app/Views/*/*.html') as $path) {
        $views .= (string)file_get_contents($path);
    }foreach (['glyph-capture','glyph-list','glyph-archive','glyph-trash'] as $required) {
        if (!str_contains($capture, $required)) {
            throw new RuntimeException('Capture detail icon is missing: ' . $required);
        }
    }foreach (['glyph-inbox','glyph-archive','glyph-trash'] as $required) {
        if (!str_contains($inbox, $required)) {
            throw new RuntimeException('Capture tab icon is missing: ' . $required);
        }
    }if (!str_contains($list, 'glyph-list')) {
        throw new RuntimeException('List heading icon is missing');
    }if (preg_match('/button-danger(?!-outline)/', $views)) {
        throw new RuntimeException('A filled danger button remains');
    }
});
$test('Scrolling remains browser-native and progress uses the primary color', function () use ($root) {
    $layout = (string)file_get_contents($root . '/app/Views/layout.html');
    $app = (string)file_get_contents($root . '/public/assets/js/app.js');
    $style = (string)file_get_contents($root . '/public/assets/css/capture-detail.css');
    if (str_contains($layout . $app, 'page-scrollbar')) {
        throw new RuntimeException('Custom page scrolling is still installed');
    }if (!str_contains($style, 'background:var(--primary)')) {
        throw new RuntimeException('Request progress does not use the primary color');
    }
});
$test('View data can expose capture status without colliding with the HTTP status', function () use ($root) {
    $view = (string)file_get_contents($root . '/app/Core/View.php');
    if (!str_contains($view, 'int $httpStatus = 200') || !str_contains($view, 'http_response_code($httpStatus)')) {
        throw new RuntimeException('View status data is still shadowed by the response parameter');
    }
});
$test('Views use only Fat-Free HTML templates', function () use ($root) {
    $renderer = (string) file_get_contents($root . '/app/Core/View.php');
    if (!str_contains($renderer, "\\Template::instance()->render('layout.html'")
        || str_contains($renderer, 'require $this->path')) {
        throw new RuntimeException('The view renderer does not use the Fat-Free template engine');
    }

    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/app/Views'));
    foreach ($files as $file) {
        if (!$file->isFile()) {
            continue;
        }
        if ($file->getExtension() !== 'html') {
            throw new RuntimeException('Non-HTML view remains: ' . $file->getFilename());
        }
        if (str_contains((string) file_get_contents($file->getPathname()), '<?')) {
            throw new RuntimeException('PHP tag remains in view: ' . $file->getFilename());
        }
    }
});
$test('Debug capture logging is bounded, redacted, and device scoped', function () use ($root) {
    $migration = (string) file_get_contents($root . '/database/migrations/010_capture_debug_requests.sql');
    $service = (string) file_get_contents($root . '/app/Services/CaptureDebugService.php');
    $api = (string) file_get_contents($root . '/app/Controllers/Api/CaptureController.php');
    $device = (string) file_get_contents($root . '/app/Controllers/Web/DeviceController.php');
    $view = (string) file_get_contents($root . '/app/Views/devices/_debug_requests.html');

    foreach (['user_id', 'device_id', 'token_id', 'parameters_json', 'files_json', 'remote_ip', 'verdict'] as $required) {
        if (!str_contains($migration, $required)) {
            throw new RuntimeException('Debug request migration is incomplete: ' . $required);
        }
    }

    foreach (['app.debug', 'isSensitiveKey', 'MAX_STRING_LENGTH', 'unset($request)'] as $required) {
        if (!str_contains($service, $required)) {
            throw new RuntimeException('Debug request storage is not safely bounded: ' . $required);
        }
    }

    foreach (['/api/shortcut/captures', '/api/v1/captures', 'rejected_validation', 'rejected_server_error'] as $required) {
        if (!str_contains($api, $required)) {
            throw new RuntimeException('Capture request instrumentation is incomplete: ' . $required);
        }
    }

    if (!str_contains($device, 'forDevice') || !str_contains($device, 'debugEnabled')) {
        throw new RuntimeException('Device debug requests are not scoped by the controller');
    }

    foreach (['<details class="debug-request">', 'Server verdict:', 'Token ID', 'Parameters', 'Uploaded files'] as $required) {
        if (!str_contains($view, $required)) {
            throw new RuntimeException('Device debug request UI is incomplete: ' . $required);
        }
    }
});
$test('Capture collections share responsive list and grid presentations', function () use ($root) {
    $repository = (string) file_get_contents($root . '/app/Repositories/CaptureRepository.php');
    $view = (string) file_get_contents($root . '/app/Views/captures/_list.html');
    $item = (string) file_get_contents($root . '/app/Views/captures/_item.html');
    $device = (string) file_get_contents($root . '/app/Views/devices/show.html');
    $style = (string) file_get_contents($root . '/public/assets/css/capture-collection.css');
    $script = (string) file_get_contents($root . '/public/assets/js/capture-view.js');

    if (substr_count($repository, 'visual_attachment_id') < 6) {
        throw new RuntimeException('Every visual capture collection query must expose its best image');
    }

    foreach (['data-capture-collection', 'capture-visual-fallback', 'loading="lazy"', 'capture-item-footer'] as $required) {
        if (!str_contains($view . $item, $required)) {
            throw new RuntimeException('Shared capture collection markup is missing ' . $required);
        }
    }

    foreach (["[data-view='grid']", 'grid-template-columns: repeat(3', 'aspect-ratio: 16 / 9', 'object-fit: cover'] as $required) {
        if (!str_contains($style, $required)) {
            throw new RuntimeException('Capture collection layouts are missing ' . $required);
        }
    }

    if (!str_contains($device, '<include href="captures/_list.html" />')) {
        throw new RuntimeException('Device capture history does not use the shared collection component');
    }

    foreach (['localStorage', 'catch-capture-view', "new Set(['list', 'grid'])", 'dataset.view = view'] as $required) {
        if (!str_contains($script, $required)) {
            throw new RuntimeException('The global capture layout preference is missing ' . $required);
        }
    }
});
$test('Relative capture times update globally and expose full local tooltips', function () use ($root) {
    $view = new Catch\Core\View($root . '/app/Views');
    $now = new DateTimeImmutable('2026-08-12 12:00:00', new DateTimeZone('UTC'));
    $cases = [
        '2026-08-12 11:59:30' => '<1m',
        '2026-08-12 11:35:00' => '25m',
        '2026-08-12 08:00:00' => '4h',
        '2026-08-08 12:00:00' => '4d',
        '2026-06-13 12:00:00' => '2mo',
        '2024-08-12 12:00:00' => '2y',
    ];
    foreach ($cases as $value => $expected) {
        if ($view->relativeTime($value, $now) !== $expected) {
            throw new RuntimeException('Relative time modifier failed for ' . $value);
        }
    }

    $collection = (string) file_get_contents($root . '/app/Views/captures/_list.html');
    $collectionItem = (string) file_get_contents($root . '/app/Views/captures/_item.html');
    $script = (string) file_get_contents($root . '/public/assets/js/relative-time.js');
    foreach (['data-relative-time', 'relativeTime', 'setInterval', '60_000', 'element.title'] as $required) {
        if (!str_contains($collection . $script, $required)) {
            throw new RuntimeException('Dynamic relative capture time is missing ' . $required);
        }
    }
});
$test('Capture menus and bulk actions share list assignment controls', function () use ($root) {
    $application = (string) file_get_contents($root . '/app/Core/Application.php');
    $captureRepository = (string) file_get_contents($root . '/app/Repositories/CaptureRepository.php');
    $listRepository = (string) file_get_contents($root . '/app/Repositories/ListRepository.php');
    $collection = (string) file_get_contents($root . '/app/Views/captures/_list.html');
    $collectionItem = (string) file_get_contents($root . '/app/Views/captures/_item.html');
    $menu = (string) file_get_contents($root . '/app/Views/captures/_action_menu.html');
    $dialog = (string) file_get_contents($root . '/app/Views/captures/_list_dialog.html');
    $bulk = (string) file_get_contents($root . '/app/Views/captures/index.html');

    foreach (['POST /captures/bulk-archive', 'POST /captures/bulk-lists'] as $required) {
        if (!str_contains($application, $required)) {
            throw new RuntimeException('Bulk action route is missing ' . $required);
        }
    }
    if (!str_contains($captureRepository, 'archiveMany') || !str_contains($listRepository, 'assignMany')) {
        throw new RuntimeException('Bulk archive or list assignment persistence is missing');
    }
    foreach (['data-capture-actions', 'data-capture-action-menu', 'data-list-dialog', 'data-open-bulk-lists', 'glyph-archive', 'glyph-trash'] as $required) {
        if (!str_contains($collection . $collectionItem . $menu . $dialog . $bulk, $required)) {
            throw new RuntimeException('Capture action interface is missing ' . $required);
        }
    }
});
$test('URL captures store immutable WebP preview attachments', function () use ($root) {
    $migration = (string) file_get_contents($root . '/database/migrations/011_link_preview_attachments.sql');
    $remote = (string) file_get_contents($root . '/app/Services/RemoteContentService.php');
    $uploads = (string) file_get_contents($root . '/app/Services/UploadService.php');
    $service = (string) file_get_contents($root . '/app/Services/CaptureService.php');
    $repository = (string) file_get_contents($root . '/app/Repositories/CaptureRepository.php');
    $detail = (string) file_get_contents($root . '/app/Views/captures/show.html');
    $preview = (string) file_get_contents($root . '/app/Views/captures/_link_preview.html');

    foreach (["ENUM('source','preview')", "DEFAULT 'source'", 'idx_attachments_capture_kind'] as $required) {
        if (!str_contains($migration, $required)) {
            throw new RuntimeException('Preview attachment migration is incomplete: ' . $required);
        }
    }

    foreach (['linkPreview', 'thumbnail_url', 'og:image', 'json+oembed', 'previewProvider'] as $required) {
        if (!str_contains($remote, $required)) {
            throw new RuntimeException('Remote preview discovery is incomplete: ' . $required);
        }
    }

    foreach (['storePreview', 'imagecreatefromstring', 'imagecopyresampled', 'imagewebp', "'preview'"] as $required) {
        if (!str_contains($uploads, $required)) {
            throw new RuntimeException('WebP preview generation is incomplete: ' . $required);
        }
    }

    foreach (['refreshLinkPreview', 'link_preview', 'previewStorageNames', 'deletePreviewAttachments'] as $required) {
        if (!str_contains($service . $repository, $required)) {
            throw new RuntimeException('Preview persistence or replacement is incomplete: ' . $required);
        }
    }

    $viewModel = (string) file_get_contents($root . '/app/Core/View.php');
    if (!str_contains($viewModel, "=== 'preview'") || !str_contains($viewModel, "=== 'source'")) {
        throw new RuntimeException('Generated previews are not separated from user attachments');
    }

    foreach (['link-preview-image', 'link-preview-title', 'link-preview-description'] as $required) {
        if (!str_contains($preview, $required)) {
            throw new RuntimeException('Link preview card is incomplete: ' . $required);
        }
    }

    $temporaryPath = sys_get_temp_dir() . '/catch-preview-' . bin2hex(random_bytes(6));
    mkdir($temporaryPath, 0700, true);
    $image = imagecreatetruecolor(1600, 900);
    if ($image === false) {
        throw new RuntimeException('The preview conversion fixture could not be created');
    }
    imagefill($image, 0, 0, imagecolorallocate($image, 244, 166, 0));
    ob_start();
    imagepng($image);
    $png = ob_get_clean();

    try {
        $uploadService = new Catch\Services\UploadService(
            Catch\Core\Config::load($root),
            $temporaryPath,
        );
        $attachment = $uploadService->storePreview(
            is_string($png) ? $png : '',
            '00000000-0000-4000-8000-000000000000',
        );
        $storedPath = $temporaryPath . '/' . $attachment['storage_name'];

        if (
            $attachment['kind'] !== 'preview'
            || $attachment['mime_type'] !== 'image/webp'
            || $attachment['width'] !== 800
            || $attachment['height'] !== 450
            || (new finfo(FILEINFO_MIME_TYPE))->file($storedPath) !== 'image/webp'
        ) {
            throw new RuntimeException('Preview conversion did not produce the bounded WebP attachment');
        }

        $uploadService->remove($attachment['storage_name']);
        @rmdir(dirname($storedPath));
        @rmdir(dirname(dirname($storedPath)));
    } finally {
        @rmdir($temporaryPath);
    }

    if (in_array('sqlite', PDO::getAvailableDrivers(), true)) {
        $database = new PDO('sqlite::memory:');
        $database->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $database->sqliteCreateFunction(
            'UTC_TIMESTAMP',
            static fn (): string => '2026-08-12 12:00:00.000000',
            -1,
        );
        $database->exec('PRAGMA foreign_keys = ON');
        $database->exec(<<<'SQL'
            CREATE TABLE catch_captures (
                id TEXT PRIMARY KEY,
                user_id TEXT NOT NULL,
                metadata_json TEXT,
                updated_at TEXT
            );
            CREATE TABLE catch_attachments (
                id TEXT PRIMARY KEY,
                capture_id TEXT NOT NULL,
                kind TEXT NOT NULL DEFAULT 'source',
                original_name TEXT NOT NULL,
                storage_name TEXT NOT NULL,
                mime_type TEXT NOT NULL,
                size_bytes INTEGER NOT NULL,
                width INTEGER,
                height INTEGER,
                checksum TEXT NOT NULL,
                created_at TEXT NOT NULL,
                FOREIGN KEY (capture_id) REFERENCES catch_captures(id) ON DELETE CASCADE
            );
            SQL);
        $database->exec("INSERT INTO catch_captures VALUES ('capture-1','user-1','{}',NULL)");
        $database->exec(<<<'SQL'
            INSERT INTO catch_attachments VALUES
                ('source-1','capture-1','source','recording.m4a','source-file','audio/mp4',12,NULL,NULL,'source-checksum','2026-08-12'),
                ('preview-old','capture-1','preview','link-preview.webp','preview-old-file','image/webp',12,640,360,'old-checksum','2026-08-12')
            SQL);

        $captureRepository = new Catch\Repositories\CaptureRepository($database);
        if ($captureRepository->previewStorageNames('capture-1', 'user-1') !== ['preview-old-file']) {
            throw new RuntimeException('Preview lookup is not scoped to its capture owner');
        }

        $database->beginTransaction();
        $captureRepository->deletePreviewAttachments('capture-1', 'user-1');
        $database->rollBack();
        if ($captureRepository->previewStorageNames('capture-1', 'user-1') !== ['preview-old-file']) {
            throw new RuntimeException('A failed preview replacement did not preserve the old preview');
        }

        $captureRepository->deletePreviewAttachments('capture-1', 'user-1');
        $captureRepository->addAttachment([
            'id' => 'preview-new',
            'capture_id' => 'capture-1',
            'kind' => 'preview',
            'original_name' => 'link-preview.webp',
            'storage_name' => 'preview-new-file',
            'mime_type' => 'image/webp',
            'size_bytes' => 10,
            'width' => 800,
            'height' => 450,
            'checksum' => 'new-checksum',
        ]);
        if ($captureRepository->previewStorageNames('capture-1', 'user-1') !== ['preview-new-file']) {
            throw new RuntimeException('Preview replacement did not store exactly one new preview');
        }
        if ((int) $database->query("SELECT COUNT(*) FROM catch_attachments WHERE kind='source'")->fetchColumn() !== 1) {
            throw new RuntimeException('Preview replacement modified a user source attachment');
        }

        $database->exec("DELETE FROM catch_captures WHERE id='capture-1'");
        if ((int) $database->query('SELECT COUNT(*) FROM catch_attachments')->fetchColumn() !== 0) {
            throw new RuntimeException('Capture deletion did not cascade to source and preview attachments');
        }
    }
});
$test('Link previews are deferred, bounded, and use the social preview identity', function () use ($root) {
    $application = (string) file_get_contents($root . '/app/Core/Application.php');
    $service = (string) file_get_contents($root . '/app/Services/CaptureService.php');
    $remote = (string) file_get_contents($root . '/app/Services/RemoteContentService.php');
    $detail = (string) file_get_contents($root . '/app/Views/captures/show.html');
    $client = (string) file_get_contents($root . '/public/assets/js/capture-preview.js');
    $devices = (string) file_get_contents($root . '/app/Repositories/DeviceRepository.php');
    $collection = (string) file_get_contents($root . '/app/Views/captures/_list.html');
    $collectionItem = (string) file_get_contents($root . '/app/Views/captures/_item.html');

    foreach (['link_preview_fetch', "'status' => 'pending'", 'previewFetchIsDue', 'failedPreviewFetchState'] as $required) {
        if (!str_contains($service, $required)) {
            throw new RuntimeException('Deferred preview state is incomplete: ' . $required);
        }
    }
    if (!str_contains($application, 'POST /captures/@id/preview')
        || !str_contains($detail, 'data-preview-fetch-due')
        || !str_contains($client, 'requestIdleCallback')) {
        throw new RuntimeException('Detail-triggered preview fetching is incomplete');
    }
    if (!str_contains($remote, "'Discordbot/2.0'") || !str_contains($remote, 'Accept-Language:')) {
        throw new RuntimeException('The social preview request identity is incomplete');
    }
    if (!str_contains($devices, 'ORDER BY COALESCE(t.last_used_at, d.last_seen_at, d.created_at) DESC')) {
        throw new RuntimeException('Devices are not sorted by last use');
    }
    foreach (['glyph-dots-vertical', 'glyph-table', 'glyph-grid'] as $required) {
        if (!str_contains($collection . $collectionItem, $required)) {
            throw new RuntimeException('Capture collection icon is missing: ' . $required);
        }
    }
});
$test('Capture task backlog enhancements remain integrated', function () use ($root) {
    $deviceMigration = (string) file_get_contents($root . '/database/migrations/013_device_client_icons.sql');
    $devices = (string) file_get_contents($root . '/app/Repositories/DeviceRepository.php');
    $cli = (string) file_get_contents($root . '/app/Repositories/CliAuthRepository.php');
    $deviceDetail = (string) file_get_contents($root . '/app/Views/devices/show.html');
    $viewModel = (string) file_get_contents($root . '/app/Core/View.php');
    foreach (["'extension','cli'", "'extension' => 'Extension'", "'cli' => 'CLI'"] as $required) {
        if (!str_contains($deviceMigration . $deviceDetail . $viewModel, $required)) {
            throw new RuntimeException('CLI or extension device icon support is incomplete: ' . $required);
        }
    }
    if (!str_contains($devices, 'suggestedClientName') || !str_contains($cli, 'suggestedDeviceName')) {
        throw new RuntimeException('Client-aware suggested device names are missing');
    }

    $repository = (string) file_get_contents($root . '/app/Repositories/CaptureRepository.php');
    $collection = (string) file_get_contents($root . '/app/Views/captures/_list.html');
    $collectionItem = (string) file_get_contents($root . '/app/Views/captures/_item.html');
    $previewClient = (string) file_get_contents($root . '/public/assets/js/capture-preview.js');
    $remote = (string) file_get_contents($root . '/app/Services/RemoteContentService.php');
    $appStoreProvider = (string) file_get_contents($root . '/app/Services/LinkPreview/AppStoreProvider.php');
    if (substr_count($repository, "a.kind IN ('preview', 'source')") < 6) {
        throw new RuntimeException('Source images are not available to every capture collection');
    }
    foreach (['data-preview-fetch-due', 'MAX_COLLECTION_FETCHES', 'IntersectionObserver'] as $required) {
        if (!str_contains($collection . $collectionItem . $previewClient, $required)) {
            throw new RuntimeException('Bounded collection preview fetching is incomplete: ' . $required);
        }
    }
    foreach (['itunes.apple.com/lookup', 'providerPreview', 'artworkUrl512'] as $required) {
        if (!str_contains($remote . $appStoreProvider, $required)) {
            throw new RuntimeException('App Store preview lookup is incomplete: ' . $required);
        }
    }

    $captureController = (string) file_get_contents($root . '/app/Controllers/Web/CaptureController.php');
    $captureIndex = (string) file_get_contents($root . '/app/Views/captures/index.html');
    $captureCreate = (string) file_get_contents($root . '/public/assets/js/capture-create.js');
    foreach (['Request::wantsJson()', "Response::redirect('/inbox')", 'data-capture-form-status', 'new FormData(form)', 'captureCollection?.insert'] as $required) {
        if (!str_contains($captureController . $captureIndex . $captureCreate, $required)) {
            throw new RuntimeException('In-place web capture submission is incomplete: ' . $required);
        }
    }
    $captureActions = (string) file_get_contents($root . '/public/assets/js/capture-actions.js');
    $captureBulk = (string) file_get_contents($root . '/public/assets/js/capture-bulk.js');
    $captureLists = (string) file_get_contents($root . '/public/assets/js/capture-lists.js');
    $captureCollection = (string) file_get_contents($root . '/public/assets/js/capture-collection.js');
    foreach (['captureCollection?.transition', "Accept: 'application/json'", 'animateRemoval'] as $required) {
        if (!str_contains($captureActions . $captureBulk . $captureLists . $captureCollection, $required)) {
            throw new RuntimeException('Async capture collection actions are incomplete: ' . $required);
        }
    }

    $debug = (string) file_get_contents($root . '/app/Services/CaptureDebugService.php');
    $captureDetail = (string) file_get_contents($root . '/app/Views/captures/show.html');
    if (!str_contains($debug, 'forCapture') || !str_contains($captureDetail, 'Related debug request')) {
        throw new RuntimeException('Capture detail does not expose its related debug request');
    }

    $editing = (string) file_get_contents($root . '/public/assets/js/capture-edit.js');
    foreach (['data-markup', 'appendInlineMarkup', 'renderMarkup', "document.createElement('ul')"] as $required) {
        if (!str_contains($captureDetail . $editing, $required)) {
            throw new RuntimeException('Safe inline-editable markup is incomplete: ' . $required);
        }
    }
});
$test('Account settings and provider adapters remain decoupled', function () use ($root) {
    $application = (string) file_get_contents($root . '/app/Core/Application.php');
    $layout = (string) file_get_contents($root . '/app/Views/layout.html');
    $settings = (string) file_get_contents($root . '/app/Views/account/settings.html');
    $deviceTable = (string) file_get_contents($root . '/app/Views/devices/_table.html');
    $capture = (string) file_get_contents($root . '/app/Views/captures/show.html');
    $listDialog = (string) file_get_contents($root . '/public/assets/js/capture-lists.js');
    $remote = (string) file_get_contents($root . '/app/Services/RemoteContentService.php');
    $registry = (string) file_get_contents($root . '/app/Services/LinkPreview/ProviderRegistry.php');

    foreach (['GET /profile', 'GET /settings', 'GET /settings/devices'] as $required) {
        if (!str_contains($application, $required)) {
            throw new RuntimeException('Account route is missing: ' . $required);
        }
    }
    foreach (['data-user-menu', 'Profile', 'Settings', 'data-reload-app', 'Refresh', 'Log out'] as $required) {
        if (!str_contains($layout, $required)) {
            throw new RuntimeException('Account menu is incomplete: ' . $required);
        }
    }
    foreach (['data-theme-select', 'data-capture-view-setting', "@settingsTab=='devices'"] as $required) {
        if (!str_contains($settings, $required)) {
            throw new RuntimeException('Settings interface is incomplete: ' . $required);
        }
    }
    if (!str_contains($deviceTable, 'data-relative-suffix=" ago"')) {
        throw new RuntimeException('Device use time is not relative');
    }
    if (str_contains($capture, '<h2>Content</h2>')
        || str_contains($listDialog, 'Choose every list this capture should belong to.')) {
        throw new RuntimeException('Removed capture detail copy has returned');
    }
    foreach (['tiktok.com', 'apps.apple.com', 'itunes.apple.com'] as $providerDetail) {
        if (str_contains($remote, $providerDetail)) {
            throw new RuntimeException('Provider detail leaked into the generic preview service: ' . $providerDetail);
        }
    }
    foreach (['TikTokProvider', 'AppStoreProvider'] as $provider) {
        if (!str_contains($registry, $provider)) {
            throw new RuntimeException('Preview provider is not registered: ' . $provider);
        }
    }
});
$test('Email recipients and HTML content are normalized safely', function () {
    $sanitizer = new Catch\Services\EmailContentSanitizer();
    $reader = new Catch\Services\EmailMessageReader($sanitizer);
    $headers = "Delivered-To: ibx-abcdefghijklmnop@catch.sorkos.net\r\n"
        . "To: ibx-abcdefghijklmnopqrstuvwxyz@catch.sorkos.net\r\n"
        . "Cc: ibx-234567abcdefghij@catch.sorkos.net\r\n";
    $addresses = $reader->recipientAddresses($headers, 'catch.sorkos.net');
    if ($addresses !== [
        'ibx-abcdefghijklmnop@catch.sorkos.net',
        'ibx-234567abcdefghij@catch.sorkos.net',
    ]) {
        throw new RuntimeException('Catch recipients were not extracted from delivery headers');
    }

    $text = $sanitizer->htmlToText(<<<'HTML'
        <h2 id="tracked">Hello</h2>
        <p onclick="steal()"><strong>Safe</strong> <a href="https://example.com/path">link</a>
        <a href="javascript:alert(1)">bad link</a></p>
        <ul><li>First</li><li><em>Second</em></li></ul>
        <img src="https://tracker.test/pixel.gif"><script>alert(1)</script>
        HTML);
    foreach (['## Hello', '**Safe**', '[link](https://example.com/path)', '- First', '*Second*'] as $expected) {
        if (!str_contains($text, $expected)) {
            throw new RuntimeException('Sanitized email content lost allowed structure: ' . $expected);
        }
    }
    foreach (['javascript:', 'tracker.test', 'alert(1)', 'onclick', '<img'] as $unsafe) {
        if (str_contains($text, $unsafe)) {
            throw new RuntimeException('Sanitized email content retained unsafe input: ' . $unsafe);
        }
    }
});
$test('Email inbox addresses are compact and stored for repeated use', function () use ($root) {
    if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
        return;
    }
    $database = new PDO('sqlite::memory:');
    $database->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $database->sqliteCreateFunction('UTC_TIMESTAMP', static fn (): string => '2026-08-17 12:00:00.000000', -1);
    $database->exec(<<<'SQL'
        CREATE TABLE catch_email_inboxes (
            id TEXT PRIMARY KEY,
            user_id TEXT NOT NULL,
            address TEXT NOT NULL UNIQUE,
            created_at TEXT NOT NULL,
            revoked_at TEXT NULL
        )
        SQL);
    $repository = new Catch\Repositories\EmailInboxRepository($database, Catch\Core\Config::load($root));
    $first = $repository->create('user-1');
    $second = $repository->create('user-1');
    if (!preg_match('/^ibx-[a-z2-7]{16}@catch\.sorkos\.net$/', $first['address']) || $first['address'] === $second['address']) {
        throw new RuntimeException('Inbox addresses do not contain independent 80-bit Base32 tokens');
    }
    $storedQuery = $database->prepare('SELECT address FROM catch_email_inboxes WHERE id=:id');
    $storedQuery->execute(['id' => $first['id']]);
    $stored = (string) $storedQuery->fetchColumn();
    if ($stored !== $first['address']) {
        throw new RuntimeException('The generated inbox address was not stored directly');
    }
    $resolved = $repository->findActiveByAddress($first['address']);
    if (($resolved['user_id'] ?? null) !== 'user-1') {
        throw new RuntimeException('An active inbox address did not resolve');
    }
    $listed = $repository->all('user-1');
    $listedFirst = array_values(array_filter(
        $listed,
        static fn (array $inbox): bool => $inbox['id'] === $first['id'],
    ))[0] ?? null;
    if (($listedFirst['address'] ?? null) !== $first['address']) {
        throw new RuntimeException('The active inbox address cannot be displayed again');
    }
    $repository->revoke($first['id'], 'user-1');
    if ($repository->findActiveByAddress($first['address']) !== null) {
        throw new RuntimeException('A revoked inbox address still resolved');
    }
});
$test('Email importer remains folder-scoped and cron-safe', function () use ($root) {
    $migration = (string) file_get_contents($root . '/database/migrations/014_email_inboxes.sql')
        . (string) file_get_contents($root . '/database/migrations/015_email_inbox_raw_addresses.sql');
    $importer = (string) file_get_contents($root . '/app/Services/EmailImporter.php');
    $cli = (string) file_get_contents($root . '/cli/import-mail.php');
    $settings = (string) file_get_contents($root . '/app/Views/account/settings.html');
    $account = (string) file_get_contents($root . '/app/Controllers/Web/AccountController.php');
    foreach (['catch_email_inboxes', 'address VARCHAR(254)', 'catch_email_imports', 'message_key_hash'] as $required) {
        if (!str_contains($migration, $required)) {
            throw new RuntimeException('Email migration is missing ' . $required);
        }
    }
    foreach (['DROP COLUMN token_hash', 'ADD COLUMN address VARCHAR(254)'] as $required) {
        if (!str_contains($migration, $required)) {
            throw new RuntimeException('The one-way raw-address migration is missing ' . $required);
        }
    }
    foreach (['mail.imap_folder', 'imap_search', 'imap_mail_move', 'imap_delete', "'source' => 'email'", 'client_capture_id'] as $required) {
        if (!str_contains($importer, $required)) {
            throw new RuntimeException('Email importer is missing ' . $required);
        }
    }
    if (str_contains($importer, 'imap_fetchheader($connection, $uid, FT_UID | FT_PEEK)')) {
        throw new RuntimeException('The header fetch uses body-only FT_PEEK flags');
    }
    if (!str_contains($cli, 'LOCK_EX | LOCK_NB')) {
        throw new RuntimeException('Concurrent cron imports are not locked');
    }
    foreach (['email-address-row', 'data-copy-row', '@inbox.address', 'EmailInboxRepository'] as $required) {
        if (!str_contains($settings . $account . $cli, $required)) {
            throw new RuntimeException('Reusable email address UX is missing ' . $required);
        }
    }
    $editing = (string) file_get_contents($root . '/public/assets/js/capture-edit.js');
    foreach (['document.createElement(`h', "document.createElement('ol')", "document.createElement('pre')"] as $required) {
        if (!str_contains($editing, $required)) {
            throw new RuntimeException('Imported email structure is not rendered safely: ' . $required);
        }
    }
});
$test('Swagger UI is fully local', function () use ($root) {
    $index = (string)file_get_contents($root . '/public/docs/api/index.html');
    foreach (['swagger-ui.css','swagger-ui-bundle.js','swagger-ui-standalone-preset.js','LICENSE'] as $asset) {
        if (!is_file($root . '/public/vendor/swagger-ui/' . $asset)) {
            throw new RuntimeException('Missing Swagger UI vendor asset: ' . $asset);
        }
    }if (!str_contains($index, '/vendor/swagger-ui/') || preg_match('/(?:src|href)=["\']https?:/i', $index)) {
        throw new RuntimeException('Swagger UI has an external runtime dependency');
    }
});
exit($failures ? 1 : 0);
