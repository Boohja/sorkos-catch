<div class="capture-action-menu" data-capture-action-menu role="menu" hidden>
  <button type="button" role="menuitem" data-menu-list>
    <i class="glyph glyph-list" aria-hidden="true"></i>
    <span>Add to list</span>
  </button>
  <form method="post" data-menu-archive>
    <input type="hidden" name="_csrf" value="<?=htmlspecialchars($csrf)?>">
    <button type="submit" role="menuitem">
      <i class="glyph glyph-archive" aria-hidden="true"></i>
      <span>Archive</span>
    </button>
  </form>
  <form method="post" data-menu-trash>
    <input type="hidden" name="_csrf" value="<?=htmlspecialchars($csrf)?>">
    <button type="submit" role="menuitem" class="capture-action-danger">
      <i class="glyph glyph-trash" aria-hidden="true"></i>
      <span>Move to Trash</span>
    </button>
  </form>
</div>
