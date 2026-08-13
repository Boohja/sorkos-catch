<?php
$utc = static fn (?string $value): string => $value ? str_replace(' ', 'T', substr($value, 0, 19)) . 'Z' : '';
$info = \Catch\Services\BrowserInfo::fromUserAgent((string)($device['user_agent'] ?? ''));
$typeLabel = ['web' => 'Web session','extension' => 'Browser extension','shortcut' => 'Shortcut','api' => 'API client','cli' => 'CLI client'][$device['client_type'] ?? 'shortcut'] ?? 'Device';
$platformLabel = $device['user_agent'] ? $info['browser'] . ' on ' . $info['os'] : (['ios' => 'iOS','ipados' => 'iPadOS'][$device['platform']] ?? ucfirst($device['platform']));
$deviceTypes = ['laptop' => 'Laptop','phone' => 'Phone','pc' => 'PC','tablet' => 'Tablet','extension' => 'Extension','cli' => 'CLI'];
$deviceType = array_key_exists($device['device_type'] ?? '', $deviceTypes) ? $device['device_type'] : 'pc';
?>
<a class="back-link" href="/settings/devices">&larr; All devices</a>
<header class="device-detail-heading"><div><span class="connection-status connection-status-<?=htmlspecialchars($device['status'])?>"><span aria-hidden="true"></span><?=$device['status'] === 'connected' ? 'Connected' : ($device['status'] === 'revoked' ? 'Access removed' : 'Setup pending')?></span><h1><?=htmlspecialchars($device['name'])?></h1><p><?=htmlspecialchars($platformLabel)?> &middot; <?=htmlspecialchars($typeLabel)?></p></div><?php if ($device['status'] !== 'revoked'): ?><form method="post" action="<?=htmlspecialchars($deviceUrl)?>/delete" onsubmit="return confirm('Remove this device and revoke its access?')"><input type="hidden" name="_csrf" value="<?=htmlspecialchars($csrf)?>"><button class="button button-danger-outline" type="submit">Remove device</button></form><?php endif; ?></header>

<?php if ($device['status'] === 'revoked'): ?>
<section class="connected-summary revoked-summary"><div class="connected-check" aria-hidden="true">&times;</div><div><h2>Access was removed</h2><p>This record remains available so older captures can keep their device provenance. Its token can no longer create captures.</p></div></section>
<?php elseif ($device['status'] === 'setup'): ?>
<section class="pairing-flow" data-device-status-url="<?=htmlspecialchars($deviceUrl)?>/status" data-polling="<?=isset($device['pairing_code']) ? 'true' : 'false'?>"<?php if (isset($device['pairing_code'])):?> data-pairing-code-expires-at="<?=htmlspecialchars($device['pairing_code_expires_at'])?>"<?php endif;?>>
  <ol class="pairing-steps">
    <li class="pairing-step pairing-step-download pairing-step-active"><span>1</span><div class="shortcut-install-options"><div><h2>Download the setup shortcut</h2><p>Open the file on your iPhone or iPad. The shortcut creates a secure connection to Catch.</p><a class="button button-primary" href="<?=htmlspecialchars($shortcutUrl)?>" download>Download Catch Setup</a></div><div class="shortcut-choice-divider" aria-hidden="true"><span>OR</span></div><div class="shortcut-qr"><div data-qr-code data-qr-value="<?=htmlspecialchars($shortcutUrl)?>" aria-label="QR code for the shortcut download"></div><small>Scan with your device camera</small></div></div></li>
    <li class="pairing-step <?=isset($device['pairing_code']) ? 'pairing-step-active' : ''?>"><span>2</span><div><h2>Enter the pairing code</h2><?php if (isset($device['pairing_code'])):?><p>Enter this 10-digit code when the setup shortcut asks for it.<span class="pairing-expiry">It expires in <strong data-pairing-countdown>--:--</strong> minutes.</span></p><div class="pairing-code-row" data-copy-row><code data-copy-source><?=htmlspecialchars($device['pairing_code'])?></code><button class="button button-secondary" type="button" data-copy-button>Copy code</button></div><?php else:?><p>Create the code only after opening the setup shortcut on this device. It will be valid for <?=htmlspecialchars((string)$pairingCodeTtlMinutes)?> minutes.</p><form method="post" action="<?=htmlspecialchars($deviceUrl)?>/pairing-code"><input type="hidden" name="_csrf" value="<?=htmlspecialchars($csrf)?>"><button class="button button-secondary" type="submit">Create pairing code</button></form><?php endif;?></div></li>
    <li class="pairing-step <?=isset($device['pairing_code']) ? 'pairing-step-waiting' : ''?>"><span>3</span><div><h2>Wait for the connection</h2><p data-pairing-status><?=isset($device['pairing_code']) ? 'This page automatically checks whether the device is connected.' : 'After creating the code, Catch waits for the first connection.'?></p><?php if (isset($device['pairing_code'])):?><div class="waiting-indicator" role="status"><span aria-hidden="true"></span>Checking every 7 seconds</div><?php endif;?></div></li>
  </ol>
