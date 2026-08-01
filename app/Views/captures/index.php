<header class="page-heading"><div><h1><?= $status==='archived'?'Archiv':'Inbox' ?></h1><p><?=count($captures)?> <?=count($captures)===1?'Eintrag':'Einträge'?></p></div><div class="sync-summary" data-sync-summary>Alles synchronisiert</div></header>
<?php if(!empty($_SESSION['flash_error'])): ?><div class="alert alert-error" role="alert"><?=htmlspecialchars($_SESSION['flash_error']);unset($_SESSION['flash_error']);?></div><?php endif; ?>
<section class="capture-composer" aria-labelledby="capture-title">
  <form method="post" action="/captures" enctype="multipart/form-data" data-capture-form>
    <input type="hidden" name="_csrf" value="<?=htmlspecialchars($csrf)?>"><input type="hidden" name="client_capture_id" value=""><input type="hidden" name="type" value="text">
    <h2 id="capture-title">Was möchtest du festhalten?</h2>
    <label class="sr-only" for="capture-text">Text oder URL</label>
    <textarea id="capture-text" name="text" rows="3" placeholder="Gedanke, Aufgabe oder Link ..."></textarea>
    <div class="composer-actions"><label class="file-button"><input type="file" name="attachments[]" multiple accept="image/*,.pdf,.txt,audio/*"><span>Datei hinzufügen</span></label><button class="button button-primary" type="submit">Speichern</button></div>
  </form>
</section>
<nav class="filter-tabs" aria-label="Inbox-Filter"><a href="/inbox" <?=$status==='inbox'?'aria-current="page"':''?>>Aktuell</a><a href="/inbox?status=archived" <?=$status==='archived'?'aria-current="page"':''?>>Archiviert</a></nav>
<section class="capture-list" aria-label="Captures" data-capture-list>
  <?php if(!$captures): ?><div class="empty-state"><div class="empty-glyph">C</div><h2>Noch nichts eingefangen</h2><p>Notiere oben deinen ersten Gedanken oder speichere einen Link.</p></div><?php endif; ?>
  <?php foreach($captures as $capture): ?>
  <article class="capture-row">
    <a class="capture-content" href="/captures/<?=urlencode($capture['id'])?>">
      <span class="type-icon" aria-hidden="true"><?=match($capture['type']){'url'=>'↗','image'=>'▣','file'=>'□','audio'=>'♪',default=>'T'}?></span>
      <span class="capture-copy"><strong><?=htmlspecialchars($capture['title']?:mb_strimwidth($capture['text']?:$capture['url']?:'Anhang',0,90,'…'))?></strong><span><?=htmlspecialchars($capture['source'])?> · <?=htmlspecialchars((new DateTime($capture['created_at']))->format('d.m.Y, H:i'))?><?=(int)$capture['attachment_count']>0?' · '.(int)$capture['attachment_count'].' Datei(en)':''?></span></span>
    </a>
    <form method="post" action="/captures/<?=urlencode($capture['id'])?>/<?=$status==='archived'?'delete':'archive'?>"><input type="hidden" name="_csrf" value="<?=htmlspecialchars($csrf)?>"><button class="icon-button" type="submit" aria-label="<?=$status==='archived'?'Löschen':'Archivieren'?>"><?=$status==='archived'?'×':'✓'?></button></form>
  </article>
  <?php endforeach; ?>
</section>
