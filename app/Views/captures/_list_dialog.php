<dialog class="confirm-dialog list-dialog" data-list-dialog aria-labelledby="list-dialog-title">
  <form method="post" data-list-form>
    <input type="hidden" name="_csrf" value="<?=htmlspecialchars($csrf)?>">
    <h2 id="list-dialog-title" data-list-dialog-title>Add to lists</h2>
    <p class="list-dialog-description" data-list-dialog-description hidden></p>
    <?php if ($availableLists): ?>
      <div class="list-checklist">
        <?php foreach ($availableLists as $list): ?>
          <label>
            <input type="checkbox" name="list_ids[]" value="<?=htmlspecialchars($list['id'])?>">
            <span><?=htmlspecialchars($list['title'])?></span>
          </label>
        <?php endforeach; ?>
      </div>
    <?php else: ?>
      <p>No lists yet. <a href="/lists">Create one first</a>.</p>
    <?php endif; ?>
    <p class="list-status" data-list-status role="status" aria-live="polite"></p>
    <div class="confirm-dialog-actions">
      <button class="button button-secondary" type="button" data-close-list-dialog>Cancel</button>
      <?php if ($availableLists): ?>
        <button class="button button-primary" type="submit" data-save-lists>Save lists</button>
      <?php endif; ?>
    </div>
  </form>
</dialog>
