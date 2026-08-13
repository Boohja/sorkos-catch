<?php
$icon = match ($capture['type']) {
    'url' => 'link',
    'image' => 'image',
    'audio' => 'voice',
    default => 'text',
};
$captureTitle = $capture['title']
    ?: mb_strimwidth($capture['text'] ?: $capture['url'] ?: 'Attachment', 0, 90, '…');
$captureUrl = '/captures/' . urlencode($capture['id']);
$captureStatus = !empty($capture['deleted_at']) ? 'trash' : (string) ($capture['status'] ?? 'inbox');
$timeValue = $captureStatus === 'trash' ? $capture['deleted_at'] : $capture['created_at'];
$contentExcerpt = trim((string) ($capture['text'] ?: $capture['extracted_text'] ?? ''));
$host = $capture['url'] ? (string) parse_url((string) $capture['url'], PHP_URL_HOST) : '';
$host = preg_replace('/^www\./i', '', $host) ?: $host;
$assignedListIds = array_values(array_filter(explode(',', (string) ($capture['assigned_list_ids'] ?? ''))));
$previewFetch = is_array($capture['metadata']['link_preview_fetch'] ?? null)
    ? $capture['metadata']['link_preview_fetch']
    : [];
$previewRetryAt = strtotime((string) ($previewFetch['retry_at'] ?? ''));
$previewFetchDue = empty($capture['visual_attachment_id'])
    && $captureStatus !== 'trash'
    && !empty($capture['url'])
    && in_array((string) ($previewFetch['status'] ?? ''), ['pending', 'retry'], true)
    && (int) ($previewFetch['attempts'] ?? 0) < 3
    && ($previewRetryAt === false || $previewRetryAt <= time());
?>
<article
  class="capture-item capture-item-<?=htmlspecialchars($capture['type'])?>"
  data-capture-id="<?=htmlspecialchars($capture['id'])?>"
  <?=$previewFetchDue ? 'data-preview-fetch-due' : ''?>
>
  <a
    class="capture-visual <?=!empty($capture['visual_attachment_id']) ? 'capture-visual-image' : 'capture-visual-empty'?>"
    href="<?=htmlspecialchars($captureUrl)?>"
    aria-label="Open <?=htmlspecialchars($captureTitle)?>"
  >
    <?php if (!empty($capture['visual_attachment_id'])): ?>
      <img
        src="/attachments/<?=urlencode($capture['visual_attachment_id'])?>"
        alt=""
        width="640"
        height="360"
        loading="lazy"
        decoding="async"
      >
      <span class="capture-visual-fallback capture-media-fallback">
        <i class="glyph glyph-<?=htmlspecialchars($icon)?>" aria-hidden="true"></i>
        <span><?=htmlspecialchars($contentExcerpt ?: $captureTitle)?></span>
      </span>
    <?php elseif ($capture['type'] === 'url'): ?>
      <span class="capture-visual-fallback capture-url-fallback">
        <strong><?=htmlspecialchars($host ?: 'Link')?></strong>
        <span><?=htmlspecialchars((string) $capture['url'])?></span>
      </span>
    <?php else: ?>
      <span class="capture-visual-fallback capture-text-fallback">
        <?=nl2br(htmlspecialchars($contentExcerpt ?: $captureTitle))?>
      </span>
    <?php endif; ?>
  </a>
  <div class="capture-item-body">
    <span class="type-icon" aria-hidden="true">
      <i class="glyph glyph-<?=htmlspecialchars($icon)?>"></i>
    </span>
    <div class="capture-copy">
      <a class="capture-title-link" href="<?=htmlspecialchars($captureUrl)?>">
        <strong><?=htmlspecialchars($captureTitle)?></strong>
      </a>
      <?php if (!empty($capture['tags'])): ?>
        <span class="capture-row-tags">
          <?php foreach ($capture['tags'] as $tag): ?>
            <a class="tag-link" href="<?=htmlspecialchars($tag['url'])?>"><?=htmlspecialchars($tag['name'])?></a>
          <?php endforeach; ?>
        </span>
      <?php endif; ?>
    </div>
  </div>
  <footer class="capture-item-footer">
    <?php if (!empty($bulkFormId)): ?>
      <input
        class="capture-select"
        type="checkbox"
        name="capture_ids[]"
        value="<?=htmlspecialchars($capture['id'])?>"
        form="<?=htmlspecialchars($bulkFormId)?>"
        aria-label="Select capture #<?=(int) $capture['catch_number']?>"
      >
    <?php endif; ?>
    <span class="capture-meta">
      <span class="capture-number">#<?=(int) $capture['catch_number']?></span>
      <span aria-hidden="true"> · </span>
      <time
        datetime="<?=htmlspecialchars(str_replace(' ', 'T', substr((string) $timeValue, 0, 19)) . 'Z')?>"
        data-relative-time
        title="UTC <?=htmlspecialchars((string) $timeValue)?>"
      ><?=$this->relativeTime((string) $timeValue)?></time>
      <?php if ($captureCollectionVariant === 'compact'): ?>
        <span aria-hidden="true"> · </span><?=htmlspecialchars($captureStatus === 'trash' ? 'Trash' : ucfirst($captureStatus))?>
      <?php elseif ($captureStatus === 'trash'): ?>
        <span aria-hidden="true"> · </span>in Trash
      <?php endif; ?>
    </span>
    <?php if ($captureShowActions): ?>
      <?php if ($captureStatus === 'trash'): ?>
        <form
          method="post"
          action="/captures/<?=urlencode($capture['id'])?>/restore"
          data-capture-collection-action
        >
          <input type="hidden" name="_csrf" value="<?=htmlspecialchars($csrf)?>">
          <button class="button button-quiet" type="submit">Restore</button>
        </form>
      <?php else: ?>
        <button
          class="capture-menu-trigger"
          type="button"
          aria-label="Capture actions"
          aria-haspopup="menu"
          aria-expanded="false"
          data-capture-actions
          data-capture-id="<?=htmlspecialchars($capture['id'])?>"
          data-capture-status="<?=htmlspecialchars($captureStatus)?>"
          data-list-ids="<?=htmlspecialchars(json_encode($assignedListIds, JSON_THROW_ON_ERROR))?>"
        ><i class="glyph glyph-dots-vertical" aria-hidden="true"></i></button>
      <?php endif; ?>
    <?php endif; ?>
  </footer>
</article>
