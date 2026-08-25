export function initCaptureBulk() {
  const form = document.querySelector('[data-bulk-actions]');
  if (!form) return;

  const count = form.querySelector('[data-bulk-count]');
  const open = form.querySelector('[data-open-bulk-delete]');
  const openLists = form.querySelector('[data-open-bulk-lists]');
  const openLater = form.querySelector('[data-open-bulk-later]');
  const openMove = form.querySelector('[data-open-bulk-move]');
  const dialog = document.querySelector('[data-bulk-delete-dialog]');
  const description = dialog?.querySelector('[data-bulk-delete-description]');
  const confirm = dialog?.querySelector('[data-confirm-bulk-delete]');
  const permanent = form.dataset.permanent === 'true';
  const boxes = () => Array.from(document.querySelectorAll(
    `input[type="checkbox"][form="${CSS.escape(form.id)}"][name="capture_ids[]"]`,
  ));
  const selected = () => boxes().filter((box) => box.checked);

  const sync = () => {
    const total = selected().length;
    form.hidden = total === 0;
    if (count) {
      count.textContent = `${total} ${total === 1 ? 'capture' : 'captures'} selected`;
    }
    if (total === 0 && dialog?.open) dialog.close();
  };

  const clear = () => {
    boxes().forEach((box) => {
      box.checked = false;
    });
    sync();
  };

  window.Catch = window.Catch || {};
  window.Catch.clearCaptureSelection = clear;

  // Browsers may restore checkbox state across reloads. Selection belongs to
  // the current rendered collection, so always begin with a clean slate.
  clear();
  window.addEventListener('pageshow', clear);
  document.addEventListener('change', (event) => {
    if (event.target.matches(`input[form="${CSS.escape(form.id)}"][name="capture_ids[]"]`)) sync();
  });

  open?.addEventListener('click', () => {
    const total = selected().length;
    if (!total) return;

    const message = permanent
      ? `${total === 1 ? 'This capture and its attachments will' : `These ${total} captures and their attachments will`} be permanently deleted. This action cannot be undone.`
      : `${total === 1 ? 'This capture will' : `These ${total} captures will`} stay in Trash for 30 days and can be restored.`;
    if (description) description.textContent = message;
    if (typeof dialog?.showModal === 'function') {
      dialog.showModal();
    } else if (window.confirm(message)) {
      form.requestSubmit();
    }
  });

  openLists?.addEventListener('click', () => {
    const ids = selected().map((box) => box.value);
    if (!ids.length) return;

    window.Catch?.openListDialog?.({
      captureIds: ids,
      assignedListIds: [],
      mode: 'bulk',
    });
  });

  openLater?.addEventListener('click', () => {
    const ids = selected().map((box) => box.value);
    if (!ids.length) return;

    window.Catch?.openLaterDialog?.({ ids });
  });

  openMove?.addEventListener('click', () => {
    const ids = selected().map((box) => box.value);
    if (!ids.length) return;

    window.Catch?.openMoveDialog?.({
      ids,
      mode: 'bulk',
      sourceStatus: openMove.dataset.moveStatus || 'inbox',
      returnTo: window.location.pathname,
    });
  });

  form.addEventListener('submit', async (event) => {
    event.preventDefault();
    const ids = selected().map((box) => box.value);
    if (!ids.length) return;

    if (confirm) confirm.disabled = true;
    if (open) open.disabled = true;
    const action = event.submitter?.hasAttribute('formaction')
      ? event.submitter.formAction
      : form.action;
    try {
      const response = await fetch(action, {
        method: 'POST',
        headers: { Accept: 'application/json' },
        body: new FormData(form),
      });
      const result = await response.json().catch(() => ({}));
      if (!response.ok) {
        throw new Error(typeof result.error === 'string'
          ? result.error
          : 'The captures could not be updated.');
      }
      if (dialog?.open) dialog.close();
      await window.Catch?.captureCollection?.transition(ids, {
        status: result.capture_status,
      });
    } catch (error) {
      window.Catch?.notify?.(error.message || 'The captures could not be updated.', true);
    } finally {
      if (confirm) confirm.disabled = false;
      if (open) open.disabled = false;
    }
  });

  document.addEventListener('capture:collection-changed', sync);
}
