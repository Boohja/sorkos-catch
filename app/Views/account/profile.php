<?php
$avatarUrl = trim((string) ($user['avatar_url'] ?? ''));
$displayName = trim((string) ($user['display_name'] ?? '')) ?: 'Catch User';
$initial = mb_strtoupper(mb_substr($displayName, 0, 1));
$createdAt = !empty($user['created_at'])
    ? str_replace(' ', 'T', substr((string) $user['created_at'], 0, 19)) . 'Z'
    : null;
?>
<header class="page-heading account-page-heading">
  <div>
    <h1>Profile</h1>
    <p>Your Sorkos identity as it is known to Catch.</p>
  </div>
</header>

<section class="profile-summary" aria-labelledby="profile-name">
  <div class="profile-avatar" aria-hidden="true">
    <?php if ($avatarUrl !== ''): ?>
      <img src="<?=htmlspecialchars($avatarUrl)?>" alt="">
    <?php else: ?>
      <span><?=htmlspecialchars($initial)?></span>
    <?php endif; ?>
  </div>
  <div>
    <h2 id="profile-name"><?=htmlspecialchars($displayName)?></h2>
    <?php if (!empty($user['email'])): ?>
      <p><?=htmlspecialchars((string) $user['email'])?></p>
    <?php endif; ?>
  </div>
</section>

<dl class="profile-details">
  <div>
    <dt>Sorkos account ID</dt>
    <dd><code><?=htmlspecialchars((string) $user['sorkos_user_id'])?></code></dd>
  </div>
  <div>
    <dt>Email</dt>
    <dd>
      <?=!empty($user['email']) ? htmlspecialchars((string) $user['email']) : 'Not provided'?>
      <?php if (!empty($user['email'])): ?>
        <small><?=!empty($user['email_verified']) ? 'Verified by Sorkos' : 'Not verified'?></small>
      <?php endif; ?>
    </dd>
  </div>
  <div>
    <dt>Preferred language</dt>
    <dd><?=htmlspecialchars((string) ($user['preferred_language'] ?: 'Not provided'))?></dd>
  </div>
  <div>
    <dt>Using Catch since</dt>
    <dd>
      <?php if ($createdAt): ?>
        <time datetime="<?=htmlspecialchars($createdAt)?>" data-local-time data-date-style="long">UTC <?=htmlspecialchars((string) $user['created_at'])?></time>
      <?php else: ?>
        Unknown
      <?php endif; ?>
    </dd>
  </div>
</dl>
