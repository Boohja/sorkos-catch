import { initTheme } from './theme.js?v=2';
import { onSync, syncOutbox } from './sync-manager.js?v=2';
import { initRequestProgress } from './request-progress.js?v=1';
import { initCaptureEditing } from './capture-edit.js?v=6';
import { initDevices } from './devices.js?v=6';
import { initCaptureTags } from './capture-tags.js?v=4';
import { initCaptureLists } from './capture-lists.js?v=9';
import { initCaptureBulk } from './capture-bulk.js?v=7';
import { initCaptureView } from './capture-view.js?v=3';
import { initRelativeTime } from './relative-time.js?v=2';
import { initCaptureActions } from './capture-actions.js?v=2';
import { initCaptureLater } from './capture-later.js?v=1';
import { initCapturePreview } from './capture-preview.js?v=2';
import { initCaptureCreate } from './capture-create.js?v=2';
import { initCaptureCollection } from './capture-collection.js?v=3';
import { initUserMenu } from './user-menu.js?v=2';
import { initMobileNavigation } from './mobile-navigation.js?v=1';
import { initCaptureMove } from './capture-move.js?v=1';

initTheme();
initRequestProgress();
initDevices();
initCaptureTags();
initCaptureLists();
initCaptureCollection();
initCaptureLater();
initCaptureEditing();
initCaptureBulk();
initCaptureView();
initRelativeTime();
initCaptureActions();
initCapturePreview();
initCaptureCreate();
initUserMenu();
initMobileNavigation();
initCaptureMove();

if ('serviceWorker' in navigator) {
  navigator.serviceWorker.register('/service-worker.js').catch(() => {});
}

document.querySelectorAll('[data-local-time]').forEach((element) => {
  const value = new Date(element.dateTime);
  if (Number.isNaN(value.getTime())) return;

  const options = {};
  if (element.dataset.dateStyle) options.dateStyle = element.dataset.dateStyle;
  if (element.dataset.timeStyle) options.timeStyle = element.dataset.timeStyle;
  element.textContent = new Intl.DateTimeFormat(undefined, options).format(value);
  element.title = `${value.toISOString()} · ${Intl.DateTimeFormat().resolvedOptions().timeZone}`;
});

const toastRegion = document.querySelector('[data-toast-region]');
const syncToast = document.querySelector('[data-sync-status]');
const summary = document.querySelector('[data-sync-summary]');
const toastTimers = new WeakMap();
const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

const dismissToast = async (toast, remove = true) => {
  window.clearTimeout(toastTimers.get(toast));
  toastTimers.delete(toast);
  if (!reducedMotion) {
    const animation = toast.animate(
      [
        { opacity: 1, transform: 'translateY(0)', clipPath: 'inset(0)' },
        { opacity: 0, transform: 'translateY(5px)', clipPath: 'inset(0 0 12% 0)' },
      ],
      { duration: 160, easing: 'cubic-bezier(.4,0,1,1)', fill: 'forwards' },
    );
    await animation.finished.catch(() => {});
  }
  if (remove) toast.remove();
  else toast.hidden = true;
};

const scheduleToast = (toast, duration = 5000) => {
  window.clearTimeout(toastTimers.get(toast));
  toastTimers.set(toast, window.setTimeout(() => dismissToast(toast), duration));
};

const createToast = (message, error = false) => {
  if (!toastRegion) return;
  const toast = document.createElement('div');
  toast.className = `toast toast-${error ? 'error' : 'success'}`;
  toast.dataset.toast = '';
  toast.setAttribute('role', error ? 'alert' : 'status');

  const text = document.createElement('span');
  text.textContent = message;
  const dismiss = document.createElement('button');
  dismiss.type = 'button';
  dismiss.dataset.toastDismiss = '';
  dismiss.textContent = 'Dismiss';
  toast.append(text, dismiss);
  toastRegion.prepend(toast);
  scheduleToast(toast);
};

toastRegion?.addEventListener('click', (event) => {
  const dismiss = event.target.closest('[data-toast-dismiss]');
  if (!dismiss) return;
  const toast = dismiss.closest('[data-toast]');
  if (toast) dismissToast(toast, toast !== syncToast);
});

toastRegion?.querySelectorAll('[data-auto-dismiss]').forEach((toast) => {
  scheduleToast(toast, Number(toast.dataset.autoDismiss) || 5000);
});

window.Catch = window.Catch || {};
window.Catch.notify = createToast;
onSync(({ state, count = 0 }) => {
  const text = state === 'offline'
    ? 'Offline — saved locally'
    : state === 'syncing'
      ? `Syncing ${count}`
      : state === 'failed'
        ? `${count} waiting to sync`
        : 'Everything synced';
  if (summary) summary.textContent = text;
  if (syncToast && state !== 'synced') {
    syncToast.querySelector('[data-toast-message]').textContent = text;
    syncToast.hidden = false;
  } else if (syncToast) {
    syncToast.hidden = true;
  }
});

window.addEventListener('online', () => {
  if (!document.querySelector('[data-share-target]')) syncOutbox();
});
document.addEventListener('visibilitychange', () => {
  if (document.visibilityState === 'visible' && !document.querySelector('[data-share-target]')) syncOutbox();
});
if (!document.querySelector('[data-share-target]')) syncOutbox();

document.querySelectorAll('[data-copy-button]').forEach((button) => {
  button.addEventListener('click', async (event) => {
    const trigger = event.currentTarget;
    const row = trigger.closest('[data-copy-row],.pairing-code-row');
    const value = row?.querySelector('[data-copy-source]')?.textContent?.trim();
    if (!value) return;

    await navigator.clipboard.writeText(value);
    trigger.textContent = 'Copied';
  });
});
