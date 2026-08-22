const CACHE = 'catch-shell-v37';
const SHELL = [
  '/offline.html',
  '/manifest.webmanifest',
  '/assets/css/app.css?v=4',
  '/assets/css/components.css?v=4',
  '/assets/css/auth.css?v=2',
  '/assets/css/devices.css?v=10',
  '/assets/css/device-detail.css?v=6',
  '/assets/css/device-provenance.css?v=1',
  '/assets/css/capture-detail.css?v=13',
  '/assets/css/capture-collection.css?v=5',
  '/assets/css/capture-bulk.css?v=3',
  '/assets/css/tags.css?v=1',
  '/assets/css/lists.css?v=2',
  '/assets/css/pair.css?v=1',
  '/assets/css/coming-soon.css?v=2',
  '/assets/css/shell.css?v=3',
  '/assets/css/account.css?v=2',
  '/assets/css/layout-compat.css?v=1',
  '/assets/css/share-target.css?v=1',
  '/assets/js/app.js?v=40',
  '/assets/js/devices.js?v=6',
  '/assets/js/theme.js?v=2',
  '/assets/js/user-menu.js?v=2',
  '/assets/js/capture-view.js?v=3',
  '/assets/js/relative-time.js?v=2',
  '/assets/js/capture-collection.js?v=3',
  '/assets/js/capture-bulk.js?v=6',
  '/assets/js/capture-create.js?v=2',
  '/assets/js/share-target.js?v=2',
  '/assets/js/db.js',
  '/assets/js/sync-manager.js',
  '/assets/vendor/qrcode.js',
  '/assets/favicon/favicon.ico',
  '/assets/favicon/favicon.svg',
  '/assets/favicon/favicon-16x16.png',
  '/assets/favicon/favicon-32x32.png',
  '/assets/favicon/apple-touch-icon.png',
  '/assets/favicon/pwa-192x192.png',
  '/assets/favicon/pwa-512x512.png',
  '/assets/favicon/maskable-192x192.png',
  '/assets/favicon/maskable-512x512.png',
  '/assets/logo/landscape_dark_small.png',
  '/assets/logo/landscape_dark.svg',
];

self.addEventListener('install', (event) => {
  event.waitUntil(caches.open(CACHE).then((cache) => cache.addAll(SHELL)));
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys()
      .then((keys) => Promise.all(keys.filter((key) => key !== CACHE).map((key) => caches.delete(key))))
      .then(() => self.clients.claim()),
  );
});

function openDb() {
  return new Promise((resolve, reject) => {
    const request = indexedDB.open('catch', 2);
    request.onupgradeneeded = () => {
      const db = request.result;
      if (!db.objectStoreNames.contains('outbox')) db.createObjectStore('outbox', { keyPath: 'client_capture_id' });
      if (!db.objectStoreNames.contains('meta')) db.createObjectStore('meta', { keyPath: 'key' });
      if (!db.objectStoreNames.contains('share-targets')) db.createObjectStore('share-targets', { keyPath: 'client_capture_id' });
    };
    request.onsuccess = () => resolve(request.result);
    request.onerror = () => reject(request.error);
  });
}

async function stageShare(request) {
  const fallback = request.clone();
  try {
    const form = await request.formData();
    const clientCaptureId = `web_share_${crypto.randomUUID()}`;
    const files = form.getAll('files')
      .filter((file) => file instanceof File && file.size > 0)
      .map((file) => ({
        blob: file,
        name: file.name,
        type: file.type,
        lastModified: file.lastModified,
      }));
    const item = {
      client_capture_id: clientCaptureId,
      title: String(form.get('title') || '').trim(),
      text: String(form.get('text') || '').trim(),
      url: String(form.get('url') || '').trim(),
      files,
      status: 'pending',
      attempts: 0,
      created_at: new Date().toISOString(),
    };
    const db = await openDb();
    await new Promise((resolve, reject) => {
      const transaction = db.transaction('share-targets', 'readwrite');
      transaction.objectStore('share-targets').put(item);
      transaction.oncomplete = resolve;
      transaction.onerror = () => reject(transaction.error);
    });
    return Response.redirect(`/share?id=${encodeURIComponent(clientCaptureId)}`, 303);
  } catch (error) {
    try {
      return await fetch(fallback);
    } catch {
      return sharePage('Couldn’t receive capture', 'Catch could not store the shared item on this device.', true);
    }
  }
}

function sharePage(title = 'Saved for later', message = 'You’re offline. Catch will finish this capture when a connection returns.', isError = false) {
  const state = isError ? 'error' : 'queued';
  return new Response(`<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover"><meta name="theme-color" content="#18181b"><meta name="csrf-token" content=""><link rel="stylesheet" href="/assets/css/app.css?v=4"><link rel="stylesheet" href="/assets/css/components.css?v=4"><link rel="stylesheet" href="/assets/css/share-target.css?v=1"><title>${title} | Catch</title></head><body><main class="share-main"><section class="share-target" data-share-target data-state="${state}" data-capture-url=""><div class="share-target-inner"><div class="share-target-mark" aria-hidden="true"><span class="share-target-glyph"></span></div><h1 data-share-title>${title}</h1><p data-share-message>${message}</p><p class="sr-only" role="status" aria-live="polite" data-share-status>${message}</p><div class="share-target-actions"><button class="button button-secondary" type="button" data-share-retry hidden>Retry</button><a class="button button-primary" href="/inbox" data-share-inbox hidden>Open inbox</a><a class="button button-primary" href="/inbox" data-share-open hidden>Open capture</a></div></div></section></main><script type="module" src="/assets/js/share-target.js?v=2"></script></body></html>`, {
    headers: { 'Content-Type': 'text/html; charset=utf-8' },
  });
}

self.addEventListener('fetch', (event) => {
  const request = event.request;
  const url = new URL(request.url);

  if (request.method === 'POST' && url.origin === self.location.origin && url.pathname === '/share') {
    event.respondWith(stageShare(request));
    return;
  }
  if (
    request.method !== 'GET'
    || url.pathname.startsWith('/api/')
    || url.pathname.startsWith('/attachments/')
    || request.destination==='audio'
    || request.destination==='video'
  ) return;

  if (request.mode === 'navigate') {
    event.respondWith(
      fetch(request).catch(() => (
        url.pathname === '/share'
          ? sharePage()
          : caches.match('/offline.html')
      )),
    );
    return;
  }

  event.respondWith(caches.match(request).then((hit) => hit || fetch(request)));
});
