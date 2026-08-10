<?php $currentPath=parse_url($_SERVER['REQUEST_URI']??'/',PHP_URL_PATH); $isAuthenticated=isset($user)&&is_array($user); $isComingSoon=$currentPath==='/coming-soon'; ?>
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
  <link rel="stylesheet" href="/assets/css/app.css?v=2">
  <link rel="stylesheet" href="/assets/css/components.css?v=1">
  <link rel="stylesheet" href="/assets/css/auth.css?v=2">
  <link rel="stylesheet" href="/assets/css/devices.css?v=3">
  <link rel="stylesheet" href="/assets/css/device-detail.css?v=1">
  <link rel="stylesheet" href="/assets/css/device-provenance.css?v=1">
  <link rel="stylesheet" href="/assets/css/capture-detail.css?v=2">
  <link rel="stylesheet" href="/assets/css/tags.css?v=1">
  <link rel="stylesheet" href="/assets/css/pair.css?v=1">
  <link rel="stylesheet" href="/assets/css/coming-soon.css?v=2">
  <link rel="stylesheet" href="/assets/css/shell.css?v=2">
  <link rel="stylesheet" href="/assets/css/layout-compat.css?v=1">
  <script>try{const t=localStorage.getItem('catch-theme');if(t&&t!=='system')document.documentElement.dataset.theme=t}catch(e){}</script>
  <title><?=htmlspecialchars($title??'Catch')?> | Catch</title>
</head>
<body class="<?=$isComingSoon?'coming-soon-page':''?>">
  <div class="site-shell">
    <a class="skip-link" href="#content">Skip to content</a>
    <?php if($isAuthenticated&&!$isComingSoon): ?>
    <header class="app-header">
      <a class="brand brand-nav" href="/inbox" aria-label="Catch Inbox"><img src="/assets/logo/landscape_dark_small.png" alt="Catch" width="104" height="37"></a>
      <nav aria-label="Main navigation">
        <a href="/inbox" <?=$currentPath==='/inbox'?'aria-current="page"':''?>>Inbox</a>
        <a href="/tags" <?=str_starts_with($currentPath,'/tags')?'aria-current="page"':''?>>Tags</a>
        <a href="/devices" <?=str_starts_with($currentPath,'/devices')?'aria-current="page"':''?>>Devices</a>
        <a href="/help" <?=$currentPath==='/help'?'aria-current="page"':''?>>Help</a>
      </nav>
      <div class="header-actions">
        <select class="theme-select" data-theme-select aria-label="Appearance">
          <option value="system">System</option><option value="light">Light</option><option value="dark">Dark</option>
        </select>
        <form method="post" action="/logout"><input type="hidden" name="_csrf" value="<?=htmlspecialchars($csrf??'')?>"><button class="button button-quiet" type="submit">Log out</button></form>
      </div>
    </header>
    <?php endif; ?>
    <main id="content" class="<?= $isAuthenticated&&!$isComingSoon?'app-main':'guest-main' ?>"><?=$content?></main>
  </div>
  <footer class="site-footer <?=$isComingSoon?'site-footer-coming-soon':''?>">
    <span>&copy; <?=date('Y')?> by <a href="https://sorkos.net" rel="external">sorkos.net</a></span>
    <?php if($isComingSoon&&!$isAuthenticated&&!empty($configured)): ?><a class="footer-login" href="/auth/start">Log in</a><?php endif; ?>
    <?php if($isComingSoon&&$isAuthenticated): ?><a class="footer-login" href="/inbox">Open Catch</a><?php endif; ?>
  </footer>
  <div class="sync-toast" data-sync-status role="status" aria-live="polite" hidden></div>
  <script type="module" src="/assets/js/app.js?v=8"></script>
</body>
</html>
