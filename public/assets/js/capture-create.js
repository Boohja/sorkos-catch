export function initCaptureCreate() {
  const form = document.querySelector('[data-capture-form]');
  if (!form) return;

  const input = form.querySelector('textarea');
  const submit = form.querySelector('button[type="submit"]');
  const status = form.querySelector('[data-capture-form-status]');
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

    form.dataset.saving = 'true';
    form.setAttribute('aria-busy', 'true');
    if (submit) submit.disabled = true;
    setStatus('Saving capture…');

    try {
      const response = await fetch(form.action, {
        method: 'POST',
        headers: { Accept: 'application/json' },
        body: new FormData(form),
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
      if (submit) submit.disabled = false;
      delete form.dataset.saving;
      form.removeAttribute('aria-busy');
    } catch (error) {
      setStatus(error.message || 'The capture could not be saved. Try again.', true);
      if (submit) submit.disabled = false;
      delete form.dataset.saving;
      form.removeAttribute('aria-busy');
    }
  });
}
