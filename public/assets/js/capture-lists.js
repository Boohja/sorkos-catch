export function initCaptureLists() {
  const dialog = document.querySelector('[data-list-dialog]');
  if (!dialog) return;

  const form = dialog.querySelector('[data-list-form]');
  const title = dialog.querySelector('[data-list-dialog-title]');
  const description = dialog.querySelector('[data-list-dialog-description]');
  const status = dialog.querySelector('[data-list-status]');
  const save = dialog.querySelector('[data-save-lists]');
  const detail = document.querySelector('[data-capture-detail]');
  const assigned = detail?.querySelector('[data-assigned-lists]');
  const archiveAction = detail?.querySelector('[data-archive-action]');
  let context = { captureIds: [], assignedListIds: [], mode: 'single' };

  const setStatus = (text, error = false) => {
    if (!status) return;
    status.textContent = text;
    status.classList.toggle('is-error', error);
  };

  const renderLists = (lists) => {
    if (!assigned) return;
    assigned.replaceChildren(...lists.map((list) => {
      const link = document.createElement('a');
      link.dataset.listId = list.id;
      link.href = list.url;
      link.textContent = list.title;
      return link;
    }));
  };

  const open = (nextContext) => {
    context = nextContext;
    const selected = new Set(context.assignedListIds || []);
    form.querySelectorAll('input[name="list_ids[]"]').forEach((checkbox) => {
      checkbox.checked = context.mode === 'single' && selected.has(checkbox.value);
    });
    title.textContent = context.mode === 'bulk' ? 'Add captures to lists' : 'Add to lists';
    description.textContent = context.mode === 'bulk'
      ? `${context.captureIds.length} ${context.captureIds.length === 1 ? 'capture' : 'captures'} will be added to the selected lists and archived.`
      : 'Choose every list this capture should belong to.';
    if (save) save.textContent = context.mode === 'bulk' ? 'Add to lists' : 'Save lists';
    setStatus('');
    if (typeof dialog.showModal === 'function') dialog.showModal();
  };

  window.Catch = window.Catch || {};
  window.Catch.openListDialog = open;
  document.addEventListener('capture:open-lists', (event) => open(event.detail));
  document.addEventListener('click', (event) => {
    const trigger = event.target.closest('[data-open-list-dialog]');
    if (!trigger) return;
    let assignedListIds = [];
    try {
      assignedListIds = JSON.parse(trigger.dataset.listIds || '[]');
    } catch {
      assignedListIds = [];
    }
    open({
      captureIds: [trigger.dataset.captureId || detail?.dataset.captureId].filter(Boolean),
      assignedListIds,
      mode: 'single',
    });
  });

  dialog.querySelector('[data-close-list-dialog]')?.addEventListener('click', () => dialog.close());
  dialog.addEventListener('click', (event) => {
    if (event.target === dialog) dialog.close();
  });

  form.addEventListener('submit', async (event) => {
    event.preventDefault();
    if (!context.captureIds.length) return;
    const body = new FormData(form);
    const action = context.mode === 'bulk'
      ? '/captures/bulk-lists'
      : `/captures/${encodeURIComponent(context.captureIds[0])}/lists/sync`;
    if (context.mode === 'bulk') {
      context.captureIds.forEach((id) => body.append('capture_ids[]', id));
    }
    if (save) save.disabled = true;
    setStatus('Saving lists…');

    try {
      const response = await fetch(action, {
        method: 'POST',
        headers: { Accept: 'application/json' },
        body,
      });
      const json = await response.json().catch(() => ({}));
      if (!response.ok) throw new Error(json.error || 'The list changes could not be saved.');

      if (context.mode === 'single' && detail) {
        renderLists(json.lists || []);
        if (archiveAction) archiveAction.hidden = json.capture_status !== 'inbox';
        dialog.close();
      } else {
        window.location.reload();
      }
    } catch (error) {
      setStatus(error.message, true);
    } finally {
      if (save) save.disabled = false;
    }
  });
}
