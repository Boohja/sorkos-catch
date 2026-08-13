const MAX_COLLECTION_FETCHES = 3;

async function fetchPreview(captureId, csrf) {
  const body = new FormData();
  body.set('_csrf', csrf);

  try {
    const response = await fetch(
      `/captures/${encodeURIComponent(captureId)}/preview`,
      {
        method: 'POST',
        headers: { Accept: 'application/json' },
        body,
      },
    );
    const result = await response.json().catch(() => ({}));

    return response.ok ? result : { updated: false };
  } catch {
    return { updated: false };
  }
}

function whenIdle(callback) {
  if ('requestIdleCallback' in window) {
    window.requestIdleCallback(callback, { timeout: 1500 });
    return;
  }

  window.setTimeout(callback, 400);
}

function initDetailPreview() {
  const capture = document.querySelector(
    '[data-capture-detail][data-preview-fetch-due]',
  );
  if (!capture) return;

  whenIdle(async () => {
    if (document.visibilityState !== 'visible') return;

    capture.removeAttribute('data-preview-fetch-due');
    const result = await fetchPreview(
      capture.dataset.captureId,
      capture.dataset.csrf || '',
    );
    if (result.updated) window.location.reload();
  });
}

function initCollectionPreviews(scope = document) {
  const collection = scope.closest?.('[data-capture-collection]')
    || document.querySelector('[data-capture-collection]');
  if (!collection || !('IntersectionObserver' in window)) return;

  const candidates = scope.matches?.('[data-preview-fetch-due]')
    ? [scope]
    : Array.from(scope.querySelectorAll('[data-preview-fetch-due]'));
  if (!candidates.length) return;

  let started = 0;
  let active = 0;
  const finish = () => {
    active -= 1;
  };

  const observer = new IntersectionObserver((entries) => {
    for (const entry of entries) {
      if (!entry.isIntersecting || started >= MAX_COLLECTION_FETCHES) continue;

      const capture = entry.target;
      observer.unobserve(capture);
      capture.removeAttribute('data-preview-fetch-due');
      started += 1;
      active += 1;

      whenIdle(async () => {
        if (document.visibilityState === 'visible') {
          const result = await fetchPreview(
            capture.dataset.captureId,
            collection.dataset.csrf || '',
          );
          if (result.updated && result.html && capture.isConnected) {
            const template = document.createElement('template');
            template.innerHTML = result.html.trim();
            const replacement = template.content.firstElementChild;
            if (replacement) {
              capture.replaceWith(replacement);
              document.dispatchEvent(new CustomEvent('capture:collection-inserted', {
                detail: { item: replacement },
              }));
            }
          }
        }
        finish();
      });
    }

    if (started >= MAX_COLLECTION_FETCHES) observer.disconnect();
  }, { rootMargin: '120px 0px' });

  candidates.forEach((candidate) => observer.observe(candidate));
}

export function initCapturePreview() {
  initDetailPreview();
  initCollectionPreviews();
  document.addEventListener('capture:collection-inserted', (event) => {
    if (event.detail.item.matches('[data-preview-fetch-due]')) {
      initCollectionPreviews(event.detail.item);
    }
  });
}
