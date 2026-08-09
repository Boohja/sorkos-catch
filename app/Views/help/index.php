<?php if(empty($user)): ?><header class="public-header"><a class="brand brand-nav" href="/"><img src="/assets/logo/landscape_dark_small.png" alt="Catch" width="104" height="37"></a><a class="button button-secondary" href="/login">Log in</a></header><?php endif; ?>
<article class="help-doc">
  <header><p class="kicker">iOS Shortcut</p><h1>Use Catch with Shortcuts</h1><p>The shared shortcut accepts content from the Share Sheet, stores it locally when needed, and sends it securely to your personal Catch inbox.</p><?php if(!empty($user)):?><a class="button button-primary" href="/devices/new">Add device</a><?php endif;?></header>

  <nav class="doc-index" aria-label="On this page"><a href="#setup">Setup</a><a href="#pairing">Pairing</a><a href="#capture">Send captures</a><a href="#inputs">Inputs</a><a href="#offline">Offline outbox</a></nav>

  <section id="setup"><h2>Set up the shortcut</h2><ol class="instruction-list"><li><strong>Create a device</strong><span>Sign in to Catch, open “Devices,” and add your iPhone or iPad.</span></li><li><strong>Download Catch Setup</strong><span>Download the <a href="<?=htmlspecialchars($appUrl)?>/assets/shortcuts/Catch%20Setup.shortcut" download>shortcut file</a> from the device page or scan its QR code.</span></li><li><strong>Create a pairing code</strong><span>Create the code when Catch Setup asks for it, then enter it in the shortcut.</span></li><li><strong>Complete the connection</strong><span>Catch Setup exchanges the code for a device token and stores it centrally for other Catch shortcuts.</span></li></ol></section>

  <section id="pairing"><h2>Pairing endpoint</h2><p>The 10-digit pairing code belongs to the device you created and expires after 15 minutes. It is deleted after a successful exchange. The returned token remains valid until the device is removed.</p><div class="endpoint"><span>POST</span><code><?=htmlspecialchars($appUrl)?>/api/shortcut/pair</code></div><pre><code>{
  "pairing_code": "12345 67890"
}</code></pre><p>Success response:</p><pre><code>{
  "result": "catch_device_..."
}</code></pre><p>Catch Setup stores <code>result</code> as the device token locally, for example at <code>Shortcuts/Catch/device.json</code>. Other shortcuts on the same device read this file. Catch never displays the token value in the web interface.</p><p>Every Shortcut API response contains exactly one string field. If <code>error</code> exists, show it and stop the shortcut; otherwise read <code>result</code>.</p><pre><code>{
  "error": "The pairing code is invalid."
}</code></pre></section>

  <section id="capture"><h2>Capture endpoint</h2><div class="endpoint"><span>POST</span><code><?=htmlspecialchars($appUrl)?>/api/shortcut/captures</code></div><pre><code>Authorization: Bearer catch_device_...
Content-Type: application/json</code></pre><pre><code>{
  "client_capture_id": "a-stable-uuid",
  "type": "text",
  "text": "Review proposal",
  "source": "ios-shortcut",
  "title": null,
  "url": null,
  "extracted_text": null,
  "metadata": {
    "device": "iPhone",
    "shortcut_version": "1.0"
  }
}</code></pre><p>Success response (the result is the capture ID):</p><pre><code>{
  "result": "018f...uuid..."
}</code></pre><p>For images and files, the shortcut uses <code>multipart/form-data</code>. Files are sent as <code>attachments[]</code>. Repeated requests with the same <code>client_capture_id</code> do not create duplicate captures.</p></section>

  <section id="inputs"><h2>Supported inputs</h2><div class="input-matrix"><div><strong>Text</strong><code>type=text</code><span>Notes, dictation, and selected text</span></div><div><strong>URL</strong><code>type=url</code><span>Link, page title, and optional context</span></div><div><strong>Image</strong><code>type=image</code><span>Original image with optional OCR text</span></div><div><strong>File</strong><code>type=file</code><span>Documents and other permitted file types</span></div><div><strong>Mixed</strong><code>type=mixed</code><span>Multiple types of content in one capture</span></div></div></section>

  <section id="offline"><h2>Offline storage and processing</h2><p>Before every upload, the shortcut writes the capture to its local Catch directory. Local files are removed only after the server confirms receipt. On the next run, the shortcut tries to sync pending items first.</p><p>The server uses the device token to associate the request with a Catch account, validates the content and files, and stores the original. A later release will evaluate matching rules and enqueue optional webhook jobs.</p></section>
</article>
