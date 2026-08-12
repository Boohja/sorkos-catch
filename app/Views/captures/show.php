<?php
$metadata = is_array($capture['metadata'] ?? null) ? $capture['metadata'] : [];
$linkPreview = is_array($metadata['link_preview'] ?? null) ? $metadata['link_preview'] : [];
$utc = static fn (?string $value): string => $value ? str_replace(' ', 'T', substr($value, 0, 19)) . 'Z' : '';
$safeHttp = static fn (mixed $value): ?string => is_string($value) && preg_match('~^https?://~i', $value) ? $value : null;
$sourceUrl = $safeHttp($metadata['source_url'] ?? null) ?? $safeHttp($metadata['referring_page_url'] ?? null) ?? $safeHttp($capture['url'] ?? null);
$sourceTitle = trim((string)($metadata['source_title'] ?? $metadata['referring_page_title'] ?? $metadata['page_title'] ?? ''));
if ($sourceTitle === '' && $sourceUrl && $sourceUrl === $capture['url']) {
    $sourceTitle = trim((string)($capture['title'] ?? ''));
}
$sourceDomain = trim((string)($metadata['source_domain'] ?? ''));
if ($sourceDomain === '' && $sourceUrl) {
    $sourceDomain = (string)(parse_url($sourceUrl, PHP_URL_HOST) ?? '');
}
$linkedUrl = $safeHttp($metadata['linked_url'] ?? null);
$context = (string)($metadata['browser_context'] ?? '');
$method = (string)($metadata['capture_method'] ?? '');
if ($method === '') {
    $method = $capture['source'] === 'browser-extension' ? (str_contains($context, 'context-menu') ? 'browser-extension-context-menu' : 'browser-extension') : (string)$capture['source'];
}
$methodLabel = match($method) {
    'browser-extension-context-menu' => 'Browser Extension, Context Menu',
    'browser-extension' => 'Browser Extension',
    'ios-shortcut' => 'iOS Shortcut',
    'web' => 'Catch Web',
    default => ucwords(str_replace(['-','_'], ' ', $method)),
};
$primaryImage = null;
if ($capture['type'] === 'image') {
    foreach ($capture['attachments'] as $attachment) {
        if (($attachment['kind'] ?? 'source') === 'source' && str_starts_with((string)$attachment['mime_type'], 'image/')) {
            $primaryImage = $attachment;
            break;
        }
    }
}
$primaryAudio = null;
if ($capture['type'] === 'audio') {
    foreach ($capture['attachments'] as $attachment) {
        if (($attachment['kind'] ?? 'source') === 'source' && str_starts_with((string)$attachment['mime_type'], 'audio/')) {
            $primaryAudio = $attachment;
            break;
        }
    }
}
$isTrashed = !empty($capture['deleted_at']);
$previewAttachment = null;
foreach ($capture['attachments'] as $attachment) {
    if (($attachment['kind'] ?? 'source') === 'preview' && !empty($attachment['available'])) {
        $previewAttachment = $attachment;
        break;
    }
}
$previewFetch = is_array($metadata['link_preview_fetch'] ?? null)
    ? $metadata['link_preview_fetch']
    : ['status' => 'pending', 'attempts' => 0];
$previewRetryAt = strtotime((string)($previewFetch['retry_at'] ?? ''));
$previewFetchDue = !$previewAttachment
    && !$isTrashed
    && !empty($capture['url'])
    && in_array((string)($previewFetch['status'] ?? 'pending'), ['pending', 'retry'], true)
    && (int)($previewFetch['attempts'] ?? 0) < 3
    && ($previewRetryAt === false || $previewRetryAt <= time());
$textMatchesTitle = $capture['text'] && trim((string)$capture['text']) === trim((string)$capture['title']);
$urlIsPrimary = !$primaryImage && !empty($capture['url']) && ($capture['type'] === 'url' || empty($capture['text']) || $textMatchesTitle);
$deviceLabel = trim((string)($capture['device_name'] ?? '')) ?: match((string)$capture['source']) {
    'web' => 'Catch Web','browser-extension' => 'Browser Extension','ios-shortcut' => 'iOS Shortcut',default => ucwords(str_replace(['-','_'], ' ', (string)$capture['source']))
};
$assignedIds = array_column($capture['tags'] ?? [], 'id');
$assignedListIds = array_column($capture['lists'] ?? [], 'id');
$remainingAttachments = array_values(array_filter($capture['attachments'], static fn (array $attachment): bool => ($attachment['kind'] ?? 'source') === 'source' && (!$primaryImage || $attachment['id'] !== $primaryImage['id']) && (!$primaryAudio || $attachment['id'] !== $primaryAudio['id'])));
$trashExpires = $isTrashed ? date('Y-m-d H:i:s', strtotime((string)$capture['deleted_at'] . ' UTC +30 days')) : null;
$backRoute = $isTrashed ? '/trash' : ($capture['status'] === 'archived' ? '/archive' : '/inbox');
$backLabel = $isTrashed ? 'Trash' : ($capture['status'] === 'archived' ? 'Archived' : 'Inbox');
?>
<a class="back-link" href="<?=htmlspecialchars($backRoute)?>">&larr; Back to <?=$backLabel?></a>
<article
  class="detail"
  data-capture-detail
  data-capture-lists
  data-capture-id="<?=htmlspecialchars($capture['id'])?>"
  data-csrf="<?=htmlspecialchars($csrf)?>"
  <?=$capture['title'] ? 'data-has-title="true"' : ''?>
  <?=$previewFetchDue ? 'data-preview-fetch-due' : ''?>
