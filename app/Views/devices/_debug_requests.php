<?php
$debugRequestHeading = $debugRequestHeading ?? 'Incoming capture requests';
?>
<section class="device-debug-requests<?=!empty($debugRequestCard) ? ' device-debug-requests-card' : ''?>">
  <header>
    <div>
      <p class="debug-label">Debug mode</p>
      <h2><?=htmlspecialchars($debugRequestHeading)?></h2>
    </div>
    <p><?= count($debugRequests) ?> recent <?= count($debugRequests) === 1 ? 'request' : 'requests' ?></p>
  </header>

  <?php if (!$debugRequests): ?>
    <p class="muted">No capture requests have been recorded for this device.</p>
  <?php else: ?>
    <div class="debug-request-list">
      <?php foreach ($debugRequests as $request): ?>
        <details class="debug-request">
          <summary>
            <span>
              <strong><?= htmlspecialchars($request['endpoint']) ?></strong>
              <small>
                <time
                  datetime="<?= htmlspecialchars($utc($request['created_at'])) ?>"
                  data-local-time
                  data-date-style="medium"
                  data-time-style="medium"
                >UTC <?= htmlspecialchars($request['created_at']) ?></time>
                &middot; <?= htmlspecialchars((string) ($request['remote_ip'] ?? 'unknown IP')) ?>
              </small>
            </span>
            <em class="debug-verdict debug-verdict-<?= htmlspecialchars($request['verdict']) ?>">
              Server verdict: <?= htmlspecialchars(str_replace('_', ' ', $request['verdict'])) ?>
              <?php if ($request['http_status'] !== null): ?>
                (<?= (int) $request['http_status'] ?>)
              <?php endif; ?>
            </em>
          </summary>

          <dl class="debug-request-context">
            <div><dt>Method</dt><dd><?= htmlspecialchars($request['method']) ?></dd></div>
            <div><dt>Content type</dt><dd><?= htmlspecialchars((string) ($request['content_type'] ?? 'not sent')) ?></dd></div>
            <div><dt>Content length</dt><dd><?= $request['content_length'] === null ? 'not sent' : number_format((int) $request['content_length']) . ' bytes' ?></dd></div>
            <div><dt>Token ID</dt><dd><code><?= htmlspecialchars($request['token_id']) ?></code></dd></div>
            <div><dt>Token scope</dt><dd><?= htmlspecialchars($request['token_scope']) ?></dd></div>
            <div><dt>Capture ID</dt><dd><?= $request['capture_id'] === null ? 'none' : '<code>' . htmlspecialchars($request['capture_id']) . '</code>' ?></dd></div>
            <?php if ($request['idempotency_key'] !== null): ?>
              <div><dt>Idempotency key</dt><dd><code><?= htmlspecialchars($request['idempotency_key']) ?></code></dd></div>
            <?php endif; ?>
            <?php if ($request['user_agent'] !== null): ?>
              <div class="debug-context-wide"><dt>User agent</dt><dd><?= htmlspecialchars($request['user_agent']) ?></dd></div>
            <?php endif; ?>
          </dl>

          <?php if ($request['error_message'] !== null): ?>
            <p class="debug-error"><?= htmlspecialchars($request['error_message']) ?></p>
          <?php endif; ?>

          <h3>Parameters</h3>
          <pre><code><?= htmlspecialchars($request['parameters_pretty']) ?></code></pre>

          <?php if ($request['files_pretty'] !== null): ?>
            <h3>Uploaded files</h3>
            <pre><code><?= htmlspecialchars($request['files_pretty']) ?></code></pre>
          <?php endif; ?>
        </details>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</section>
