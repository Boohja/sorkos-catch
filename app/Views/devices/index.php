<header class="page-heading"><div><h1>Devices</h1><p>Every connection that can send content to your Catch inbox.</p></div><a class="button button-primary" href="/devices/new">Add device</a></header>

<?php if(empty($devices)): ?>
<section class="device-empty-state"><div class="device-glyph" aria-hidden="true">+</div><h2>No devices yet</h2><p>Start with your iPhone or iPad. More platforms will follow.</p><a class="button button-primary" href="/devices/new">Add your first device</a></section>
<?php else: ?>
<div class="devices-table" role="list">
  <?php foreach($devices as $device): ?>
  <article class="devices-table-row" role="listitem">
    <a class="device-main-link" href="<?=htmlspecialchars($device['url'])?>"><span class="platform-mark" aria-hidden="true"><?=in_array($device['platform'],['ios','ipados'],true)?'i':'?'?></span><span><strong><?=htmlspecialchars($device['name'])?></strong><small><?=htmlspecialchars($device['platform']==='ipados'?'iPadOS':'iOS')?> · <?=htmlspecialchars($device['kind']==='mobile'?'Mobile':'Desktop')?></small></span></a>
    <span class="connection-status connection-status-<?=htmlspecialchars($device['status'])?>"><span aria-hidden="true"></span><?=$device['status']==='connected'?'Connected':'Setup pending'?></span>
    <span class="device-date"><?=$device['connected_at']?'Connected '.htmlspecialchars(date('M j, Y',strtotime($device['connected_at']))):'Added '.htmlspecialchars(date('M j, Y',strtotime($device['created_at'])))?></span>
    <a class="button button-quiet" href="<?=htmlspecialchars($device['url'])?>" aria-label="Open <?=htmlspecialchars($device['name'])?>">Open</a>
  </article>
  <?php endforeach; ?>
</div>
<?php endif; ?>
