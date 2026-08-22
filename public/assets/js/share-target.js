import { get } from './db.js';
import { saveSharedCapture } from './sync-manager.js';

const root = document.querySelector('[data-share-target]');

if (root) {
  const debug = window.CatchShareDebug;
  debug?.started?.();
  const trace = (step, detail = {}) => debug?.log?.(step, detail);
  const errorDetail = (error) => ({
    name: error?.name || 'Error',
    message: error?.message || String(error || 'Unknown error'),
    status: error?.status,
  });
  const withTimeout = (promise, milliseconds, label) => {
    let timer;
    const timeout = new Promise((resolve, reject) => {
      timer = window.setTimeout(() => {
        const error = new Error(`${label} timed out after ${milliseconds / 1000} seconds.`);
        error.status = 408;
        reject(error);
      }, milliseconds);
    });
    return Promise.race([promise, timeout]).finally(() => window.clearTimeout(timer));
  };
  const title = root.querySelector('[data-share-title]');
  const message = root.querySelector('[data-share-message]');
  const status = root.querySelector('[data-share-status]');
  const retry = root.querySelector('[data-share-retry]');
  const openInbox = root.querySelector('[data-share-inbox]');
  const openCapture = root.querySelector('[data-share-open]');
  const id = new URLSearchParams(location.search).get('id');
  const pageCsrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
  let working = false;

  trace('module.context', {
    hasQueueId: Boolean(id),
    hasServerError: Boolean(root.dataset.shareError),
    hasCompletedUrl: Boolean(root.dataset.captureUrl),
    hasCsrf: Boolean(pageCsrf),
    online: navigator.onLine,
    serviceWorkerControlled: Boolean(navigator.serviceWorker?.controller),
  });

  const setState = (state, heading, copy) => {
    trace('ui.state', { state });
    root.dataset.state = state;
    title.textContent = heading;
    message.textContent = copy;
    status.textContent = copy;
    retry.hidden = state !== 'error';
    openInbox.hidden = !['queued', 'error'].includes(state);
    openCapture.hidden = true;
  };

  const finish = (url) => {
    trace('capture.finished', { destination: url });
    root.dataset.state = 'success';
    title.textContent = 'Capture saved';
    message.textContent = 'Opening your capture…';
    status.textContent = 'Capture saved. Opening it now.';
    openInbox.hidden = true;
    openCapture.href = url;
    openCapture.hidden = false;
    window.setTimeout(() => location.replace(url), 450);
  };

  const process = async () => {
    if (working) {
      trace('process.skipped', { reason: 'already-working' });
      return;
    }
    trace('process.begin', { online: navigator.onLine });
    const serverError = root.dataset.shareError;
    if (serverError) {
      trace('server.error', { message: serverError });
      setState('error', 'Couldn’t finish capture', serverError);
      return;
    }
    const completedUrl = root.dataset.captureUrl;
    if (completedUrl) {
      trace('server.capture-ready', { destination: completedUrl });
      window.setTimeout(() => finish(completedUrl), 500);
      return;
    }
    if (!id) {
      trace('queue.id-missing');
      setState('error', 'Nothing to process', 'Share something with Catch and try again.');
      return;
    }

    trace('queue.lookup.start');
    let item;
    try {
      item = await get('share-targets', id);
    } catch (error) {
      trace('queue.lookup.failed', errorDetail(error));
      setState('error', 'Local queue unavailable', 'Catch could not open the saved share on this device.');
      return;
    }
    if (!item) {
      trace('queue.lookup.empty');
      setState('error', 'Capture unavailable', 'The shared item is no longer in the local queue.');
      return;
    }
    trace('queue.lookup.complete', {
      attempts: item.attempts || 0,
      fileCount: item.files?.length || 0,
      hasTitle: Boolean(item.title),
      hasText: Boolean(item.text),
      hasUrl: Boolean(item.url),
    });
    if (!navigator.onLine) {
      trace('network.offline');
      setState('queued', 'Saved for later', 'You’re offline. Catch will finish this capture when a connection returns.');
      return;
    }

    working = true;
    setState('processing', 'Processing capture', 'Keeping the original safe while Catch prepares your capture.');
    const minimumSplash = new Promise((resolve) => window.setTimeout(resolve, 700));
    try {
      let csrf = pageCsrf;
      if (!csrf) {
        trace('csrf.request.start');
        const tokenResponse = await withTimeout(
          fetch(`/share?id=${encodeURIComponent(id)}`, {
            headers: { Accept: 'text/html' },
            cache: 'no-store',
          }),
          15000,
          'Session refresh',
        );
        trace('csrf.request.response', {
          status: tokenResponse.status,
          redirected: tokenResponse.redirected,
        });
        if (tokenResponse.redirected) {
          trace('csrf.request.redirected', { destination: new URL(tokenResponse.url).pathname });
          location.assign(tokenResponse.url);
          return;
        }
        const tokenPage = new DOMParser().parseFromString(await tokenResponse.text(), 'text/html');
        csrf = tokenPage.querySelector('meta[name="csrf-token"]')?.content || '';
        if (!csrf) {
          const tokenError = new Error('Sign in to Catch and retry.');
          tokenError.status = 401;
          throw tokenError;
        }
        trace('csrf.available');
      }
      trace('capture.request.start');
      const [result] = await Promise.all([
        withTimeout(saveSharedCapture(item, csrf), 20000, 'Capture request'),
        minimumSplash,
      ]);
      trace('capture.request.complete', { hasDestination: Boolean(result.url) });
      finish(result.url);
    } catch (error) {
      trace('process.failed', errorDetail(error));
      if (!error.status) {
        setState('queued', 'Saved for later', 'The connection dropped. Catch will finish this capture when you’re online.');
        return;
      }
      const expired = [401, 419].includes(error.status);
      setState(
        'error',
        expired ? 'Session expired' : 'Couldn’t finish capture',
        expired ? 'Open Catch and sign in, then retry this capture.' : 'The original is still saved locally. Check your connection and retry.',
      );
    } finally {
      working = false;
      trace('process.end');
    }
  };

  retry.addEventListener('click', () => {
    trace('retry.clicked');
    process();
  });
  window.addEventListener('online', () => {
    trace('network.online');
    process();
  });
  process();
}
