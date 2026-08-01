<?php if(empty($user)): ?><header class="public-header"><a class="brand" href="/"><span class="brand-mark">C</span><span>Catch</span></a><a class="button button-secondary" href="/login">Anmelden</a></header><?php endif; ?>
<article class="help-doc">
  <header><p class="kicker">iOS-Kurzbefehl</p><h1>Catch mit Kurzbefehlen verwenden</h1><p>Der gemeinsame Kurzbefehl nimmt Inhalte aus dem Share Sheet entgegen, speichert sie bei Bedarf lokal und sendet sie sicher an deine persönliche Catch-Inbox.</p><?php if(!empty($user)):?><a class="button button-primary" href="/devices/new">Gerät hinzufügen</a><?php endif;?></header>

  <nav class="doc-index" aria-label="Auf dieser Seite"><a href="#einrichten">Einrichten</a><a href="#pairing">Pairing</a><a href="#capture">Captures senden</a><a href="#inputs">Eingaben</a><a href="#offline">Offline-Outbox</a></nav>

  <section id="einrichten"><h2>Kurzbefehl einrichten</h2><ol class="instruction-list"><li><strong>Gerät anlegen</strong><span>Melde dich bei Catch an, öffne „Geräte“ und füge dein iPhone oder iPad hinzu.</span></li><li><strong>Catch Setup laden</strong><span>Lade die <a href="<?=htmlspecialchars($appUrl)?>/assets/shortcuts/Catch%20Setup.shortcut" download>Shortcut-Datei</a> auf der Geräteseite oder per QR-Code.</span></li><li><strong>Verbindungscode erzeugen</strong><span>Erzeuge den Code erst, wenn Catch Setup danach fragt, und gib ihn dort ein.</span></li><li><strong>Verbindung abschließen</strong><span>Catch Setup tauscht den Code gegen ein Gerätetoken und speichert es zentral für weitere Catch-Kurzbefehle.</span></li></ol></section>

  <section id="pairing"><h2>Pairing-Endpunkt</h2><p>Der Verbindungscode gehört zum angelegten Gerät und ist nur für dessen laufende Einrichtung vorgesehen. Nach dem erfolgreichen Tausch wird er gelöscht. Das ausgegebene Token bleibt gültig, bis das Gerät entfernt wird.</p><div class="endpoint"><span>POST</span><code><?=htmlspecialchars($appUrl)?>/api/shortcut/pair</code></div><pre><code>{
  "pairing_code": "ABCD-EFGH-JKLM-NPQR"
}</code></pre><p>Antwort:</p><pre><code>{
  "device_token": "catch_device_...",
  "token_type": "Bearer",
  "capture_endpoint": "<?=htmlspecialchars($appUrl)?>/api/shortcut/captures"
}</code></pre><p>Catch Setup speichert <code>device_token</code> lokal, beispielsweise unter <code>Shortcuts/Catch/device.json</code>. Weitere Kurzbefehle auf demselben Gerät lesen diese Datei. Catch zeigt den Tokenwert nicht im Web-Frontend.</p></section>

  <section id="capture"><h2>Capture-Endpunkt</h2><div class="endpoint"><span>POST</span><code><?=htmlspecialchars($appUrl)?>/api/shortcut/captures</code></div><pre><code>Authorization: Bearer catch_device_...
Content-Type: application/json</code></pre><pre><code>{
  "client_capture_id": "eine-stabile-uuid",
  "type": "text",
  "text": "Angebot prüfen",
  "source": "ios-shortcut",
  "title": null,
  "url": null,
  "extracted_text": null,
  "metadata": {
    "device": "iPhone",
    "shortcut_version": "1.0"
  }
}</code></pre><p>Für Bilder und Dateien verwendet der Kurzbefehl <code>multipart/form-data</code>. Dateien werden als <code>attachments[]</code> übertragen. Wiederholte Requests mit derselben <code>client_capture_id</code> erzeugen keinen doppelten Capture.</p></section>

  <section id="inputs"><h2>Unterstützte Eingaben</h2><div class="input-matrix"><div><strong>Text</strong><code>type=text</code><span>Notizen, Diktat und markierter Text</span></div><div><strong>URL</strong><code>type=url</code><span>Link, Seitentitel und optionaler Begleittext</span></div><div><strong>Bild</strong><code>type=image</code><span>Originalbild plus optionaler OCR-Text</span></div><div><strong>Datei</strong><code>type=file</code><span>Dokumente und andere erlaubte Dateitypen</span></div><div><strong>Gemischt</strong><code>type=mixed</code><span>Mehrere Inhalte in einem Capture</span></div></div></section>

  <section id="offline"><h2>Offline und Verarbeitung</h2><p>Vor jedem Upload legt der Kurzbefehl den Capture in seinem lokalen Catch-Verzeichnis ab. Erst nach einer bestätigten Serverantwort werden lokale Dateien entfernt. Beim nächsten Aufruf versucht der Kurzbefehl zuerst, wartende Einträge zu synchronisieren.</p><p>Der Server ordnet den Request über das Gerätetoken einem Catch-Konto zu, validiert Inhalt und Dateien und speichert das Original. In einer späteren Ausbaustufe werden danach passende Regeln ausgewertet und optionale Webhook-Jobs angelegt.</p></section>
</article>
