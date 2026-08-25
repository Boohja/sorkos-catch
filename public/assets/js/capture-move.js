export function initCaptureMove() {
  const dialog = document.querySelector('[data-move-dialog]');
  if (!dialog) return;

  const title = dialog.querySelector('[data-move-title]');
  const status = dialog.querySelector('[data-move-status-message]');
  const csrf = dialog.querySelector('[data-move-csrf]')?.value || '';
  const archive = dialog.querySelector('[data-move-target="archive"]');
  const targets = Array.from(dialog.querySelectorAll('[data-move-target]'));
  let context = { ids: [], mode: 'single', sourceStatus: 'inbox', returnTo: '/inbox' };

  const setStatus = (message = '', error = false) => {
    status.textContent = message;
    status.classList.toggle('is-error', error);
  };

  const open = (nextContext) => {
    context = nextContext;
    const multiple = context.ids.length !== 1;
    title.textContent = multiple ? `Move ${context.ids.length} captures` : 'Move capture';
    const alreadyArchived = context.sourceStatus === 'archived';
    archive.disabled = alreadyArchived;
    archive.querySelector('[data-current-archive]').hidden = !alreadyArchived;
    setStatus();
    dialog.showModal();
  };

  const move = async (destination) => {
    if (!context.ids.length) return;
    const single = context.mode === 'single';
    const action = single
      ? `/captures/${encodeURIComponent(context.ids[0])}/${destination === 'archive' ? 'archive' : 'delete'}`
      : `/captures/${destination === 'archive' ? 'bulk-archive' : 'bulk-delete'}`;
    const body = new FormData();
    body.append('_csrf', csrf);
    body.append('view', context.sourceStatus);
    if (!single) context.ids.forEach((id) => body.append('capture_ids[]', id));

    targets.forEach((target) => { target.disabled = true; });
    setStatus(`Moving to ${destination === 'archive' ? 'Archive' : 'Trash'}…`);
    try {
      const response = await fetch(action, {
        method: 'POST',
        headers: { Accept: 'application/json' },
        body,
      });
      const result = await response.json().catch(() => ({}));
      if (!response.ok) throw new Error(result.error || 'The captures could not be moved.');
      dialog.close();
      if (single && document.querySelector('[data-capture-detail]')) {
        window.location.assign(context.returnTo || '/inbox');
        return;
      }
      await window.Catch?.captureCollection?.transition(context.ids, {
        status: result.capture_status,
      });
      window.Catch?.clearCaptureSelection?.();
    } catch (error) {
      setStatus(error.message || 'The captures could not be moved.', true);
    } finally {
      targets.forEach((target) => {
        target.disabled = target.dataset.moveTarget === 'archive'
          && context.sourceStatus === 'archived';
      });
    }
  };

  window.Catch = window.Catch || {};
  window.Catch.openMoveDialog = open;

  document.addEventListener('click', (event) => {
    const trigger = event.target.closest('[data-open-move]');
    if (!trigger) return;
    open({
      ids: [trigger.dataset.captureId].filter(Boolean),
      mode: 'single',
      sourceStatus: trigger.dataset.moveStatus || 'inbox',
      returnTo: trigger.dataset.moveReturn || '/inbox',
    });
  });

  targets.forEach((target) => {
    target.addEventListener('click', () => move(target.dataset.moveTarget));
  });
  dialog.querySelector('[data-close-move-dialog]')?.addEventListener('click', () => dialog.close());
  dialog.addEventListener('click', (event) => {
    if (event.target === dialog) dialog.close();
  });
}