>
  <header class="capture-detail-heading">
    <i class="glyph glyph-capture capture-detail-icon" aria-hidden="true"></i><div><h1><span class="capture-heading-number">#<?=(int)$capture['catch_number']?><span data-title-separator <?=empty($capture['title']) ? 'hidden' : ''?>> -</span></span> <span class="<?=$isTrashed ? '' : 'capture-editable'?><?=empty($capture['title']) ? ' is-empty' : ''?>" data-capture-field="title" data-single-line data-placeholder="Add title" role="textbox" aria-label="Capture title" <?=$isTrashed ? '' : 'contenteditable="true"'?>><?=htmlspecialchars((string)$capture['title'])?></span></h1>
    <p class="capture-heading-meta"><time datetime="<?=htmlspecialchars($utc($capture['created_at']))?>" data-local-time data-date-style="medium" data-time-style="short">UTC <?=htmlspecialchars($capture['created_at'])?></time><span class="capture-heading-lists" data-assigned-lists><?php foreach ($capture['lists'] ?? [] as $list): ?><a data-list-id="<?=htmlspecialchars($list['id'])?>" href="<?=htmlspecialchars($list['url'])?>"><?=htmlspecialchars($list['title'])?></a><?php endforeach; ?></span></p></div>
  </header>
  <?php if ($isTrashed): ?><section><div class="trash-notice"><strong>In Trash</strong><span>Permanently removed after <time datetime="<?=htmlspecialchars($utc($trashExpires))?>" data-local-time data-date-style="medium">UTC <?=htmlspecialchars($trashExpires)?></time></span></div></section><?php endif; ?>
  <section class="capture-primary"><h2>Content</h2>
    <?php if ($primaryImage): ?><?php $primaryImageUrl = '/attachments/' . urlencode($primaryImage['id']); ?>
      <?php if (!empty($primaryImage['available'])): ?><figure class="primary-image"><a href="<?=htmlspecialchars($primaryImageUrl)?>" target="_blank"><img src="<?=htmlspecialchars($primaryImageUrl)?>" alt="<?=htmlspecialchars($primaryImage['original_name'])?>"></a></figure>
      <?php else: ?><div class="missing-attachment" role="status"><strong><?=htmlspecialchars($primaryImage['original_name'])?></strong><span>The stored image file is unavailable.</span></div><?php endif; ?>
    <?php elseif ($primaryAudio): ?><?php $primaryAudioUrl = '/attachments/' . urlencode($primaryAudio['id']); ?>
      <?php if (!empty($primaryAudio['available'])): ?><div class="primary-audio"><audio controls preload="metadata" src="<?=htmlspecialchars($primaryAudioUrl)?>">Your browser does not support audio playback. <a href="<?=htmlspecialchars($primaryAudioUrl)?>">Download the recording</a>.</audio><span><?=htmlspecialchars($primaryAudio['original_name'])?> &middot; <?=htmlspecialchars($primaryAudio['mime_type'])?></span></div>
      <?php else: ?><div class="missing-attachment" role="status"><strong><?=htmlspecialchars($primaryAudio['original_name'])?></strong><span>The stored audio file is unavailable.</span></div><?php endif; ?>
    <?php elseif ($urlIsPrimary && $previewAttachment): ?><?php require __DIR__ . '/_link_preview.php'; ?>
    <?php elseif ($urlIsPrimary): ?><div class="url-card"><span><span data-url-fallback><?=htmlspecialchars($capture['title'] ?: $capture['url'])?></span><small class="editable-url <?=$isTrashed ? '' : 'capture-editable'?>" data-capture-field="url" data-single-line data-placeholder="Add URL" role="textbox" aria-label="Capture URL" <?=$isTrashed ? '' : 'contenteditable="true"'?>><?=htmlspecialchars((string)$capture['url'])?></small></span><a class="url-card-open" data-open-capture-url href="<?=htmlspecialchars($capture['url'])?>" target="_blank" rel="noopener noreferrer" aria-label="Open captured URL"><i class="glyph glyph-link" aria-hidden="true"></i></a></div>
    <?php elseif ($capture['text'] || $capture['type'] === 'text'): ?><div class="prose <?=$isTrashed ? '' : 'capture-editable'?>" data-capture-field="text" data-placeholder="Add text" role="textbox" aria-label="Capture text" aria-multiline="true" <?=$isTrashed ? '' : 'contenteditable="true"'?>><?=nl2br(htmlspecialchars((string)$capture['text']))?></div>
    <?php elseif ($capture['url']): ?><div class="url-card"><span><span data-url-fallback><?=htmlspecialchars($capture['title'] ?: $capture['url'])?></span><small class="editable-url <?=$isTrashed ? '' : 'capture-editable'?>" data-capture-field="url" data-single-line data-placeholder="Add URL" role="textbox" aria-label="Capture URL" <?=$isTrashed ? '' : 'contenteditable="true"'?>><?=htmlspecialchars((string)$capture['url'])?></small></span><a class="url-card-open" data-open-capture-url href="<?=htmlspecialchars($capture['url'])?>" target="_blank" rel="noopener noreferrer" aria-label="Open captured URL"><i class="glyph glyph-link" aria-hidden="true"></i></a></div>
    <?php else: ?><p class="muted">No preview is available.</p><?php endif; ?>
  </section>
  <?php if (in_array($capture['type'], ['audio','image'], true) && trim((string)$capture['extracted_text']) !== ''): ?><details><summary><?=$capture['type'] === 'audio' ? 'Transcript' : 'Extracted text'?></summary><div class="prose <?=$isTrashed ? '' : 'capture-editable'?>" data-capture-field="extracted_text" data-placeholder="Add extracted text" role="textbox" aria-label="<?=$capture['type'] === 'audio' ? 'Transcript' : 'Extracted text'?>" aria-multiline="true" <?=$isTrashed ? '' : 'contenteditable="true"'?>><?=nl2br(htmlspecialchars($capture['extracted_text']))?></div></details><?php endif; ?>
  <?php if ($remainingAttachments): ?><section><h2>Attachments</h2><div class="attachment-list"><?php foreach ($remainingAttachments as $attachment): ?><?php $attachmentUrl = '/attachments/' . urlencode($attachment['id']); ?>
    <?php if (str_starts_with($attachment['mime_type'], 'image/') && !empty($attachment['available'])): ?><figure class="image-attachment"><a href="<?=htmlspecialchars($attachmentUrl)?>" target="_blank"><img src="<?=htmlspecialchars($attachmentUrl)?>" alt="<?=htmlspecialchars($attachment['original_name'])?>" loading="lazy"></a><figcaption><strong><?=htmlspecialchars($attachment['original_name'])?></strong><span><?=htmlspecialchars($attachment['mime_type'])?> &middot; <?=number_format($attachment['size_bytes'] / 1024, 1, '.', ',')?> KB<?php if ($attachment['width'] && $attachment['height']): ?> &middot; <?=(int)$attachment['width']?> &times; <?=(int)$attachment['height']?><?php endif; ?></span></figcaption></figure>
    <?php elseif (str_starts_with($attachment['mime_type'], 'image/')): ?><div class="missing-attachment" role="status"><strong><?=htmlspecialchars($attachment['original_name'])?></strong><span>The stored image file is unavailable.</span></div>
    <?php elseif (str_starts_with($attachment['mime_type'], 'audio/') && !empty($attachment['available'])): ?><div class="audio-attachment"><audio controls preload="metadata" src="<?=htmlspecialchars($attachmentUrl)?>">Your browser does not support audio playback. <a href="<?=htmlspecialchars($attachmentUrl)?>">Download the recording</a>.</audio><span><strong><?=htmlspecialchars($attachment['original_name'])?></strong> &middot; <?=htmlspecialchars($attachment['mime_type'])?> &middot; <?=number_format($attachment['size_bytes'] / 1024, 1, '.', ',')?> KB</span></div>
    <?php elseif (str_starts_with($attachment['mime_type'], 'audio/')): ?><div class="missing-attachment" role="status"><strong><?=htmlspecialchars($attachment['original_name'])?></strong><span>The stored audio file is unavailable.</span></div>
    <?php else: ?><a class="file-attachment" href="<?=htmlspecialchars($attachmentUrl)?>"><strong><?=htmlspecialchars($attachment['original_name'])?></strong><span><?=htmlspecialchars($attachment['mime_type'])?> &middot; <?=number_format($attachment['size_bytes'] / 1024, 1, '.', ',')?> KB</span></a><?php endif; ?>
  <?php endforeach;?></div></section><?php endif; ?>
  <?php if (!$isTrashed): ?>
  <section class="capture-tags-panel" data-capture-tags data-capture-id="<?=htmlspecialchars($capture['id'])?>"><h2>Tags</h2><div class="assigned-tags" data-assigned-tags><?php foreach ($capture['tags'] ?? [] as $tag): ?><span class="assigned-tag" data-tag-id="<?=htmlspecialchars($tag['id'])?>"><a href="<?=htmlspecialchars($tag['url'])?>"><?=htmlspecialchars($tag['name'])?></a><button type="button" data-remove-tag aria-label="Remove <?=htmlspecialchars($tag['name'])?>">×</button></span><?php endforeach; ?></div><?php $unassigned = array_filter($availableTags, static fn (array $tag): bool => !in_array($tag['id'], $assignedIds, true)); ?><?php if ($availableTags): ?><form class="tag-assign-form" data-tag-assign method="post" action="/captures/<?=urlencode($capture['id'])?>/tags"><input type="hidden" name="_csrf" value="<?=htmlspecialchars($csrf)?>"><label class="sr-only" for="capture-tag">Tag</label><select id="capture-tag" name="tag_id" <?=!$unassigned ? 'disabled' : ''?>><?php foreach ($unassigned as $tag): ?><option value="<?=htmlspecialchars($tag['id'])?>"><?=htmlspecialchars($tag['name'])?></option><?php endforeach; ?></select><button class="button button-secondary" <?=!$unassigned ? 'disabled' : ''?>>Add tag</button></form><?php else: ?><p class="muted">No tags yet. <a href="/tags">Create one</a>.</p><?php endif; ?><p class="tag-status" data-tag-status role="status" aria-live="polite"></p></section>
  <?php endif; ?>
  <p class="edit-status" data-edit-status role="status" aria-live="polite"></p>
  <section class="capture-metadata"><h2>Captured from</h2><dl>
    <div><dt>Device</dt><dd class="metadata-device"><i class="glyph glyph-<?=htmlspecialchars((string)($capture['device_type'] ?? 'pc'))?>" aria-hidden="true"></i><?php if (!empty($capture['device_id'])): ?><a href="/devices/<?=urlencode($capture['device_id'])?>"><?=htmlspecialchars($deviceLabel)?></a><?php else: ?><?=htmlspecialchars($deviceLabel)?><?php endif; ?></dd></div>
    <?php if ($sourceUrl): ?><div><dt>Web</dt><dd><a href="<?=htmlspecialchars($sourceUrl)?>" target="_blank" rel="noopener noreferrer"><?=htmlspecialchars($sourceTitle ?: $sourceDomain ?: $sourceUrl)?></a><?php if ($linkedUrl): ?><small>Wrapping link: <a href="<?=htmlspecialchars($linkedUrl)?>" target="_blank" rel="noopener noreferrer"><?=htmlspecialchars($linkedUrl)?></a></small><?php endif; ?></dd></div><?php endif; ?>
    <div><dt>Method</dt><dd><?=htmlspecialchars($methodLabel)?></dd></div>
  </dl></section>
  <footer class="detail-actions"><?php if ($isTrashed): ?><form method="post" action="/captures/<?=urlencode($capture['id'])?>/restore"><input type="hidden" name="_csrf" value="<?=htmlspecialchars($csrf)?>"><button class="button button-primary">Restore</button></form><form method="post" action="/captures/<?=urlencode($capture['id'])?>/delete" onsubmit="return confirm('Permanently delete this capture and its attachments? This cannot be undone.')"><input type="hidden" name="_csrf" value="<?=htmlspecialchars($csrf)?>"><button class="button button-danger-outline"><i class="glyph glyph-trash" aria-hidden="true"></i>Delete permanently</button></form><?php else: ?><button class="button button-secondary" type="button" data-open-list-dialog data-capture-id="<?=htmlspecialchars($capture['id'])?>" data-list-ids="<?=htmlspecialchars(json_encode($assignedListIds, JSON_THROW_ON_ERROR))?>"><i class="glyph glyph-list" aria-hidden="true"></i>Add to list</button><form method="post" action="/captures/<?=urlencode($capture['id'])?>/archive" data-archive-action <?=$capture['status'] === 'inbox' ? '' : 'hidden'?>> <input type="hidden" name="_csrf" value="<?=htmlspecialchars($csrf)?>"><button class="button button-secondary"><i class="glyph glyph-archive" aria-hidden="true"></i>Archive</button></form><form method="post" action="/captures/<?=urlencode($capture['id'])?>/delete"><input type="hidden" name="_csrf" value="<?=htmlspecialchars($csrf)?>"><button class="button button-danger-outline"><i class="glyph glyph-trash" aria-hidden="true"></i>Move to Trash</button></form><?php endif; ?></footer>
</article>
