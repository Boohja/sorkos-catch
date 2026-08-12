<section class="login-shell">
  <div class="login-brand"><img class="brand-presentation" src="/assets/logo/landscape_dark.svg" alt="Catch"><h1>Everything lands<br>in one place.</h1><p>Text, links, images, and files. Capture now, decide later.</p></div>
  <div class="auth-form">
    <div><h2>Welcome back</h2><p>Sign in to your personal inbox.</p></div>
    <?php if (!empty($error)): ?><div class="alert alert-error" role="alert"><?=htmlspecialchars($error)?></div><?php endif; ?>
    <?php if ($configured): ?><a class="button button-primary button-full button-link" href="/auth/start">Continue with Sorkos</a><?php else: ?><button class="button button-primary button-full" type="button" disabled>Sorkos is not configured</button><?php endif; ?>
    <p class="auth-note">Authentication is handled securely by Sorkos Login. Catch receives your profile and verified email address.</p>
  </div>
</section>
