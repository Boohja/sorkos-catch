export function initCaptureActions() {
  const menu = document.querySelector('[data-capture-action-menu]');
  if (!menu) return;

  const laterButton = menu.querySelector('[data-menu-later]');
  const archiveForm = menu.querySelector('[data-menu-archive]');
  const trashForm = menu.querySelector('[data-menu-trash]');
  const customActionForms = [...menu.querySelectorAll('[data-menu-custom-action]')];
  let trigger = null;

  const visibleItems = () => [...menu.querySelectorAll('[role="menuitem"]')]
    .filter((item) => !item.hidden && !item.closest('form[hidden]'));

  const focusItem = (index) => {
    const items = visibleItems();
    if (!items.length) return;
    const target = items[Math.max(0, Math.min(index, items.length - 1))];
    menu.querySelectorAll('[role="menuitem"]').forEach((item) => {
      item.tabIndex = item === target ? 0 : -1;
    });
    target.focus();
  };

  const close = (restoreFocus = false) => {
    const previousTrigger = trigger;
    if (previousTrigger) previousTrigger.setAttribute('aria-expanded', 'false');
    menu.querySelectorAll('[role="menuitem"]').forEach((item) => {
      item.tabIndex = -1;
    });
    menu.hidden = true;
    trigger = null;
    if (restoreFocus) previousTrigger?.focus();
  };

  const submitAction = async (form) => {
    if (!trigger) return;
    const captureId = trigger.dataset.captureId;
    const previousStatus = trigger.dataset.captureStatus;
    const customAction = form.matches('[data-menu-custom-action]');
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
      if (result.capture_status && result.capture_status !== previousStatus) {
        await window.Catch?.captureCollection?.transition([captureId], {
          status: result.capture_status,
        });
      } else if (customAction) {
        window.location.reload();
        return;
      }
      window.Catch?.notify?.(result.message || 'Action completed.');
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
      customActionForms.forEach((form) => {
        form.action = `/captures/${id}/actions/${encodeURIComponent(form.dataset.actionId)}`;
      });
      menu.hidden = false;
      position();
      focusItem(0);
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

  [archiveForm, trashForm, ...customActionForms].forEach((form) => {
    form?.addEventListener('submit', (event) => {
      event.preventDefault();
      submitAction(form);
    });
  });

  document.addEventListener('keydown', (event) => {
    if (menu.hidden) return;
    if (event.key === 'Escape') {
      event.preventDefault();
      close(true);
      return;
    }
    if (event.key === 'Tab') {
      close(true);
      return;
    }
    if (!['ArrowDown', 'ArrowUp', 'Home', 'End'].includes(event.key)) return;
    const items = visibleItems();
    if (!items.length) return;
    event.preventDefault();
    const current = items.indexOf(document.activeElement);
    const next = event.key === 'Home'
      ? 0
      : event.key === 'End'
        ? items.length - 1
        : event.key === 'ArrowDown'
          ? (current + 1 + items.length) % items.length
          : (current - 1 + items.length) % items.length;
    focusItem(next);
  });
  menu.addEventListener('focusout', () => {
    queueMicrotask(() => {
      if (!menu.hidden && !menu.contains(document.activeElement)) close();
    });
  });
  window.addEventListener('scroll', close, { passive: true });
  window.addEventListener('resize', close);
}