</section>
<?php else: ?>
<section class="connected-summary"><div class="connected-check" aria-hidden="true">&#10003;</div><div><h2>This device is connected</h2><dl><div><dt>Connected</dt><dd><time datetime="<?=htmlspecialchars($utc($device['connected_at']))?>" data-local-time data-date-style="medium" data-time-style="short">UTC <?=htmlspecialchars($device['connected_at'])?></time></dd></div><div><dt>Last used</dt><dd><?php if ($device['last_seen_at']):?><time datetime="<?=htmlspecialchars($utc($device['last_seen_at']))?>" data-local-time data-date-style="medium" data-time-style="short">UTC <?=htmlspecialchars($device['last_seen_at'])?></time><?php else:?>Not yet<?php endif;?></dd></div><div><dt>Captures</dt><dd><?=(int)$device['capture_count']?></dd></div></dl></div></section>
<?php endif; ?>

<?php if ($device['status'] !== 'revoked'): ?><section class="settings-section device-identity"><h2>Name this device</h2><p>Use a name and icon that distinguish it from your other devices.</p><form class="device-identity-form" method="post" action="<?=htmlspecialchars($deviceUrl)?>/rename"><input type="hidden" name="_csrf" value="<?=htmlspecialchars($csrf)?>"><fieldset class="device-type-picker"><legend class="sr-only">Device icon</legend><?php foreach ($deviceTypes as $value => $label): ?><label title="<?=htmlspecialchars($label)?>"><input type="radio" name="device_type" value="<?=htmlspecialchars($value)?>" <?=$deviceType === $value ? 'checked' : ''?>><i class="glyph glyph-<?=htmlspecialchars($value)?>" aria-hidden="true"></i><span class="sr-only"><?=htmlspecialchars($label)?></span></label><?php endforeach; ?></fieldset><label class="device-name-input"><span class="sr-only">Device name</span><input name="name" maxlength="120" required value="<?=htmlspecialchars($device['name'])?>"></label><button class="button button-secondary" type="submit">Save name</button></form><?php if (!empty($device['user_agent'])): ?><details><summary>Technical browser information</summary><code><?=htmlspecialchars($device['user_agent'])?></code></details><?php endif; ?></section><?php endif; ?>

<section class="device-captures">
  <header>
    <h2>Captures from this device</h2>
    <p><?=count($captures)?> <?=count($captures) === 1 ? 'capture' : 'captures'?></p>
  </header>
  <?php
$captureCollectionVariant = 'compact';
$captureShowActions = false;
$captureShowViewToggle = false;
$captureEmptyTitle = 'No captures yet';
$captureEmptyText = 'No captures have been created by this device.';
require dirname(__DIR__) . '/captures/_list.php';
?>
</section>

<?php if ($debugEnabled): ?>
  <?php require __DIR__ . '/_debug_requests.php'; ?>
<?php endif; ?>

<?php if ($device['status'] === 'setup'): ?><script src="/assets/vendor/qrcode.js"></script><?php endif; ?>
