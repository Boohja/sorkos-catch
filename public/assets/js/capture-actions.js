export function initCaptureActions() {
  const menu = document.querySelector('[data-capture-action-menu]');
  if (!menu) return;

  const laterButton = menu.querySelector('[data-menu-later]');
  const archiveForm = menu.querySelector('[data-menu-archive]');
  const trashForm = menu.querySelector('[data-menu-trash]');
  let trigger = null;

  const close = () => {
    if (trigger) trigger.setAttribute('aria-expanded', 'false');
    menu.hidden = true;
    trigger = null;
  };

  const submitAction = async (form) => {
    if (!trigger) return;
    const captureId = trigger.dataset.captureId;
    const button = form.querySelector('button[type="submit"]');
    if (button) button.disabled = true;

    try {
      const response = await fetch(form.action, {
        method: 'POST',
        headers: { Accept: 'application/json' },
        body: new FormData(form),
      });
      const result = await response.json().catch(() => ({}));
      if (!response.ok) throw new Error(result.error || 'The capture could not be updated.');
      close();
      await window.Catch?.captureCollection?.transition([captureId], {
        status: result.capture_status,
      });
    } catch (error) {
      window.Catch?.notify?.(error.message || 'The capture could not be updated.', true);
    } finally {
      if (button) button.disabled = false;
    }
  };

  const position = () => {
    if (!trigger) return;
    const triggerRect = trigger.getBoundingClientRect();
    const menuRect = menu.getBoundingClientRect();
    const gap = 6;
    const top = triggerRect.bottom + gap + menuRect.height <= window.innerHeight
      ? triggerRect.bottom + gap
      : triggerRect.top - menuRect.height - gap;
    menu.style.top = `${Math.max(8, top)}px`;
    menu.style.left = `${Math.max(8, Math.min(triggerRect.right - menuRect.width, window.innerWidth - menuRect.width - 8))}px`;
  };

  document.addEventListener('click', (event) => {
    const nextTrigger = event.target.closest('[data-capture-actions]');
    if (nextTrigger) {
      if (trigger === nextTrigger && !menu.hidden) {
        close();
        return;
      }
      close();
      trigger = nextTrigger;
      trigger.setAttribute('aria-expanded', 'true');
      const id = encodeURIComponent(trigger.dataset.captureId);
      archiveForm.action = `/captures/${id}/archive`;
      archiveForm.hidden = trigger.dataset.captureStatus !== 'inbox';
      laterButton.hidden = trigger.dataset.captureStatus !== 'inbox';
      trashForm.action = `/captures/${id}/delete`;
      menu.hidden = false;
      position();
      menu.querySelector('[role="menuitem"]')?.focus();
      return;
    }

    if (!menu.hidden && !menu.contains(event.target)) close();
  });

  laterButton?.addEventListener('click', () => {
    if (!trigger) return;
    const captureId = trigger.dataset.captureId;
    close();
    window.Catch?.openLaterDialog?.({ ids: [captureId] });
  });

  [archiveForm, trashForm].forEach((form) => {
    form?.addEventListener('submit', (event) => {
      event.preventDefault();
      submitAction(form);
    });
  });

  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape' && !menu.hidden) close();
  });
  window.addEventListener('scroll', close, { passive: true });
  window.addEventListener('resize', close);
}
