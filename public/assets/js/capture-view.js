const STORAGE_KEY = 'catch-capture-view';
const VIEWS = new Set(['list', 'grid']);

function savedView() {
  try {
    const value = localStorage.getItem(STORAGE_KEY);
    return VIEWS.has(value) ? value : 'list';
  } catch {
    return 'list';
  }
}

function saveView(view) {
  try {
    localStorage.setItem(STORAGE_KEY, view);
  } catch {
    // The selected view still applies to the current page when storage is unavailable.
  }
}

export function initCaptureView() {
  document.querySelectorAll('.capture-visual-image img').forEach((image) => {
    const markUnavailable = () => image.closest('.capture-visual-image')?.classList.add('is-unavailable');
    image.addEventListener('error', markUnavailable, { once: true });
    if (image.complete && image.naturalWidth === 0) markUnavailable();
  });

  const collection = document.querySelector('.capture-collection-switchable[data-capture-collection]');
  const switcher = document.querySelector('[data-capture-view-switch]');
  if (!collection || !switcher) return;

  const buttons = Array.from(switcher.querySelectorAll('[data-capture-view]'));
  const apply = (view) => {
    if (!VIEWS.has(view)) return;
    collection.dataset.view = view;
    buttons.forEach((button) => {
      button.setAttribute('aria-pressed', String(button.dataset.captureView === view));
    });
  };

  apply(savedView());
  buttons.forEach((button) => {
    button.addEventListener('click', () => {
      const view = button.dataset.captureView;
      apply(view);
      saveView(view);
    });
  });
}
