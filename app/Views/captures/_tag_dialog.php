<dialog class="confirm-dialog tag-dialog" data-tag-dialog aria-labelledby="tag-dialog-title">
  <form method="post" action="/captures/<?=urlencode($capture['id'])?>/tags" data-tag-form>
    <input type="hidden" name="_csrf" value="<?=htmlspecialchars($csrf)?>">
    <h2 id="tag-dialog-title">Tags</h2>
    <label class="tag-dialog-field" for="capture-tag-name">
      <span class="sr-only">Find or create a tag</span>
      <input id="capture-tag-name" name="name" type="text" list="capture-tag-options" maxlength="100" autocomplete="off" placeholder="Find or create a tag" data-tag-input>
    </label>
    <datalist id="capture-tag-options" data-tag-options>
      <?php foreach ($availableTags as $tag): ?><option value="<?=htmlspecialchars($tag['name'])?>"></option><?php endforeach; ?>
    </datalist>
    <div class="tag-dialog-assigned">
      <div class="assigned-tags" data-assigned-tags aria-label="Assigned tags">
        <?php foreach ($capture['tags'] ?? [] as $tag): ?><span class="assigned-tag" data-tag-id="<?=htmlspecialchars($tag['id'])?>"><a href="<?=htmlspecialchars($tag['url'])?>"><?=htmlspecialchars($tag['name'])?></a><button type="button" data-remove-tag aria-label="Remove <?=htmlspecialchars($tag['name'])?>">&times;</button></span><?php endforeach; ?>
      </div>
      <p class="tag-dialog-empty" data-tag-empty <?=!empty($capture['tags']) ? 'hidden' : ''?>>No tags assigned.</p>
    </div>
    <div class="confirm-dialog-actions">
      <button class="button button-secondary" type="button" data-close-tag-dialog>Close</button>
    </div>
  </form>
</dialog>
