export function initCaptureTags() {
  const dialog = document.querySelector('[data-tag-dialog]');
  const detail = document.querySelector('[data-capture-detail]');
  if (!dialog || !detail) return;

  const form = dialog.querySelector('[data-tag-form]');
  const input = dialog.querySelector('[data-tag-input]');
  const options = dialog.querySelector('[data-tag-options]');
  const assigned = dialog.querySelector('[data-assigned-tags]');
  const empty = dialog.querySelector('[data-tag-empty]');
  const headingTags = detail.querySelector('[data-heading-tags]');
  const csrf = form?.querySelector('[name=_csrf]')?.value || '';

  const notify = (message, error = false) => window.Catch?.notify?.(message, error);
  const syncEmpty = () => {
    if (empty) empty.hidden = Boolean(assigned?.children.length);
  };
  const request = async (url, data) => {
    const response = await fetch(url, {
      method: 'POST',
      headers: { Accept: 'application/json' },
      body: data,
    });
    const json = await response.json().catch(() => ({}));
    if (!response.ok) throw new Error(json.error || 'The tag change could not be saved.');
    return json;
  };
  const rememberOption = (name) => {
    if (!options || Array.from(options.options).some((option) => option.value === name)) return;
    options.append(new Option('', name));
  };
  const renderTag = (tag) => {
    if (assigned && !assigned.querySelector(`[data-tag-id="${CSS.escape(tag.id)}"]`)) {
      const pill = document.createElement('span');
      pill.className = 'assigned-tag';
      pill.dataset.tagId = tag.id;
      pill.innerHTML = '<a></a><button type="button" data-remove-tag></button>';
      const link = pill.querySelector('a');
      link.href = tag.url;
      link.textContent = tag.name;
      const remove = pill.querySelector('button');
      remove.textContent = '\u00d7';
      remove.setAttribute('aria-label', `Remove ${tag.name}`);
      assigned.append(pill);
    }
    if (headingTags && !headingTags.querySelector(`[data-heading-tag-id="${CSS.escape(tag.id)}"]`)) {
      const link = document.createElement('a');
      link.dataset.headingTagId = tag.id;
      link.href = tag.url;
      link.textContent = `#${tag.name}`;
      headingTags.append(link);
    }
  };
  const open = () => {
    if (typeof dialog.showModal === 'function') dialog.showModal();
    requestAnimationFrame(() => input?.focus());
  };

  document.addEventListener('click', (event) => {
    if (event.target.closest('[data-open-tag-dialog]')) open();
  });
  dialog.querySelector('[data-close-tag-dialog]')?.addEventListener('click', () => dialog.close());
  dialog.addEventListener('click', (event) => {
    if (event.target === dialog) dialog.close();
  });
  input?.addEventListener('keydown', (event) => {
    if (event.key !== 'Enter' || event.isComposing) return;
    event.preventDefault();
    form?.requestSubmit();
  });
  form?.addEventListener('submit', async (event) => {
    event.preventDefault();
    const name = input?.value.trim() || '';
    if (!name) return;
    input.disabled = true;
    try {
      const data = new FormData(form);
      data.set('name', name);
      const json = await request(form.action, data);
      renderTag(json.tag);
      rememberOption(json.tag.name);
      input.value = '';
      notify(`${json.tag.name} added.`);
      syncEmpty();
    } catch (error) {
      notify(error.message, true);
    } finally {
      input.disabled = false;
      input.focus();
    }
  });
  assigned?.addEventListener('click', async (event) => {
    const remove = event.target.closest('[data-remove-tag]');
    if (!remove) return;
    const pill = remove.closest('[data-tag-id]');
    remove.disabled = true;
    try {
      const data = new FormData();
      data.set('_csrf', csrf);
      const json = await request(
        `/captures/${encodeURIComponent(detail.dataset.captureId)}/tags/${encodeURIComponent(pill.dataset.tagId)}/delete`,
        data,
      );
      pill.remove();
      headingTags?.querySelector(`[data-heading-tag-id="${CSS.escape(pill.dataset.tagId)}"]`)?.remove();
      notify(`${json.tag.name} removed.`);
      syncEmpty();
      input?.focus();
    } catch (error) {
      remove.disabled = false;
      notify(error.message, true);
    }
  });
  syncEmpty();
}
