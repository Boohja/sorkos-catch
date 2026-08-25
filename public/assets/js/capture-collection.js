const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

function collection() {
  return document.querySelector('[data-capture-collection]');
}

function captureItems(root = collection()) {
  return root ? Array.from(root.querySelectorAll('article.capture-item[data-capture-id]')) : [];
}

function renderEmpty(root) {
  if (root.querySelector('.empty-state')) return;

  const empty = document.createElement('div');
  empty.className = 'empty-state';
  empty.innerHTML = `
    <h2></h2>
    <p></p>
  `;
  empty.querySelector('h2').textContent = root.dataset.emptyTitle || 'Nothing here yet';
  empty.querySelector('p').textContent = root.dataset.emptyText || 'Captures matching this view will appear here.';
  root.append(empty);
}

function syncCollection(root = collection()) {
  if (!root) return;

  const total = captureItems(root).length;
  const count = document.querySelector('[data-capture-count]');
  const switcher = document.querySelector('[data-capture-view-switch]');
  if (count) {
    const singular = count.dataset.singular || 'capture';
    const plural = count.dataset.plural || `${singular}s`;
    count.textContent = `${total} ${total === 1 ? singular : plural}`;
  }
  if (switcher) switcher.hidden = total === 0;
  if (total === 0) renderEmpty(root);
  else root.querySelector('.empty-state')?.remove();

  document.dispatchEvent(new CustomEvent('capture:collection-changed', {
    detail: { total },
  }));
}

async function animateRemoval(item) {
  if (reducedMotion) {
    item.remove();
    return;
  }

  const root = item.closest('[data-capture-collection]');
  const grid = root?.dataset.view === 'grid';
  const animation = item.animate(
    grid
      ? [
        { opacity: 1, transform: 'scale(1)', clipPath: 'inset(0)' },
        { opacity: 0, transform: 'scale(.985)', clipPath: 'inset(0 0 8% 0)' },
      ]
      : [
        { opacity: 1, transform: 'translateY(0)', maxHeight: `${item.offsetHeight}px` },
        { opacity: 0, transform: 'translateY(-4px)', maxHeight: '0px' },
      ],
    { duration: 180, easing: 'cubic-bezier(.2,.8,.2,1)', fill: 'forwards' },
  );
  await animation.finished.catch(() => {});
  item.remove();
}

function shouldLeaveCollection(root, transition) {
  const nextStatus = transition.status;
  const currentStatus = root.dataset.collectionStatus;
  const listId = root.dataset.collectionListId;

  if (listId && nextStatus && nextStatus !== 'archived') return true;
  if (listId && Array.isArray(transition.listIds)) {
    return !transition.listIds.includes(listId);
  }
  if (currentStatus === 'inbox') return nextStatus && nextStatus !== 'inbox';
  if (currentStatus === 'archived') return nextStatus && nextStatus !== 'archived';
  if (currentStatus === 'trash') return nextStatus && nextStatus !== 'trash';
  return false;
}

async function transition(ids, change = {}) {
  const root = collection();
  if (!root || !shouldLeaveCollection(root, change)) return;

  const idSet = new Set(ids);
  const departing = captureItems(root).filter((item) => idSet.has(item.dataset.captureId));
  await Promise.all(departing.map(animateRemoval));
  syncCollection(root);
  window.Catch?.clearCaptureSelection?.();
}

function insert(html) {
  const root = collection();
  if (!root || !html) return null;

  const template = document.createElement('template');
  template.innerHTML = html.trim();
  const item = template.content.firstElementChild;
  if (!item) return null;
  const id = item.dataset.captureId;
  if (id && root.querySelector(`[data-capture-id="${CSS.escape(id)}"]`)) return null;

  root.querySelector('.empty-state')?.remove();
  root.prepend(item);
  syncCollection(root);
  document.dispatchEvent(new CustomEvent('capture:collection-inserted', {
    detail: { item },
  }));

  if (!reducedMotion) {
    item.animate(
      [
        { opacity: 0, transform: 'translateY(-5px)', clipPath: 'inset(0 0 8% 0)' },
        { opacity: 1, transform: 'translateY(0)', clipPath: 'inset(0)' },
      ],
      { duration: 200, easing: 'cubic-bezier(.2,.8,.2,1)' },
    );
  }
  return item;
}

async function submitCollectionAction(form) {
  const item = form.closest('[data-capture-id]');
  if (!item) return;

  const button = form.querySelector('button[type="submit"]');
  if (button) button.disabled = true;
  try {
    const response = await fetch(form.action, {
      method: form.method || 'POST',
      headers: { Accept: 'application/json' },
      body: new FormData(form),
    });
    const result = await response.json().catch(() => ({}));
    if (!response.ok) throw new Error(result.error || 'The capture could not be updated.');
    await transition([item.dataset.captureId], { status: result.capture_status });
  } catch (error) {
    window.Catch?.notify?.(error.message || 'The capture could not be updated.', true);
    if (button) button.disabled = false;
  }
}

export function initCaptureCollection() {
  window.Catch = window.Catch || {};
  window.Catch.captureCollection = { insert, sync: syncCollection, transition };

  document.addEventListener('submit', (event) => {
    const form = event.target.closest('[data-capture-collection-action]');
    if (!form) return;
    event.preventDefault();
    submitCollectionAction(form);
  });

  const root = collection();
  const pollUrl = root?.dataset.capturePollUrl;
  if (!pollUrl) return;

  let timer;
  let polling = false;
  const schedule = (delay = 45_000) => {
    window.clearTimeout(timer);
    timer = window.setTimeout(poll, delay);
  };
  const poll = async () => {
    if (polling || document.visibilityState !== 'visible' || !navigator.onLine) {
      schedule();
      return;
    }
    polling = true;
    try {
      const url = new URL(pollUrl, window.location.origin);
      url.searchParams.set('after', root.dataset.capturePollAfter || '0');
      const response = await fetch(url, {
        headers: { Accept: 'application/json' },
        cache: 'no-store',
      });
      const contentType = response.headers.get('content-type') || '';
      if (!response.ok || !contentType.includes('application/json')) return;
      const result = await response.json();
      if (Array.isArray(result.html)) result.html.forEach(insert);
      if (Number.isInteger(result.cursor)) root.dataset.capturePollAfter = String(result.cursor);
    } catch {
      // Polling is deliberately silent; the next interval retries.
    } finally {
      polling = false;
      schedule();
    }
  };

  document.addEventListener('visibilitychange', () => {
    if (document.visibilityState === 'visible') schedule(250);
  });
  window.addEventListener('online', () => schedule(250));
  schedule();
}
