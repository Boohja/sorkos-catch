export function initCaptureLater() {
  const dialog = document.querySelector('[data-later-dialog]');
  const form = dialog?.querySelector('[data-later-form]');
  if (!dialog || !form) return;

  const description = form.querySelector('[data-later-description]');
  const dateInput = form.querySelector('[data-later-date]');
  const utcInput = form.querySelector('[data-later-until-utc]');
  const status = form.querySelector('[data-later-status]');
  const submit = form.querySelector('[data-save-later]');
  const defaultAction = form.action;
  let captureIds = [];
  let returnToInbox = false;

  const tomorrow = () => {
    const date = new Date();
    date.setDate(date.getDate() + 1);
    const year = date.getFullYear();
    const month = String(date.getMonth() + 1).padStart(2, '0');
    const day = String(date.getDate()).padStart(2, '0');
    return `${year}-${month}-${day}`;
  };

  const syncDateRequirement = () => {
    const custom = form.elements.later_choice.value === 'date';
    dateInput.required = custom;
  };

  const open = ({ ids, detail = false }) => {
    captureIds = Array.from(new Set(ids.filter(Boolean)));
    if (!captureIds.length) return;

    form.reset();
    form.querySelector('[name="later_choice"][value="12_hours"]').checked = true;
    form.action = captureIds.length === 1
      ? `/captures/${encodeURIComponent(captureIds[0])}/later`
      : defaultAction;
    returnToInbox = detail;
    dateInput.min = tomorrow();
    utcInput.value = '';
    status.textContent = '';
    status.classList.remove('is-error');
    description.textContent = captureIds.length === 1
      ? 'This item will be removed from the inbox until the time you choose.'
      : `These ${captureIds.length} items will be removed from the inbox until the time you choose.`;
    syncDateRequirement();
    dialog.showModal();
  };

  window.Catch = window.Catch || {};
  window.Catch.openLaterDialog = open;

  document.querySelectorAll('[data-open-later]').forEach((button) => {
    button.addEventListener('click', () => open({
      ids: [button.dataset.captureId],
      detail: Boolean(button.closest('[data-capture-detail]')),
    }));
  });

  form.addEventListener('change', (event) => {
    if (event.target === dateInput) {
      form.querySelector('[name="later_choice"][value="date"]').checked = true;
    }
    syncDateRequirement();
  });

  form.addEventListener('submit', async (event) => {
    event.preventDefault();
    status.textContent = '';
    status.classList.remove('is-error');

    if (form.elements.later_choice.value === 'date') {
      if (!dateInput.value) {
        dateInput.focus();
        return;
      }
      const localOneAm = new Date(`${dateInput.value}T01:00:00`);
      if (Number.isNaN(localOneAm.getTime())) {
        status.textContent = 'The selected date could not be read. Choose it again.';
        status.classList.add('is-error');
        return;
      }
      utcInput.value = localOneAm.toISOString();
    } else {
      utcInput.value = '';
    }

    form.querySelectorAll('[data-later-capture-id]').forEach((input) => input.remove());
    if (captureIds.length > 1) {
      captureIds.forEach((id) => {
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'capture_ids[]';
        input.value = id;
        input.dataset.laterCaptureId = '';
        form.append(input);
      });
    }

    submit.disabled = true;
    try {
      const response = await fetch(form.action, {
        method: 'POST',
        headers: { Accept: 'application/json' },
        body: new FormData(form),
      });
      const result = await response.json().catch(() => ({}));
      if (!response.ok) {
        throw new Error(result.error || 'The captures could not be moved to Later.');
      }
      dialog.close();
      if (returnToInbox) {
        window.location.assign('/inbox');
        return;
      }
      await window.Catch?.captureCollection?.transition(captureIds, {
        status: result.capture_status,
      });
    } catch (error) {
      status.textContent = error.message || 'The captures could not be moved to Later.';
      status.classList.add('is-error');
    } finally {
      submit.disabled = false;
    }
  });

  form.querySelector('[data-close-later-dialog]')?.addEventListener('click', () => dialog.close());
}
