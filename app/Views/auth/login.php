<section class="login-shell">
  <div class="login-brand"><div class="brand brand-large"><span class="brand-mark">C</span><span>Catch</span></div><h1>Alles landet<br>an einem Ort.</h1><p>Texte, Links, Bilder und Dateien. Erst erfassen, später entscheiden.</p></div>
  <div class="auth-form">
    <div><h2>Willkommen zurück</h2><p>Melde dich bei deiner persönlichen Inbox an.</p></div>
    <?php if(!empty($error)): ?><div class="alert alert-error" role="alert"><?=htmlspecialchars($error)?></div><?php endif; ?>
    <?php if($configured): ?><a class="button button-primary button-full button-link" href="/auth/start">Mit Sorkos anmelden</a><?php else: ?><button class="button button-primary button-full" type="button" disabled>Sorkos noch nicht konfiguriert</button><?php endif; ?>
    <p class="auth-note">Die Anmeldung wird sicher über Sorkos Login durchgeführt. Catch erhält dein Profil und deine bestätigte E-Mail-Adresse.</p>
  </div>
</section>
