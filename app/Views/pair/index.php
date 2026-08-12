<section class="browser-pair">
  <picture><source media="(prefers-color-scheme: dark)" srcset="/assets/logo/landscape_dark.png"><img src="/assets/logo/landscape_light.png" alt="Catch" width="356" height="120"></picture>
  <?php if (!empty($connected)): ?>
    <div class="browser-pair-result" role="status">
      <span aria-hidden="true">✓</span>
      <div><h1>Browser connected</h1><p>You can return to the extension. Any capture waiting there will be sent automatically.</p></div>
    </div>
  <?php elseif (!empty($pairing) && $pairing['status'] === 'pending'): ?>
    <p class="kicker">Browser extension</p>
    <h1>Connect <?=htmlspecialchars($pairing['device_name'])?></h1>
    <p>This gives the extension permission to add captures to your Catch inbox. It cannot read, archive, or delete existing captures.</p>
    <dl><div><dt>Browser</dt><dd><?=htmlspecialchars(ucfirst($pairing['platform']))?></dd></div><div><dt>Access</dt><dd>Capture only</dd></div></dl>
    <form method="post" action="/pair"><input type="hidden" name="_csrf" value="<?=htmlspecialchars($csrf)?>"><input type="hidden" name="request" value="<?=htmlspecialchars($request)?>"><button class="button button-primary button-full" type="submit">Connect browser</button></form>
  <?php else: ?>
    <h1>This connection link is no longer active</h1>
    <p>Return to the Catch extension and start a new connection.</p>
  <?php endif; ?>
</section>
