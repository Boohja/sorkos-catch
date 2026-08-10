<header class="page-heading"><div><h1><?= $status==='archived'?'Archive':'Inbox' ?></h1><p><?=count($captures)?> <?=count($captures)===1?'item':'items'?></p></div><div class="sync-summary" data-sync-summary>Everything synced</div></header>
<?php if(!empty($_SESSION['flash_error'])): ?><div class="alert alert-error" role="alert"><?=htmlspecialchars($_SESSION['flash_error']);unset($_SESSION['flash_error']);?></div><?php endif; ?>
<section class="capture-composer" aria-labelledby="capture-title">
  <form method="post" action="/captures" enctype="multipart/form-data" data-capture-form>
    <input type="hidden" name="_csrf" value="<?=htmlspecialchars($csrf)?>"><input type="hidden" name="client_capture_id" value=""><input type="hidden" name="type" value="text">
    <h2 id="capture-title">What do you want to capture?</h2>
    <label class="sr-only" for="capture-text">Text or URL</label>
    <textarea id="capture-text" name="text" rows="3" placeholder="Thought, task, or link…"></textarea>
    <div class="composer-actions"><label class="file-button"><input type="file" name="attachments[]" multiple accept="image/*,.pdf,.txt,audio/*"><span>Add file</span></label><button class="button button-primary" type="submit">Save</button></div>
  </form>
</section>
<nav class="filter-tabs" aria-label="Inbox filter"><a href="/inbox" <?=$status==='inbox'?'aria-current="page"':''?>>Current</a><a href="/inbox?status=archived" <?=$status==='archived'?'aria-current="page"':''?>>Archived</a></nav>
<section class="capture-list" aria-label="Captures" data-capture-list>
  <?php if(!$captures): ?><div class="empty-state"><div class="empty-glyph">C</div><h2>Nothing captured yet</h2><p>Add your first thought above or save a link.</p></div><?php endif; ?>
  <?php foreach($captures as $capture): ?>
  <article class="capture-row">
    <input class="capture-select" type="checkbox" value="<?=htmlspecialchars($capture['id'])?>" aria-label="Select capture #<?=(int)$capture['catch_number']?>">
    <a class="capture-content" href="/captures/<?=urlencode($capture['id'])?>">
      <?php $captureIcon=match($capture['type']){'url'=>'link','image'=>'image','audio'=>'voice',default=>'text'}; ?>
      <span class="type-icon" aria-hidden="true"><i class="glyph glyph-<?=htmlspecialchars($captureIcon)?>"></i></span>
      <span class="capture-number">#<?=(int)$capture['catch_number']?></span>
      <span class="capture-copy"><strong><?=htmlspecialchars($capture['title']?:mb_strimwidth($capture['text']?:$capture['url']?:'Attachment',0,90,'…'))?></strong><span><time datetime="<?=htmlspecialchars(str_replace(' ','T',substr($capture['created_at'],0,19)).'Z')?>" data-local-time data-date-style="medium" data-time-style="short">UTC <?=htmlspecialchars($capture['created_at'])?></time></span></span>
    </a>
    <form method="post" action="/captures/<?=urlencode($capture['id'])?>/<?=$status==='archived'?'delete':'archive'?>"><input type="hidden" name="_csrf" value="<?=htmlspecialchars($csrf)?>"><button class="icon-button" type="submit" aria-label="<?=$status==='archived'?'Delete':'Archive'?>"><?=$status==='archived'?'×':'✓'?></button></form>
  </article>
  <?php endforeach; ?>
</section>
