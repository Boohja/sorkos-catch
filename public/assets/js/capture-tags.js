export function initCaptureTags() {
  const dialog = document.querySelector('[data-tag-dialog]');
  const detail = document.querySelector('[data-capture-detail]');
  if (!dialog || !detail) return;

  const input = dialog.querySelector('[data-tag-filter]');
  const assigned = dialog.querySelector('[data-assigned-tags]');
  const options = dialog.querySelector('[data-tag-options]');
  const empty = dialog.querySelector('[data-tag-empty]');
  const noResults = dialog.querySelector('[data-tag-no-results]');
  const headingTags = detail.querySelector('[data-heading-tags]');
  const csrf = dialog.querySelector('[data-tag-csrf]')?.value || '';

  const notify = (message, error = false) => window.Catch?.notify?.(message, error);
  const assignedIds = () => new Set(
    Array.from(assigned?.querySelectorAll('[data-tag-id]') || []).map((tag) => tag.dataset.tagId),
  );
  const sync = () => {
    const selected = assignedIds();
    const term = (input?.value || '').trim().toLocaleLowerCase();
    let visible = 0;
    options?.querySelectorAll('[data-add-tag]').forEach((option) => {
      const matches = !term || option.dataset.tagName.toLocaleLowerCase().includes(term);
      option.hidden = selected.has(option.dataset.tagId) || !matches;
      if (!option.hidden) visible += 1;
    });
    if (empty) empty.hidden = selected.size > 0;
    if (noResults) noResults.hidden = visible > 0;
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
  const renderTag = (tag) => {
    if (assigned && !assigned.querySelector(`[data-tag-id="${CSS.escape(tag.id)}"]`)) {
      const option = document.createElement('button');
      option.className = 'tag-dialog-option tag-dialog-option-selected';
      option.type = 'button';
      option.dataset.tagId = tag.id;
      option.dataset.removeTag = '';
      option.setAttribute('aria-label', `Remove ${tag.name}`);
      option.innerHTML = '<span></span>';
      option.querySelector('span').textContent = tag.name;
      assigned.append(option);
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
    if (input) input.value = '';
    sync();
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
  input?.addEventListener('input', sync);
  options?.addEventListener('click', async (event) => {
    const option = event.target.closest('[data-add-tag]');
    if (!option) return;
    option.disabled = true;
    try {
      const data = new FormData();
      data.set('_csrf', csrf);
      data.set('tag_id', option.dataset.tagId);
      const json = await request(`/captures/${encodeURIComponent(detail.dataset.captureId)}/tags`, data);
      renderTag(json.tag);
      option.disabled = false;
      notify(`${json.tag.name} added.`);
      sync();
      input?.focus();
    } catch (error) {
      option.disabled = false;
      notify(error.message, true);
    }
  });
  assigned?.addEventListener('click', async (event) => {
    const remove = event.target.closest('[data-remove-tag]');
    if (!remove) return;
    const option = remove.closest('[data-tag-id]');
    remove.disabled = true;
    try {
      const data = new FormData();
      data.set('_csrf', csrf);
      const json = await request(
        `/captures/${encodeURIComponent(detail.dataset.captureId)}/tags/${encodeURIComponent(option.dataset.tagId)}/delete`,
        data,
      );
      option.remove();
      headingTags?.querySelector(`[data-heading-tag-id="${CSS.escape(option.dataset.tagId)}"]`)?.remove();
      notify(`${json.tag.name} removed.`);
      sync();
      input?.focus();
    } catch (error) {
      remove.disabled = false;
      notify(error.message, true);
    }
  });
  sync();
}
