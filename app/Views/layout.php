<?php $currentPath=parse_url($_SERVER['REQUEST_URI']??'/',PHP_URL_PATH); $isAuthenticated=isset($user)&&is_array($user); ?>
<!doctype html>
<html lang="de">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
  <meta name="theme-color" content="#f59e0b">
  <meta name="description" content="Catch ist deine persönliche Capture-Inbox.">
  <link rel="manifest" href="/manifest.webmanifest">
  <link rel="icon" href="/assets/icons/icon.svg" type="image/svg+xml">
  <link rel="stylesheet" href="/assets/css/app.css?v=1">
  <link rel="stylesheet" href="/assets/css/auth.css?v=1">
  <link rel="stylesheet" href="/assets/css/devices.css?v=2">
  <link rel="stylesheet" href="/assets/css/coming-soon.css?v=1">
  <script>try{const t=localStorage.getItem('catch-theme');if(t&&t!=='system')document.documentElement.dataset.theme=t}catch(e){}</script>
  <title><?=htmlspecialchars($title??'Catch')?> | Catch</title>
</head>
<body>
  <a class="skip-link" href="#content">Zum Inhalt</a>
  <?php if($isAuthenticated): ?>
  <header class="app-header">
    <a class="brand" href="/inbox" aria-label="Catch Inbox"><span class="brand-mark">C</span><span>Catch</span></a>
    <nav aria-label="Hauptnavigation">
      <a href="/inbox" <?=$currentPath==='/inbox'?'aria-current="page"':''?>>Inbox</a>
      <a href="/devices" <?=str_starts_with($currentPath,'/devices')?'aria-current="page"':''?>>Geräte</a>
      <a href="/help" <?=$currentPath==='/help'?'aria-current="page"':''?>>Hilfe</a>
    </nav>
    <div class="header-actions">
      <select class="theme-select" data-theme-select aria-label="Darstellung">
        <option value="system">System</option><option value="light">Hell</option><option value="dark">Dunkel</option>
      </select>
      <form method="post" action="/logout"><input type="hidden" name="_csrf" value="<?=htmlspecialchars($csrf??'')?>"><button class="button button-quiet" type="submit">Abmelden</button></form>
    </div>
  </header>
  <?php endif; ?>
  <main id="content" class="<?= $isAuthenticated?'app-main':'guest-main' ?>"><?=$content?></main>
  <div class="sync-toast" data-sync-status role="status" aria-live="polite" hidden></div>
  <script type="module" src="/assets/js/app.js?v=4"></script>
</body>
</html>
