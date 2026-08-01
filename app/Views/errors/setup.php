<section class="setup-shell">
  <div class="brand brand-large"><span class="brand-mark">C</span><span>Catch</span></div>
  <div class="setup-copy">
    <p class="kicker">Bereit für die Datenbank</p>
    <h1>Capture once.<br>Decide later.</h1>
    <p>Die Anwendung läuft bereits. Trage die Zugangsdaten in <code>config/config.ini</code> oder als Umgebungsvariablen ein und führe anschließend die Migration aus.</p>
    <pre><code>Copy-Item config/config.example.ini config/config.ini
php bin/migrate.php</code></pre>
    <p class="status-line"><span class="status-icon" aria-hidden="true"></span><?= $configured?'Datenbank konfiguriert, aber nicht erreichbar.':'Datenbank noch nicht konfiguriert.' ?></p>
  </div>
</section>
