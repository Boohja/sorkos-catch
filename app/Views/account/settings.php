<header class="page-heading account-page-heading">
  <div>
    <h1>Settings</h1>
    <p>Choose how Catch looks and works on this browser.</p>
  </div>
</header>

<nav class="filter-tabs settings-tabs" aria-label="Settings sections">
  <a href="/settings" <?=$settingsTab === 'general' ? 'aria-current="page"' : ''?>>General</a>
  <a href="/settings/devices" <?=$settingsTab === 'devices' ? 'aria-current="page"' : ''?>>Devices</a>
  <a href="/settings/email" <?=$settingsTab === 'email' ? 'aria-current="page"' : ''?>>Email</a>
</nav>

<?php if ($settingsTab === 'devices'): ?>
  <header class="settings-section-heading">
    <div>
      <h2>Devices</h2>
      <p>Every browser, extension, shortcut, and client connected to Catch.</p>
    </div>
    <a class="button button-primary" href="/devices/new">Add device</a>
  </header>
  <?php require dirname(__DIR__) . '/devices/_table.php'; ?>
<?php elseif ($settingsTab === 'email'): ?>
  <header class="settings-section-heading">
    <div>
      <h2>Email addresses</h2>
      <p>Forward an email to a private address to create a Catch.</p>
    </div>
    <form method="post" action="/settings/email">
      <input type="hidden" name="_csrf" value="<?=htmlspecialchars($csrf)?>">
      <button class="button button-primary" type="submit">Create address</button>
    </form>
  </header>

  <?php if (empty($emailInboxes)): ?>
    <section class="email-inbox-empty">
      <h3>No email addresses yet</h3>
      <p>Create an address, then save it in your contacts or forwarding rules.</p>
    </section>
  <?php else: ?>
    <div class="email-inboxes" role="list">
      <?php foreach ($emailInboxes as $inbox): ?>
        <?php $active = empty($inbox['revoked_at']); ?>
        <article class="email-inbox-row" role="listitem">
          <div class="email-inbox-main">
            <div class="email-address-row" data-copy-row>
              <code data-copy-source><?=htmlspecialchars((string) $inbox['address'])?></code>
              <?php if ($active): ?><button class="button button-secondary" type="button" data-copy-button>Copy</button><?php endif; ?>
            </div>
            <small>Created <?=htmlspecialchars((string) $inbox['created_at'])?></small>
          </div>
          <span class="email-inbox-status <?=$active ? 'is-active' : ''?>"><?=$active ? 'Active' : 'Revoked'?></span>
          <?php if ($active): ?>
            <form method="post" action="/settings/email/<?=htmlspecialchars((string) $inbox['id'])?>/revoke">
              <input type="hidden" name="_csrf" value="<?=htmlspecialchars($csrf)?>">
              <button class="button button-secondary" type="submit">Revoke</button>
            </form>
          <?php endif; ?>
        </article>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
<?php else: ?>
  <form class="preference-settings" data-preference-settings>
    <fieldset>
      <legend>Appearance</legend>
      <p>Use the system setting or choose a theme for this browser.</p>
      <label for="settings-theme">Theme</label>
      <select id="settings-theme" data-theme-select>
        <option value="system">System</option>
        <option value="light">Light</option>
        <option value="dark">Dark</option>
      </select>
    </fieldset>

    <fieldset>
      <legend>Capture lists</legend>
      <p>Choose the layout used when a capture collection opens.</p>
      <label for="settings-capture-view">Preferred layout</label>
      <select id="settings-capture-view" data-capture-view-setting>
        <option value="list">List</option>
        <option value="grid">Grid</option>
      </select>
    </fieldset>

    <p class="preference-status" data-preference-status role="status" aria-live="polite"></p>
  </form>
<?php endif; ?>
