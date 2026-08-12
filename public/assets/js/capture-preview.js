export function initCapturePreview() {
  const capture = document.querySelector('[data-capture-detail][data-preview-fetch-due]');
  if (!capture) return;

  const fetchPreview = async () => {
    if (document.visibilityState !== 'visible') return;

    capture.removeAttribute('data-preview-fetch-due');
    const body = new FormData();
    body.set('_csrf', capture.dataset.csrf || '');

    try {
      const response = await fetch(
        `/captures/${encodeURIComponent(capture.dataset.captureId)}/preview`,
        {
          method: 'POST',
          headers: { Accept: 'application/json' },
          body,
        },
      );
      const result = await response.json().catch(() => ({}));
      if (response.ok && result.updated) window.location.reload();
    } catch {
      // Preview generation is optional; the stored capture remains usable.
    }
  };

  if ('requestIdleCallback' in window) {
    window.requestIdleCallback(fetchPreview, { timeout: 1500 });
  } else {
    window.setTimeout(fetchPreview, 400);
  }
}
