<?php $utc=static fn(?string $value): string=>$value?str_replace(' ','T',substr($value,0,19)).'Z':''; ?>
<header class="page-heading"><div><h1>Devices</h1><p>Every browser, extension, and shortcut that can send content to your Catch inbox.</p></div><a class="button button-primary" href="/devices/new">Add device</a></header>

<?php if(empty($devices)): ?>
<section class="device-empty-state"><div class="device-glyph" aria-hidden="true">+</div><h2>No devices yet</h2><p>Add an iPhone shortcut or capture something from this browser.</p><a class="button button-primary" href="/devices/new">Add your first device</a></section>
<?php else: ?>
<div class="devices-table" role="list">
  <?php foreach($devices as $device): $info=\Catch\Services\BrowserInfo::fromUserAgent((string)($device['user_agent']??'')); $typeLabel=['web'=>'Web session','extension'=>'Browser extension','shortcut'=>'Shortcut','api'=>'API client'][$device['client_type']??'shortcut']??'Device'; $platformLabel=['ios'=>'iOS','ipados'=>'iPadOS'][$device['platform']]??ucfirst($device['platform']); ?>
  <article class="devices-table-row" role="listitem">
    <a class="device-main-link" href="<?=htmlspecialchars($device['url'])?>"><span class="platform-mark" aria-hidden="true"><?=htmlspecialchars(strtoupper(substr($device['platform'],0,1)))?></span><span><strong><?=htmlspecialchars($device['name'])?></strong><small><?=htmlspecialchars($typeLabel)?> &middot; <?=htmlspecialchars($device['user_agent']?$info['browser'].' on '.$info['os']:$platformLabel)?></small></span></a>
    <span class="connection-status connection-status-<?=htmlspecialchars($device['status'])?>"><span aria-hidden="true"></span><?=$device['status']==='connected'?'Connected':'Setup pending'?></span>
    <span class="device-date"><?=$device['connected_at']?'Connected ':'Added '?><time datetime="<?=htmlspecialchars($utc($device['connected_at']?:$device['created_at']))?>" data-local-time data-date-style="medium">UTC <?=htmlspecialchars($device['connected_at']?:$device['created_at'])?></time></span>
    <a class="button button-quiet" href="<?=htmlspecialchars($device['url'])?>" aria-label="Open <?=htmlspecialchars($device['name'])?>">Open</a>
  </article>
  <?php endforeach; ?>
</div>
<?php endif; ?>
