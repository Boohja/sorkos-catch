export function initCaptureCreate() {
  const form = document.querySelector('[data-capture-form]');
  if (!form) return;

  const input = form.querySelector('textarea');
  const submit = form.querySelector('button[type="submit"]');
  const status = form.querySelector('[data-capture-form-status]');
  const fileInput = form.querySelector('input[type="file"]');
  const attachments = form.querySelector('[data-composer-attachments]');
  let previewUrls = [];
  const clearAttachments = () => {
    previewUrls.forEach((url) => URL.revokeObjectURL(url));
    previewUrls = [];
    attachments?.replaceChildren();
    if (attachments) attachments.hidden = true;
  };
  const renderAttachments = (focusIndex = null) => {
    clearAttachments();
    if (!fileInput || !attachments) return;
    const files = Array.from(fileInput.files);
    attachments.hidden = files.length === 0;
    files.forEach((file, index) => {
      const item = document.createElement('li');
      item.className = 'composer-attachment';
      if (file.type.startsWith('image/')) {
        const preview = document.createElement('img');
        preview.src = URL.createObjectURL(file);
        previewUrls.push(preview.src);
        preview.alt = '';
        preview.className = 'composer-attachment-preview';
        preview.addEventListener('error', () => preview.remove(), { once: true });
        item.append(preview);
      }
      const name = document.createElement('span');
      name.className = 'composer-attachment-name';
      name.textContent = file.name;
      const remove = document.createElement('button');
      remove.type = 'button';
      remove.className = 'composer-attachment-remove';
      remove.setAttribute('aria-label', `Remove ${file.name}`);
      remove.title = `Remove ${file.name}`;
      remove.innerHTML = '<svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true"><path d="m6 6 12 12M6 18 18 6" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>';
      remove.addEventListener('click', () => {
        if (form.dataset.saving === 'true') return;
        const remaining = new DataTransfer();
        Array.from(fileInput.files).forEach((selected, selectedIndex) => {
          if (selectedIndex !== index) remaining.items.add(selected);
        });
        fileInput.files = remaining.files;
        renderAttachments(index);
      });
      item.append(name, remove);
      attachments.append(item);
    });
    if (focusIndex !== null) {
      const buttons = attachments.querySelectorAll('button');
      (buttons[Math.min(focusIndex, buttons.length - 1)] || fileInput).focus();
    }
  };
  fileInput?.addEventListener('change', () => renderAttachments());
  form.addEventListener('reset', clearAttachments);
  const setAttachmentControlsDisabled = (disabled) => {
    if (fileInput) fileInput.disabled = disabled;
    attachments?.querySelectorAll('button').forEach((button) => { button.disabled = disabled; });
  };
  const setStatus = (message, error = false) => {
    if (!status) return;
    status.textContent = message;
    status.classList.toggle('is-error', error);
  };

  const renewId = () => {
    form.elements.client_capture_id.value = crypto.randomUUID();
  };

  renewId();
  const shared = new URLSearchParams(location.search);
  if (input && (shared.has('title') || shared.has('text') || shared.has('url'))) {
    const parts = [shared.get('title'), shared.get('text'), shared.get('url')]
      .map((part) => part?.trim())
      .filter((part, index, values) => part && values.indexOf(part) === index);
    input.value = parts.join('\n');
    form.elements.type.value = parts.length === 1 && /^https?:\/\/\S+$/.test(parts[0]) ? 'url' : 'text';
    input.focus();
    setStatus('Shared content is ready to save.');
    history.replaceState(null, '', location.pathname + location.hash);
  }
  input?.addEventListener('input', () => {
    form.elements.type.value = /^https?:\/\/\S+$/.test(input.value.trim())
      ? 'url'
      : 'text';
  });

  form.addEventListener('submit', async (event) => {
    event.preventDefault();
    if (form.dataset.saving === 'true') return;

    const body = new FormData(form);
    form.dataset.saving = 'true';
    setAttachmentControlsDisabled(true);
    form.setAttribute('aria-busy', 'true');
    if (submit) submit.disabled = true;
    setStatus('Saving capture…');

    try {
      const response = await fetch(form.action, {
        method: 'POST',
        headers: { Accept: 'application/json' },
        body,
      });
      const result = await response.json().catch(() => ({}));
      if (!response.ok) {
        throw new Error(result.error || 'The capture could not be saved.');
      }

      form.reset();
      renewId();
      if (result.created !== false && result.html) {
        window.Catch?.captureCollection?.insert(result.html);
      }
      setStatus(result.created === false ? 'This capture was already saved.' : 'Saved.');
      setAttachmentControlsDisabled(false);
      if (submit) submit.disabled = false;
      delete form.dataset.saving;
      form.removeAttribute('aria-busy');
    } catch (error) {
      setStatus(error.message || 'The capture could not be saved. Try again.', true);
      setAttachmentControlsDisabled(false);
      if (submit) submit.disabled = false;
      delete form.dataset.saving;
      form.removeAttribute('aria-busy');
    }
  });
}
