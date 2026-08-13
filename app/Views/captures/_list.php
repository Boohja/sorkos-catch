<?php
$captureCollectionVariant = $captureCollectionVariant ?? 'switchable';
$captureShowActions = $captureShowActions ?? true;
$captureShowViewToggle = $captureShowViewToggle ?? $captureCollectionVariant === 'switchable';
$captureEmptyTitle = $captureEmptyTitle ?? (($status ?? null) === 'trash' ? 'Trash is empty' : 'Nothing here yet');
$captureEmptyText = $captureEmptyText ?? (($status ?? null) === 'trash'
    ? 'Captures you move to Trash will appear here for 30 days.'
    : 'Captures matching this view will appear here.');
?>
<?php if ($captureShowViewToggle): ?>
  <div
    class="capture-view-switch"
    role="group"
    aria-label="Capture layout"
    data-capture-view-switch
    <?=$captures ? '' : 'hidden'?>
  >
    <button type="button" data-capture-view="list" aria-label="List view" aria-pressed="true">
      <i class="glyph glyph-table" aria-hidden="true"></i>
    </button>
    <button type="button" data-capture-view="grid" aria-label="Grid view" aria-pressed="false">
      <i class="glyph glyph-grid" aria-hidden="true"></i>
    </button>
  </div>
<?php endif; ?>
<section
  class="capture-collection capture-collection-<?=htmlspecialchars($captureCollectionVariant)?>"
  aria-label="Captures"
  data-capture-list
  data-capture-collection
  data-csrf="<?=htmlspecialchars($csrf ?? '')?>"
  data-collection-status="<?=htmlspecialchars((string) ($status ?? ''))?>"
  data-collection-list-id="<?=htmlspecialchars((string) ($list['id'] ?? ''))?>"
  data-empty-title="<?=htmlspecialchars($captureEmptyTitle)?>"
  data-empty-text="<?=htmlspecialchars($captureEmptyText)?>"
  data-view="list"
>
  <?php if (!$captures): ?>
    <div class="empty-state">
      <h2><?=htmlspecialchars($captureEmptyTitle)?></h2>
      <p><?=htmlspecialchars($captureEmptyText)?></p>
    </div>
  <?php endif; ?>
  <?php foreach ($captures as $capture): ?>
    <?php require __DIR__ . '/_item.php'; ?>
  <?php endforeach; ?>
</section>
