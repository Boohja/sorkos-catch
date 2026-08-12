<?php $heading = match($status) {
    'archived' => 'Archived','trash' => 'Trash',default => 'Inbox'
}; ?>
<header class="page-heading"><div><h1><?=$heading?></h1><p><?php if ($status === 'trash'): ?>Captures stay here for 30 days before permanent deletion.<?php else: ?><?=count($captures)?> <?=count($captures) === 1 ? 'item' : 'items'?><?php endif; ?></p></div><div class="sync-summary" data-sync-summary>Everything synced</div></header>
<?php if (!empty($_SESSION['flash_error'])): ?><div class="alert alert-error" role="alert"><?=htmlspecialchars($_SESSION['flash_error']);
    unset($_SESSION['flash_error']);?></div><?php endif; ?><?php if (!empty($_SESSION['flash_success'])): ?><div class="alert alert-success" role="status"><?=htmlspecialchars($_SESSION['flash_success']);
        unset($_SESSION['flash_success']);?></div><?php endif; ?>
<?php if ($status === 'inbox'): ?><section class="capture-composer" aria-labelledby="capture-title"><form method="post" action="/captures" enctype="multipart/form-data" data-capture-form><input type="hidden" name="_csrf" value="<?=htmlspecialchars($csrf)?>"><input type="hidden" name="client_capture_id" value=""><input type="hidden" name="type" value="text"><h2 id="capture-title">What do you want to capture?</h2><label class="sr-only" for="capture-text">Text or URL</label><textarea id="capture-text" name="text" rows="3" placeholder="Thought, task, or link…"></textarea><div class="composer-actions"><label class="file-button"><input type="file" name="attachments[]" multiple accept="image/*,.pdf,.txt,audio/*"><span>Add file</span></label><button class="button button-primary" type="submit">Save</button></div></form></section><?php endif; ?>
<nav class="filter-tabs" aria-label="Capture views"><a href="/inbox" <?=$status === 'inbox' ? 'aria-current="page"' : ''?>><i class="glyph glyph-inbox" aria-hidden="true"></i>Inbox</a><a href="/archive" <?=$status === 'archived' ? 'aria-current="page"' : ''?>><i class="glyph glyph-archive" aria-hidden="true"></i>Archived</a><a href="/trash" <?=$status === 'trash' ? 'aria-current="page"' : ''?>><i class="glyph glyph-trash" aria-hidden="true"></i>Trash</a></nav>
<?php $bulkFormId = 'capture-bulk-form';
require __DIR__ . '/_list.php'; ?>
<?php if ($captures): ?>
  <?php $permanent = $status === 'trash'; ?>
  <form
    id="capture-bulk-form"
    class="bulk-actions"
    method="post"
    action="/captures/bulk-delete"
    data-bulk-actions
    data-permanent="<?=$permanent ? 'true' : 'false'?>"
    hidden
  >
    <input type="hidden" name="_csrf" value="<?=htmlspecialchars($csrf)?>">
    <input type="hidden" name="view" value="<?=htmlspecialchars($status)?>">
    <span class="bulk-selection-count" data-bulk-count role="status" aria-live="polite"></span>
    <?php if (!$permanent): ?>
      <button class="button button-secondary" type="button" data-open-bulk-lists>
        <i class="glyph glyph-list" aria-hidden="true"></i>Add to list
      </button>
    <?php endif; ?>
    <?php if ($status === 'inbox'): ?>
      <button class="button button-secondary" type="submit" formaction="/captures/bulk-archive">
        <i class="glyph glyph-archive" aria-hidden="true"></i>Archive
      </button>
    <?php endif; ?>
    <button class="button button-danger-outline" type="button" data-open-bulk-delete>
      <i class="glyph glyph-trash" aria-hidden="true"></i><?=$permanent ? 'Delete permanently' : 'Move to Trash'?>
    </button>
  </form>
  <dialog class="confirm-dialog" data-bulk-delete-dialog aria-labelledby="bulk-delete-title" aria-describedby="bulk-delete-description">
    <form method="dialog">
      <h2 id="bulk-delete-title"><?=$permanent ? 'Permanently delete captures?' : 'Move captures to Trash?'?></h2>
      <p id="bulk-delete-description" data-bulk-delete-description><?=$permanent ? 'The selected captures and their attachments will be permanently deleted. This action cannot be undone.' : 'The selected captures can be restored from Trash for 30 days.'?></p>
      <div class="confirm-dialog-actions">
        <button class="button button-secondary" value="cancel" autofocus>Cancel</button>
        <button class="button button-danger-outline" type="submit" form="capture-bulk-form" data-confirm-bulk-delete>
          <i class="glyph glyph-trash" aria-hidden="true"></i><?=$permanent ? 'Delete permanently' : 'Move to Trash'?>
        </button>
      </div>
    </form>
  </dialog>
<?php endif; ?>
