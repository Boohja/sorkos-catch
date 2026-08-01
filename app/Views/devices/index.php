<header class="page-heading"><div><h1>Geräte</h1><p>Alle Wege, über die Inhalte in deiner Catch-Inbox landen.</p></div><a class="button button-primary" href="/devices/new">Gerät hinzufügen</a></header>

<?php if(empty($devices)): ?>
<section class="device-empty-state"><div class="device-glyph" aria-hidden="true">+</div><h2>Noch kein Gerät verbunden</h2><p>Starte mit deinem iPhone oder iPad. Weitere Plattformen kommen später hinzu.</p><a class="button button-primary" href="/devices/new">Erstes Gerät hinzufügen</a></section>
<?php else: ?>
<div class="devices-table" role="list">
  <?php foreach($devices as $device): ?>
  <article class="devices-table-row" role="listitem">
    <a class="device-main-link" href="<?=htmlspecialchars($device['url'])?>"><span class="platform-mark" aria-hidden="true"><?=in_array($device['platform'],['ios','ipados'],true)?'i':'?'?></span><span><strong><?=htmlspecialchars($device['name'])?></strong><small><?=htmlspecialchars($device['platform']==='ipados'?'iPadOS':'iOS')?> · <?=htmlspecialchars($device['kind']==='mobile'?'Mobil':'Desktop')?></small></span></a>
    <span class="connection-status connection-status-<?=htmlspecialchars($device['status'])?>"><span aria-hidden="true"></span><?=$device['status']==='connected'?'Verbunden':'Einrichtung offen'?></span>
    <span class="device-date"><?=$device['connected_at']?'Verbunden seit '.htmlspecialchars(date('d.m.Y',strtotime($device['connected_at']))):'Angelegt am '.htmlspecialchars(date('d.m.Y',strtotime($device['created_at'])))?></span>
    <a class="button button-quiet" href="<?=htmlspecialchars($device['url'])?>" aria-label="<?=htmlspecialchars($device['name'])?> öffnen">Öffnen</a>
  </article>
  <?php endforeach; ?>
</div>
<?php endif; ?>
