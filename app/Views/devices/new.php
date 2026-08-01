<a class="back-link" href="/devices">← Zurück zu den Geräten</a>
<header class="flow-heading"><h1>Gerät hinzufügen</h1><p>Wähle, wo du Catch verwenden möchtest. Wir zeigen dir nur die dafür nötigen Schritte.</p></header>
<?php if(!empty($error)): ?><p class="form-error" role="alert"><?=htmlspecialchars($error)?></p><?php endif; ?>

<form class="device-wizard" method="post" action="/devices" data-device-wizard>
  <input type="hidden" name="_csrf" value="<?=htmlspecialchars($csrf)?>">
  <fieldset><legend>Was möchtest du verbinden?</legend><div class="choice-grid">
    <label class="choice-option"><input type="radio" name="kind" value="mobile" required><span class="choice-icon choice-icon-mobile" aria-hidden="true"></span><strong>Mobil</strong><small>iPhone, iPad und später Android</small></label>
    <label class="choice-option choice-option-disabled"><input type="radio" name="kind" value="desktop" disabled><span class="choice-icon choice-icon-desktop" aria-hidden="true"></span><strong>Desktop-Browser</strong><small>Chrome und Firefox · folgt</small></label>
  </div></fieldset>
  <fieldset data-platform-step hidden><legend>Welches System?</legend><div class="choice-grid">
    <label class="choice-option"><input type="radio" name="platform" value="ios" required><span class="choice-icon" aria-hidden="true">iOS</span><strong>iPhone</strong><small>Mit Apple Kurzbefehle</small></label>
    <label class="choice-option"><input type="radio" name="platform" value="ipados" required><span class="choice-icon" aria-hidden="true">iPad</span><strong>iPad</strong><small>Mit Apple Kurzbefehle</small></label>
    <label class="choice-option choice-option-disabled"><input type="radio" name="platform" value="android" disabled><span class="choice-icon" aria-hidden="true">A</span><strong>Android</strong><small>Folgt</small></label>
  </div></fieldset>
  <fieldset data-name-step hidden><legend>Wie heißt das Gerät?</legend><label class="device-name-field">Gerätename<input name="name" maxlength="120" required placeholder="z. B. Mein iPhone"></label><div class="wizard-actions"><button class="button button-primary" type="submit">Gerät anlegen</button></div></fieldset>
</form>
