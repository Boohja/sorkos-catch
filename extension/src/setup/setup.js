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
  let timer = null;

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
