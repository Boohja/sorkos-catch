if (typeof CatchExt === 'undefined' && typeof importScripts === 'function') {
  importScripts('shared/browser.js', 'shared/config.js', 'shared/storage.js', 'shared/api.js');
}

(function () {
  const ext = CatchExt.browser;
  const raw = ext.raw;
  const PAIRING_ALARM = 'catch-pairing-poll';
  const EXTENSION_VERSION = raw.runtime.getManifest().version;

  function randomId(prefix) {
    if (crypto.randomUUID) return `${prefix}${crypto.randomUUID()}`;
    const bytes = crypto.getRandomValues(new Uint8Array(16));
    return `${prefix}${Array.from(bytes, (byte) => byte.toString(16).padStart(2, '0')).join('')}`;
  }

  function base64Url(bytes) {
    let binary = '';
    bytes.forEach((byte) => { binary += String.fromCharCode(byte); });
    return btoa(binary).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
  }

  async function challengeFor(verifier) {
    const digest = await crypto.subtle.digest('SHA-256', new TextEncoder().encode(verifier));
    return base64Url(new Uint8Array(digest));
  }

  function domainFromUrl(value) {
    try { return new URL(value).hostname.replace(/^www\./, ''); } catch { return ''; }
  }

  function normalizedText(value) {
    return String(value || '').replace(/\r\n?/g, '\n').replace(/[ \t]+\n/g, '\n').replace(/\n{3,}/g, '\n\n').trim();
  }

  function capturePayload(values) {
    const url = values.url || '';
    const context = values.context || 'popup';
    const sourceUrl = values.sourceUrl ?? url;
    const sourceTitle = normalizedText(values.sourceTitle ?? values.pageTitle ?? values.title);
    let title = normalizedText(values.title || values.pageTitle).slice(0, 500);
    if (!title && !['link-context-menu', 'image-context-menu'].includes(context)) title = domainFromUrl(url);
    return {
      id: values.id || randomId('browser_capture_'),
      title,
      text: normalizedText(values.text),
      url,
      remoteAttachmentUrl: values.remoteAttachmentUrl || '',
      screenshotRequested: Boolean(values.screenshotRequested),
      tabId: values.tabId,
      windowId: values.windowId,
      metadata: {
        captured_at: values.capturedAt || new Date().toISOString(),
        source_url: sourceUrl,
        source_title: sourceTitle,
        source_domain: domainFromUrl(sourceUrl),
        browser_context: context,
        capture_method: context.includes('context-menu') ? 'browser-extension-context-menu' : 'browser-extension',
        extension_version: EXTENSION_VERSION,
        ...(values.metadata || {}),
      },
    };
  }

  function captureEvent(capture, status, details = {}) {
    const title = capture.title || capture.metadata?.source_title || domainFromUrl(capture.url || capture.metadata?.source_url);
    return {
      captureId: capture.id,
      status,
      title: normalizedText(title).slice(0, 500),
      url: capture.remoteAttachmentUrl || capture.url || capture.metadata?.source_url || '',
      context: capture.metadata?.browser_context || '',
      ...details,
    };
  }

  async function recordCaptureEvent(capture, status, details = {}) {
    try { await CatchExt.store.recordCaptureEvent(captureEvent(capture, status, details)); } catch {}
  }

  async function showBadge(text, color, title) {
    await Promise.allSettled([
      ext.action.setBadgeText({ text }),
      ext.action.setBadgeBackgroundColor({ color }),
      ext.action.setTitle({ title }),
    ]);
    setTimeout(() => {
      ext.action.setBadgeText({ text: '' }).catch(() => {});
      ext.action.setTitle({ title: 'Catch this page' }).catch(() => {});
    }, 2800);
  }

  async function showPageFeedback(tabId, message, tone = 'success') {
    if (!Number.isInteger(tabId)) return;
    try { await ext.tabs.sendMessage(tabId, { type: 'feedback.toast', message, tone }); } catch {}
  }

  async function openSetup() {
    try { await ext.runtime.openOptionsPage(); }
    catch { await ext.tabs.create({ url: ext.runtime.getURL('setup/setup.html') }); }
  }

  async function captureViewport(capture, queued) {
    if (!capture.screenshotRequested) return null;
    const tab = await ext.tabs.get(capture.tabId);
    if (!tab?.active) {
      if (queued) capture.metadata.screenshot_omitted = 'The original tab was no longer active after setup.';
      return null;
    }
    return ext.tabs.captureVisibleTab(tab.windowId, { format: 'png' });
  }

  async function submitCapture(capture, options = {}) {
    const token = await CatchExt.store.getDeviceToken();
    if (!token) {
      await CatchExt.store.addPendingCapture(capture);
      await recordCaptureEvent(capture, 'queued', { message: 'Waiting for Catch to be connected.' });
      await openSetup();
      await showBadge('SET', '#f59e0b', 'Connect Catch to send the saved capture');
      return { status: 'queued', setupRequired: true };
    }

    try {
      await recordCaptureEvent(capture, 'sending');
      const screenshot = await captureViewport(capture, Boolean(options.queued));
      const result = await CatchExt.api.createCapture(token, capture, screenshot);
      await recordCaptureEvent(capture, 'saved', { catchNumber: result.catch_number });
      await showBadge('OK', '#d97706', `Saved Catch #${result.catch_number}`);
      await showPageFeedback(capture.tabId, `Catch #${result.catch_number} saved`);
      return { status: result.status, catchNumber: result.catch_number };
    } catch (error) {
      if (error.status === 401) {
        await CatchExt.store.clearConnection();
        await CatchExt.store.addPendingCapture(capture);
        await recordCaptureEvent(capture, 'queued', { message: 'The connection was revoked. Reconnect Catch to retry.' });
        await openSetup();
        return { status: 'queued', setupRequired: true };
      }
      await recordCaptureEvent(capture, 'failed', { message: error.message || 'Catch could not save this capture.', code: error.code || 'failed', httpStatus: error.status || 0 });
      await showBadge('!', '#dc2626', error.message || 'Catch failed');
      await showPageFeedback(capture.tabId, 'Catch could not be saved', 'error');
      throw error;
    }
  }

  async function processPendingCaptures() {
    const pending = await CatchExt.store.getPendingCaptures();
    const results = [];
    for (const capture of pending) {
      try {
        const result = await submitCapture(capture, { queued: true });
        if (!result.setupRequired) await CatchExt.store.removePendingCapture(capture.id);
        results.push({ id: capture.id, ...result });
        if (result.setupRequired) break;
      } catch (error) {
        results.push({ id: capture.id, status: 'failed', message: error.message });
      }
    }
    return results;
  }

  async function startPairing() {
    const connection = await CatchExt.store.getConnection();
    if (connection?.deviceToken) return { status: 'connected', connection };
    const existing = await CatchExt.store.getPairingSession();
    if (existing && Date.parse(existing.expiresAt) > Date.now()) {
      await ext.tabs.create({ url: existing.pairUrl });
      return { status: 'pending', expiresAt: existing.expiresAt };
    }
    await CatchExt.store.clearPairingSession();
    const verifier = base64Url(crypto.getRandomValues(new Uint8Array(32)));
    const pairing = await CatchExt.api.startPairing({
      codeChallenge: await challengeFor(verifier),
    });
    await CatchExt.store.savePairingSession({
      requestId: pairing.request_id,
      verifier,
      pairUrl: pairing.pair_url,
      expiresAt: pairing.expires_at,
    });
    ext.alarms.create(PAIRING_ALARM, { periodInMinutes: 0.5 });
    await ext.tabs.create({ url: pairing.pair_url });
    return { status: 'pending', expiresAt: pairing.expires_at };
  }

  async function pollPairing() {
    const connection = await CatchExt.store.getConnection();
    if (connection?.deviceToken) return { status: 'connected', connection };
    const session = await CatchExt.store.getPairingSession();
    if (!session) return { status: 'idle' };
    if (Date.parse(session.expiresAt) <= Date.now()) {
      await CatchExt.store.clearPairingSession();
      await ext.alarms.clear(PAIRING_ALARM);
      return { status: 'expired' };
    }
    try {
      const result = await CatchExt.api.exchangePairing({ requestId: session.requestId, codeVerifier: session.verifier });
      if (result.status === 'pending') return result;
      const connectionValue = {
        deviceToken: result.device_token,
        deviceId: result.device.id,
        deviceName: result.device.name,
      };
      await CatchExt.store.saveConnection(connectionValue);
      await CatchExt.store.clearPairingSession();
      await ext.alarms.clear(PAIRING_ALARM);
      const pendingResults = await processPendingCaptures();
      return { status: 'connected', connection: connectionValue, pendingResults };
    } catch (error) {
      if ([404, 410].includes(error.status)) {
        await CatchExt.store.clearPairingSession();
        await ext.alarms.clear(PAIRING_ALARM);
        return { status: 'expired' };
      }
      throw error;
    }
  }

  async function disconnect() {
    const token = await CatchExt.store.getDeviceToken();
    if (token) {
      try { await CatchExt.api.disconnect(token); }
      catch (error) { if (error.status !== 401) throw error; }
    }
    await CatchExt.store.clearConnection();
    await CatchExt.store.clearPairingSession();
    await ext.alarms.clear(PAIRING_ALARM);
    return { status: 'disconnected' };
  }

  async function connection() {
    const local = await CatchExt.store.getConnection();
    if (!local?.deviceToken) return { connection: null };
    try {
      const result = await CatchExt.api.validateConnection(local.deviceToken);
      const value = { ...local, deviceId: result.device.id, deviceName: result.device.name };
      await CatchExt.store.saveConnection(value);
      return { connection: value };
    } catch (error) {
      if (error.status === 401) {
        await CatchExt.store.clearConnection();
        return { connection: null, revoked: true };
      }
      throw error;
    }
  }

  async function registerContextMenus() {
    await ext.contextMenus.removeAll();
    await ext.contextMenus.create({ id: 'catch-page', title: 'Catch this page', contexts: ['page'] });
    await ext.contextMenus.create({ id: 'catch-selection', title: 'Catch selection', contexts: ['selection'] });
    await ext.contextMenus.create({ id: 'catch-link', title: 'Catch link', contexts: ['link'] });
    await ext.contextMenus.create({ id: 'catch-image', title: 'Catch image', contexts: ['image'] });
  }

  async function handleContextMenu(info, tab) {
    let capture;
    let target = {};
    try { target = await ext.tabs.sendMessage(tab?.id, { type: 'page.context-menu' }) || {}; } catch {}
    if (info.srcUrl && ['catch-image', 'catch-link'].includes(info.menuItemId)) {
      capture = capturePayload({
        text: '',
        title: '',
        url: info.srcUrl,
        remoteAttachmentUrl: info.srcUrl,
        tabId: tab?.id,
        windowId: tab?.windowId,
        context: 'image-context-menu',
        sourceUrl: info.pageUrl || tab?.url,
        sourceTitle: tab?.title || '',
        metadata: {
          linked_url: info.linkUrl || '',
          image_alt: target.imageAlt || '',
        },
      });
    } else if (info.menuItemId === 'catch-selection') {
      capture = capturePayload({
        text: info.selectionText,
        url: info.pageUrl || tab?.url,
        title: tab?.title,
        pageTitle: tab?.title,
        tabId: tab?.id,
        windowId: tab?.windowId,
        context: 'selection-context-menu',
      });
    } else if (info.menuItemId === 'catch-link') {
      capture = capturePayload({
        url: info.linkUrl,
        tabId: tab?.id,
        windowId: tab?.windowId,
        context: 'link-context-menu',
        sourceUrl: info.pageUrl || tab?.url,
        sourceTitle: tab?.title || '',
        metadata: { link_text: target.linkUrl === info.linkUrl ? target.linkText || '' : '' },
      });
    } else if (info.menuItemId === 'catch-page') {
      capture = capturePayload({
        text: tab?.title,
        url: info.pageUrl || tab?.url,
        title: tab?.title,
        pageTitle: tab?.title,
        tabId: tab?.id,
        windowId: tab?.windowId,
        context: 'page-context-menu',
      });
    }
    if (capture) await submitCapture(capture);
  }

  function respondWith(sendResponse, operation) {
    Promise.resolve(operation)
      .then((result) => sendResponse({ ok: true, ...result }))
      .catch((error) => sendResponse({ ok: false, error: error.message || 'The operation failed.', code: error.code || 'failed', status: error.status || 0 }));
    return true;
  }

  raw.runtime.onMessage.addListener((message, _sender, sendResponse) => {
    if (message?.type === 'capture.submit') return respondWith(sendResponse, submitCapture(capturePayload(message.capture || {})));
    if (message?.type === 'connection.get') return respondWith(sendResponse, connection());
    if (message?.type === 'pair.start') return respondWith(sendResponse, startPairing());
    if (message?.type === 'pair.status') return respondWith(sendResponse, pollPairing());
    if (message?.type === 'connection.disconnect') return respondWith(sendResponse, disconnect());
    if (message?.type === 'history.get') return respondWith(sendResponse, CatchExt.store.getCaptureHistory().then((history) => ({ history })));
    if (message?.type === 'history.clear') return respondWith(sendResponse, CatchExt.store.clearCaptureHistory().then(() => ({ history: [] })));
    return false;
  });

  raw.contextMenus.onClicked.addListener((info, tab) => {
    handleContextMenu(info, tab).catch((error) => showBadge('!', '#dc2626', error.message || 'Catch failed'));
  });
  raw.runtime.onInstalled.addListener((details) => {
    registerContextMenus().catch(() => {});
    if (details.reason === 'install') CatchExt.store.getDeviceToken().then((token) => { if (!token) openSetup(); });
  });
  if (raw.runtime.onStartup) raw.runtime.onStartup.addListener(() => {
    registerContextMenus().catch(() => {});
    CatchExt.store.getPairingSession().then((session) => { if (session) ext.alarms.create(PAIRING_ALARM, { periodInMinutes: 0.5 }); });
  });
  raw.alarms.onAlarm.addListener((alarm) => { if (alarm.name === PAIRING_ALARM) pollPairing().catch(() => {}); });
  registerContextMenus().catch(() => {});
})();
