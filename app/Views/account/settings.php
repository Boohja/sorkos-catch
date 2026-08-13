<header class="page-heading account-page-heading">
  <div>
    <h1>Settings</h1>
    <p>Choose how Catch looks and works on this browser.</p>
  </div>
</header>

<nav class="filter-tabs settings-tabs" aria-label="Settings sections">
  <a href="/settings" <?=$settingsTab === 'general' ? 'aria-current="page"' : ''?>>General</a>
  <a href="/settings/devices" <?=$settingsTab === 'devices' ? 'aria-current="page"' : ''?>>Devices</a>
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
