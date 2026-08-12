<?php
$utc = static fn (?string $value): string => $value
    ? str_replace(' ', 'T', substr($value, 0, 19)) . 'Z'
    : '';
$clientLabels = [
    'web' => 'Web session',
    'extension' => 'Browser extension',
    'shortcut' => 'Shortcut',
    'api' => 'API client',
];
$platformLabels = [
    'ios' => 'iOS',
    'ipados' => 'iPadOS',
];
$deviceTypes = ['laptop', 'phone', 'pc', 'tablet'];
?>

<header class="page-heading">
  <div>
    <h1>Devices</h1>
    <p>Every browser, extension, and shortcut that can send content to your Catch inbox.</p>
  </div>
  <a class="button button-primary" href="/devices/new">Add device</a>
</header>

<?php if (empty($devices)): ?>
  <section class="device-empty-state">
    <div class="device-glyph" aria-hidden="true">+</div>
    <h2>No devices yet</h2>
    <p>Add an iPhone shortcut or capture something from this browser.</p>
    <a class="button button-primary" href="/devices/new">Add your first device</a>
  </section>
<?php else: ?>
  <div class="devices-table" role="list">
    <?php foreach ($devices as $device): ?>
      <?php
      $info = \Catch\Services\BrowserInfo::fromUserAgent((string) ($device['user_agent'] ?? ''));
        $typeLabel = $clientLabels[$device['client_type'] ?? 'shortcut'] ?? 'Device';
        $platformLabel = $platformLabels[$device['platform']] ?? ucfirst($device['platform']);
        $deviceType = in_array($device['device_type'] ?? '', $deviceTypes, true)
            ? $device['device_type']
            : 'pc';
        $environmentLabel = $device['user_agent']
            ? $info['browser'] . ' on ' . $info['os']
            : $platformLabel;
        ?>
      <article class="devices-table-row" role="listitem">
        <a class="device-main-link" href="<?= htmlspecialchars($device['url']) ?>">
          <span class="platform-mark" aria-hidden="true">
            <i class="glyph glyph-<?= htmlspecialchars($deviceType) ?>"></i>
          </span>
          <span>
            <strong><?= htmlspecialchars($device['name']) ?></strong>
            <small><?= htmlspecialchars($typeLabel) ?> &middot; <?= htmlspecialchars($environmentLabel) ?></small>
          </span>
        </a>

        <span class="device-capture-count">
          <?= (int) $device['capture_count'] ?>
          <?= (int) $device['capture_count'] === 1 ? 'capture' : 'captures' ?>
        </span>

        <span class="device-date">
          Last used:
          <?php if ($device['last_seen_at']): ?>
            <time
              datetime="<?= htmlspecialchars($utc($device['last_seen_at'])) ?>"
              data-local-time
              data-date-style="medium"
              data-time-style="short"
            >UTC <?= htmlspecialchars($device['last_seen_at']) ?></time>
          <?php else: ?>
            not yet
          <?php endif; ?>
        </span>

        <span class="connection-status connection-status-<?= htmlspecialchars($device['status']) ?>">
          <span aria-hidden="true"></span>
          <?= $device['status'] === 'connected' ? 'Connected' : 'Setup pending' ?>
        </span>
      </article>
    <?php endforeach; ?>
  </div>
<?php endif; ?>
