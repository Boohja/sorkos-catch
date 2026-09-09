const LAST_KNOWN_COUNT_KEY = 'catch-inbox-count';

function storedInboxCount() {
  try {
    const count = Number(localStorage.getItem(LAST_KNOWN_COUNT_KEY));
    return Number.isFinite(count) ? Math.max(0, Math.trunc(count)) : 0;
  } catch {
    return 0;
  }
}

function rememberInboxCount(count) {
  try {
    localStorage.setItem(LAST_KNOWN_COUNT_KEY, String(count));
  } catch {
    // The badge still works when private browsing prevents local storage.
  }
}

function forgetInboxCount() {
  try {
    localStorage.removeItem(LAST_KNOWN_COUNT_KEY);
  } catch {
    // Nothing else is required when storage is unavailable.
  }
}

function updateAppBadge(total, remember = false) {
  if (!Number.isFinite(total)) return;
  const count = Math.max(0, Math.trunc(total));
  if (remember) rememberInboxCount(count);

  try {
    const operation = count > 0
      ? navigator.setAppBadge(count)
      : navigator.clearAppBadge();

    Promise.resolve(operation).catch(() => {
      // Badge permission and launcher support are controlled by the platform.
    });
  } catch {
    // A platform may expose the API while denying it in the current context.
  }
}

function initBadgePermissionControl() {
  const settings = document.querySelector('[data-app-badge-settings]');
  if (!settings) return;

  const button = settings.querySelector('[data-enable-app-badge]');
  const status = settings.querySelector('[data-app-badge-status]');
  settings.hidden = false;

  const isAppleMobile = /iPhone|iPad/.test(navigator.userAgent)
    || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
  if (!isAppleMobile || !('Notification' in window)) {
    button.hidden = true;
    status.textContent = 'Catch updates the badge automatically on supported app launchers.';
    return;
  }

  const syncPermissionState = () => {
    button.hidden = Notification.permission === 'granted';
    button.disabled = Notification.permission === 'denied';
    status.textContent = Notification.permission === 'granted'
      ? 'App badge enabled.'
      : Notification.permission === 'denied'
        ? 'Badge permission is blocked in your iPhone or iPad settings.'
        : '';
  };

  button.addEventListener('click', async () => {
    button.disabled = true;
    const permission = await Notification.requestPermission().catch(() => 'denied');
    if (permission === 'granted') updateAppBadge(storedInboxCount());
    syncPermissionState();
  });
  syncPermissionState();
}

export function initAppBadge() {
  if (!('setAppBadge' in navigator) || !('clearAppBadge' in navigator)) return;
  initBadgePermissionControl();

  if (document.body.dataset.authenticated !== 'true') {
    forgetInboxCount();
    updateAppBadge(0);
    return;
  }

  const inbox = document.querySelector('[data-capture-collection][data-collection-status="inbox"]');
  if (!inbox) {
    updateAppBadge(storedInboxCount());
    return;
  }

  updateAppBadge(inbox.querySelectorAll('article.capture-item[data-capture-id]').length, true);
  document.addEventListener('capture:collection-changed', (event) => {
    updateAppBadge(event.detail?.total, true);
  });
}
