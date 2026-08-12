<?php
$previewTitle = trim((string) ($linkPreview['title'] ?? $capture['title'] ?? ''));
$previewDescription = trim((string) ($linkPreview['description'] ?? ''));
$previewProvider = trim((string) ($linkPreview['provider_name'] ?? $sourceDomain));
$previewAuthor = trim((string) ($linkPreview['author_name'] ?? ''));
$previewImageUrl = '/attachments/' . urlencode($previewAttachment['id']);
?>
<div class="link-preview">
  <a
    class="link-preview-image"
    href="<?= htmlspecialchars((string) $capture['url']) ?>"
    target="_blank"
    rel="noopener noreferrer"
    aria-label="Open <?= htmlspecialchars($previewTitle ?: 'captured link') ?>"
  >
    <img
      src="<?= htmlspecialchars($previewImageUrl) ?>"
      alt=""
      width="<?= (int) ($previewAttachment['width'] ?: 800) ?>"
      height="<?= (int) ($previewAttachment['height'] ?: 450) ?>"
    >
  </a>
  <div class="link-preview-copy">
    <?php if ($previewProvider !== '' || $previewAuthor !== ''): ?>
      <p class="link-preview-byline">
        <?= htmlspecialchars($previewProvider) ?>
        <?php if ($previewProvider !== '' && $previewAuthor !== ''): ?><span aria-hidden="true">&middot;</span><?php endif; ?>
        <?= htmlspecialchars($previewAuthor) ?>
      </p>
    <?php endif; ?>
    <a
      class="link-preview-title"
      href="<?= htmlspecialchars((string) $capture['url']) ?>"
      target="_blank"
      rel="noopener noreferrer"
      data-url-fallback
    ><?= htmlspecialchars($previewTitle ?: (string) $capture['url']) ?></a>
    <?php if ($previewDescription !== ''): ?>
      <p class="link-preview-description"><?= htmlspecialchars($previewDescription) ?></p>
    <?php endif; ?>
    <small
      class="editable-url <?= $isTrashed ? '' : 'capture-editable' ?>"
      data-capture-field="url"
      data-single-line
      data-placeholder="Add URL"
      role="textbox"
      aria-label="Capture URL"
      <?= $isTrashed ? '' : 'contenteditable="true"' ?>
    ><?= htmlspecialchars((string) $capture['url']) ?></small>
  </div>
  <a
    class="url-card-open"
    data-open-capture-url
    href="<?= htmlspecialchars((string) $capture['url']) ?>"
    target="_blank"
    rel="noopener noreferrer"
    aria-label="Open captured URL"
  ><i class="glyph glyph-link" aria-hidden="true"></i></a>
</div>
