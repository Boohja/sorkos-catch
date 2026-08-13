<section class="browser-pair">
  <picture><source media="(prefers-color-scheme: dark)" srcset="/assets/logo/landscape_dark.png"><img src="/assets/logo/landscape_light.png" alt="Catch" width="356" height="120"></picture>
  <?php if (!empty($connected)): ?>
    <div class="browser-pair-result" role="status"><span aria-hidden="true">&#10003;</span><div><h1>Catch CLI authorized</h1><p>You can close this tab and return to your terminal.</p></div></div>
  <?php elseif (!empty($request) && $request['status'] === 'pending'): ?>
    <p class="kicker">Command-line client</p>
    <h1>Authorize <?=htmlspecialchars($request['device_name'])?></h1>
    <p>This device will be able to read and search your Catch data. It cannot create, update, archive, or delete captures.</p>
    <dl><div><dt>Platform</dt><dd><?=htmlspecialchars(ucfirst($request['platform']))?></dd></div><div><dt>Access</dt><dd>Read only</dd></div></dl>
    <form method="post" action="/cli/authorize"><input type="hidden" name="_csrf" value="<?=htmlspecialchars($csrf)?>"><input type="hidden" name="login" value="<?=htmlspecialchars($login)?>"><button class="button button-primary button-full" type="submit">Authorize CLI</button></form>
  <?php else: ?>
    <h1>This authorization link is no longer active</h1><p>Return to the terminal and run <code>catch login</code> again.</p>
  <?php endif; ?>
</section>
