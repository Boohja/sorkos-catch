(function () {
  const disconnected = document.querySelector('[data-disconnected]');
  const pending = document.querySelector('[data-pending]');
  const connected = document.querySelector('[data-connected]');
  const connect = document.querySelector('[data-connect]');
  const reopen = document.querySelector('[data-reopen]');
  const disconnect = document.querySelector('[data-disconnect]');
  const status = document.querySelector('[data-status]');
  const pendingStatus = document.querySelector('[data-pending-status]');
  const deviceName = document.querySelector('[data-device-name]');
  const errorState = document.querySelector('[data-error]');
  const errorTitle = document.querySelector('[data-error-title]');
  const errorMessage = document.querySelector('[data-error-message]');
  const errorDetails = document.querySelector('[data-error-details]');
  const errorTechnical = document.querySelector('[data-error-technical]');
  const historyList = document.querySelector('[data-history-list]');
  const historyEmpty = document.querySelector('[data-history-empty]');
  const clearHistory = document.querySelector('[data-clear-history]');
  let timer = null;

  function eventLabel(event) {
    if (event.status === 'saved') return `Saved as #${event.catchNumber}`;
    if (event.status === 'failed') return event.httpStatus ? `Failed · HTTP ${event.httpStatus}` : 'Failed';
    if (event.status === 'queued') return 'Waiting for setup';
    return 'Sending';
  }

  function renderHistory(history) {
    historyList.replaceChildren();
    historyEmpty.hidden = history.length > 0;
    clearHistory.hidden = history.length === 0;
    history.forEach((event) => {
      const item = document.createElement('li');
      item.dataset.state = event.status;
      const top = document.createElement('div');
      const state = document.createElement('strong');
      state.textContent = eventLabel(event);
      const time = document.createElement('time');
      const date = new Date(event.updatedAt);
      time.dateTime = event.updatedAt;
      time.textContent = Number.isNaN(date.getTime()) ? '' : new Intl.DateTimeFormat(undefined, { dateStyle: 'short', timeStyle: 'short' }).format(date);
      top.append(state, time);
      const title = document.createElement(event.url ? 'a' : 'p');
      title.textContent = event.title || event.url || 'Untitled capture';
      if (event.url) {
        title.href = event.url;
        title.target = '_blank';
        title.rel = 'noopener noreferrer';
        title.title = event.url;
      }
      item.append(top, title);
      if (event.message) {
        const message = document.createElement('p');
        message.className = 'activity-message';
        message.textContent = event.message;
        item.append(message);
      }
      historyList.append(item);
    });
  }

  async function refreshHistory() {
    try {
      const result = await CatchExt.browser.runtime.sendMessage({ type: 'history.get' });
      if (result?.ok) renderHistory(result.history || []);
    } catch {}
  }

  function showOnly(element) {
    disconnected.hidden = element !== disconnected;
    pending.hidden = element !== pending;
    connected.hidden = element !== connected;
  }

  function stopPolling() {
    if (timer) clearInterval(timer);
    timer = null;
  }

  function showConnection(connection) {
    stopPolling();
    showOnly(connected);
    deviceName.textContent = connection?.deviceName || 'Browser extension';
  }

  function showDisconnected() {
    stopPolling();
    showOnly(disconnected);
  }

  function showPending(message = 'Waiting for approval...') {
    showOnly(pending);
    pendingStatus.textContent = message;
    if (!timer) timer = setInterval(checkPairing, 2000);
  }

  function clearError() {
    errorState.hidden = true;
    errorDetails.hidden = true;
    status.textContent = '';
  }

  function showError(result) {
    showDisconnected();
    errorState.hidden = false;
    connect.disabled = false;
    connect.textContent = 'Try again';
    const serverUnavailable = result?.status >= 500 || result?.code === 'pairing_unavailable' || result?.code === 'pairing_failed';
    const networkUnavailable = !result?.status && /fetch|network|reach/i.test(result?.error || '');

    if (serverUnavailable) {
      errorTitle.textContent = 'Catch needs a server update';
      errorMessage.textContent = 'Browser pairing is not available on this Catch installation yet.';
      errorDetails.hidden = false;
      errorTechnical.textContent = 'Apply database migration 003_extension_pairing.sql, deploy the matching backend, then try again.';
    } else if (networkUnavailable) {
      errorTitle.textContent = 'Catch is out of reach';
      errorMessage.textContent = 'Check your connection and try again.';
    } else {
      errorTitle.textContent = 'The connection could not start';
      errorMessage.textContent = result?.error || 'Reload the extension and try again.';
    }
  }

  async function checkPairing() {
    try {
      const result = await CatchExt.browser.runtime.sendMessage({ type: 'pair.status' });
      if (!result?.ok) { showError(result); return; }
      if (result.status === 'connected') showConnection(result.connection);
      else if (result.status === 'expired') {
        showDisconnected();
        errorState.hidden = false;
        errorTitle.textContent = 'The approval window expired';
        errorMessage.textContent = 'Start again to create a fresh connection request.';
        connect.textContent = 'Start again';
      } else if (result.status === 'pending') showPending();
    } catch (error) { showError({ error: error.message }); }
  }

  async function beginPairing() {
    clearError();
    connect.disabled = true;
    connect.textContent = 'Opening Catch...';
    status.textContent = 'Creating a secure connection request...';
    try {
      const result = await CatchExt.browser.runtime.sendMessage({ type: 'pair.start' });
      if (!result?.ok) { showError(result); return; }
      if (result.status === 'connected') { showConnection(result.connection); return; }
      showPending('Catch opened in a new tab. Waiting for approval...');
      await checkPairing();
    } catch (error) { showError({ error: error.message }); }
  }

  connect.addEventListener('click', beginPairing);
  reopen.addEventListener('click', beginPairing);

  disconnect.addEventListener('click', async () => {
    disconnect.disabled = true;
    disconnect.textContent = 'Disconnecting...';
    try {
      const result = await CatchExt.browser.runtime.sendMessage({ type: 'connection.disconnect' });
      if (!result?.ok) throw new Error(result?.error || 'Could not disconnect this browser.');
      showDisconnected();
      clearError();
      connect.disabled = false;
      connect.textContent = 'Continue to Catch';
    } catch (error) {
      disconnect.disabled = false;
      disconnect.textContent = 'Try again';
      showOnly(connected);
    }
  });

  clearHistory.addEventListener('click', async () => {
    clearHistory.disabled = true;
    try {
      const result = await CatchExt.browser.runtime.sendMessage({ type: 'history.clear' });
      if (result?.ok) renderHistory([]);
    } finally { clearHistory.disabled = false; }
  });

  window.addEventListener('focus', refreshHistory);
  document.addEventListener('visibilitychange', () => { if (!document.hidden) refreshHistory(); });
  refreshHistory();

  CatchExt.browser.runtime.sendMessage({ type: 'connection.get' }).then((result) => {
    if (result?.connection) showConnection(result.connection);
    else {
      showDisconnected();
      if (result?.revoked) {
        errorState.hidden = false;
        errorTitle.textContent = 'This browser was disconnected';
        errorMessage.textContent = 'Its access was removed in Catch. Connect again to resume capturing.';
        connect.textContent = 'Connect again';
      } else checkPairing();
    }
  }).catch((error) => showError({ error: error.message }));
})();
