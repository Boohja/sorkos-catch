<?php $currentPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$isAuthenticated = isset($user) && is_array($user);
$isComingSoon = $currentPath === '/coming-soon';
$displayName = $isAuthenticated ? (trim((string) ($user['display_name'] ?? '')) ?: 'Catch User') : '';
$avatarUrl = $isAuthenticated ? trim((string) ($user['avatar_url'] ?? '')) : '';
$avatarInitial = $displayName !== '' ? mb_strtoupper(mb_substr($displayName, 0, 1)) : 'C';
$layoutCsrf = (string) ($csrf ?? $_SESSION['_csrf'] ?? '');
$flashMessages = [];
if (!empty($_SESSION['flash_error'])) {
    $flashMessages[] = ['type' => 'error', 'message' => (string) $_SESSION['flash_error']];
    unset($_SESSION['flash_error']);
}
if (!empty($_SESSION['flash_success'])) {
    $flashMessages[] = ['type' => 'success', 'message' => (string) $_SESSION['flash_success']];
    unset($_SESSION['flash_success']);
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
  <meta name="theme-color" content="#18181b">
  <meta name="description" content="Catch is your personal capture inbox.">
  <link rel="manifest" href="/manifest.webmanifest">
  <link rel="icon" href="/assets/favicon/favicon.ico" sizes="any">
  <link rel="icon" href="/assets/favicon/favicon.svg" type="image/svg+xml">
  <link rel="icon" href="/assets/favicon/favicon-32x32.png" type="image/png" sizes="32x32">
  <link rel="icon" href="/assets/favicon/favicon-16x16.png" type="image/png" sizes="16x16">
  <link rel="apple-touch-icon" href="/assets/favicon/apple-touch-icon.png" sizes="180x180">
  <link rel="stylesheet" href="https://glyph.sorkos.net/cdn/fonts/a588550d09ff860a08cf6a3dceac2747.css">
  <link rel="stylesheet" href="/assets/css/app.css?v=4">
  <link rel="stylesheet" href="/assets/css/components.css?v=4">
  <link rel="stylesheet" href="/assets/css/auth.css?v=2">
  <link rel="stylesheet" href="/assets/css/devices.css?v=5">
  <link rel="stylesheet" href="/assets/css/device-detail.css?v=5">
  <link rel="stylesheet" href="/assets/css/device-provenance.css?v=1">
  <link rel="stylesheet" href="/assets/css/capture-detail.css?v=12">
  <link rel="stylesheet" href="/assets/css/capture-collection.css?v=5">
  <link rel="stylesheet" href="/assets/css/capture-bulk.css?v=2">
  <link rel="stylesheet" href="/assets/css/tags.css?v=1">
  <link rel="stylesheet" href="/assets/css/lists.css?v=2">
  <link rel="stylesheet" href="/assets/css/pair.css?v=1">
  <link rel="stylesheet" href="/assets/css/coming-soon.css?v=2">
  <link rel="stylesheet" href="/assets/css/shell.css?v=3">
  <link rel="stylesheet" href="/assets/css/account.css?v=2">
  <link rel="stylesheet" href="/assets/css/layout-compat.css?v=1">
  <script>try{const t=localStorage.getItem('catch-theme');if(t&&t!=='system')document.documentElement.dataset.theme=t}catch(e){}</script>
  <title><?=htmlspecialchars($title ?? 'Catch')?> | Catch</title>
</head>
<body class="<?=$isComingSoon ? 'coming-soon-page' : ''?>">
  <div class="site-shell">
    <a class="skip-link" href="#content">Skip to content</a>
    <?php if ($isAuthenticated && !$isComingSoon): ?>
    <header class="app-header">
      <a class="brand brand-nav" href="/inbox" aria-label="Catch Inbox"><img src="/assets/logo/landscape_dark_small.png" alt="Catch" width="104" height="37"></a>
      <nav aria-label="Main navigation">
        <a href="/inbox" <?=$currentPath === '/inbox' ? 'aria-current="page"' : ''?>>Inbox</a>
        <a href="/tags" <?=str_starts_with($currentPath, '/tags') ? 'aria-current="page"' : ''?>>Tags</a>
        <a href="/lists" <?=str_starts_with($currentPath, '/lists') ? 'aria-current="page"' : ''?>>Lists</a>
        <a href="/help" <?=$currentPath === '/help' ? 'aria-current="page"' : ''?>>Help</a>
      </nav>
      <div class="header-actions">
        <div class="user-menu" data-user-menu>
          <button
            class="user-avatar-button"
            type="button"
            aria-expanded="false"
            aria-haspopup="menu"
            aria-controls="user-menu-panel"
            data-user-menu-trigger
          >
            <?php if ($avatarUrl !== ''): ?>
              <img src="<?=htmlspecialchars($avatarUrl)?>" alt="">
            <?php else: ?>
              <span aria-hidden="true"><?=htmlspecialchars($avatarInitial)?></span>
            <?php endif; ?>
            <span class="sr-only">Open account menu for <?=htmlspecialchars($displayName)?></span>
          </button>
          <div class="user-menu-panel" id="user-menu-panel" role="menu" data-user-menu-panel hidden>
            <div class="user-menu-identity">
              <strong><?=htmlspecialchars($displayName)?></strong>
              <?php if (!empty($user['email'])): ?><small><?=htmlspecialchars((string) $user['email'])?></small><?php endif; ?>
            </div>
            <a href="/profile" role="menuitem">Profile</a>
            <a href="/settings" role="menuitem">Settings</a>
            <button type="button" role="menuitem" data-reload-app>Refresh</button>
            <hr>
            <form method="post" action="/logout" role="none">
              <input type="hidden" name="_csrf" value="<?=htmlspecialchars($layoutCsrf)?>">
              <button type="submit" role="menuitem">Log out</button>
            </form>
          </div>
        </div>
      </div>
    </header>
    <?php endif; ?>
    <main id="content" class="<?= $isAuthenticated && !$isComingSoon ? 'app-main' : 'guest-main' ?>"><?=$content?></main>
  </div>
  <footer class="site-footer <?=$isComingSoon ? 'site-footer-coming-soon' : ''?>">
    <span>&copy; <?=date('Y')?> by <a href="https://sorkos.net" rel="external">sorkos.net</a></span>
    <?php if ($isComingSoon && !$isAuthenticated && !empty($configured)): ?><a class="footer-login" href="/auth/start">Log in</a><?php endif; ?>
    <?php if ($isComingSoon && $isAuthenticated): ?><a class="footer-login" href="/inbox">Open Catch</a><?php endif; ?>
  </footer>
  <section class="toast-region" data-toast-region aria-label="Notifications" aria-live="polite">
    <?php foreach ($flashMessages as $flash): ?>
      <div class="toast toast-<?=htmlspecialchars($flash['type'])?>" data-toast data-auto-dismiss="5000" role="<?=$flash['type'] === 'error' ? 'alert' : 'status'?>">
        <span><?=htmlspecialchars($flash['message'])?></span>
        <button type="button" data-toast-dismiss>Dismiss</button>
      </div>
    <?php endforeach; ?>
    <div class="toast toast-neutral" data-sync-status data-toast role="status" hidden>
      <span data-toast-message></span>
      <button type="button" data-toast-dismiss>Dismiss</button>
    </div>
  </section>
  <div class="request-progress" data-request-progress role="progressbar" aria-label="Saving changes" hidden></div>
  <?php if (!empty($enableCaptureActionMenu)): ?><?php require __DIR__ . '/captures/_action_menu.php'; ?><?php endif; ?>
  <?php if (!empty($enableListDialog)): ?><?php require __DIR__ . '/captures/_list_dialog.php'; ?><?php endif; ?>
  <?php if (!empty($enableTagDialog)): ?><?php require __DIR__ . '/captures/_tag_dialog.php'; ?><?php endif; ?>
  <script type="module" src="/assets/js/app.js?v=32"></script>
</body>
</html>
