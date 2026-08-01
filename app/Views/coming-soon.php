<section class="coming-soon-shell">
  <div class="coming-soon-card">
    <div class="brand brand-large"><span class="brand-mark">C</span><span>Catch</span></div>
    <div class="coming-soon-copy">
      <p class="kicker">Noch nicht öffentlich</p>
      <h1>Gedanken rein.<br>Später weitermachen.</h1>
      <p>Catch wird gerade für den ersten Einsatz vorbereitet. Bald kannst du Texte, Links, Bilder und Aufnahmen in einer persönlichen Inbox sammeln.</p>
    </div>
    <?php if (!empty($user)): ?>
      <a class="button button-primary coming-soon-action" href="/inbox">Catch öffnen</a>
    <?php elseif ($configured): ?>
      <?php if ($accessDenied): ?><p class="coming-soon-notice" role="status">Dieser Zugang ist während der Vorschau noch nicht freigeschaltet.</p><?php endif; ?>
      <a class="button button-secondary coming-soon-action" href="/auth/start">Vorschau anmelden</a>
    <?php endif; ?>
  </div>
  <div class="coming-soon-signal" aria-hidden="true"><span></span><span></span><span></span></div>
</section>
