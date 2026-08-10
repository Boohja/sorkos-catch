(function (root) {
  const CatchExt = root.CatchExt = root.CatchExt || {};
  const { storage } = CatchExt.browser;

  async function getDeviceToken() {
    const { connection } = await storage.get('connection');
    const token = connection && connection.deviceToken;
    return typeof token === 'string' && token.startsWith('catch_device_') ? token : null;
  }

  async function getConnection() {
    const { connection } = await storage.get('connection');
    return connection || null;
  }

  async function saveConnection(connection) {
    await storage.set({ connection: { ...connection, connectedAt: new Date().toISOString() } });
  }

  async function clearConnection() {
    await storage.remove('connection');
  }

  async function getPairingSession() {
    const { pairingSession } = await storage.get('pairingSession');
    return pairingSession || null;
  }

  async function savePairingSession(session) {
    await storage.set({ pairingSession: session });
  }

  async function clearPairingSession() {
    await storage.remove('pairingSession');
  }

  async function addPendingCapture(capture) {
    const { pendingCaptures = [] } = await storage.get('pendingCaptures');
    const queue = [...pendingCaptures.filter((item) => item.id !== capture.id), capture]
      .slice(-CatchExt.config.pendingLimit);
    await storage.set({ pendingCaptures: queue });
    return queue.length;
  }

  async function getPendingCaptures() {
    const { pendingCaptures = [] } = await storage.get('pendingCaptures');
    return Array.isArray(pendingCaptures) ? pendingCaptures : [];
  }

  async function removePendingCapture(id) {
    const queue = await getPendingCaptures();
    await storage.set({ pendingCaptures: queue.filter((item) => item.id !== id) });
  }

  async function recordCaptureEvent(event) {
    const { captureHistory = [] } = await storage.get('captureHistory');
    const history = Array.isArray(captureHistory) ? captureHistory : [];
    const next = [{ ...event, updatedAt: new Date().toISOString() }, ...history.filter((item) => item.captureId !== event.captureId)].slice(0, 20);
    await storage.set({ captureHistory: next });
    return next;
  }

  async function getCaptureHistory() {
    const { captureHistory = [] } = await storage.get('captureHistory');
    return Array.isArray(captureHistory) ? captureHistory.slice(0, 20) : [];
  }

  async function clearCaptureHistory() {
    await storage.remove('captureHistory');
  }

  CatchExt.store = {
    getDeviceToken,
    getConnection,
    saveConnection,
    clearConnection,
    getPairingSession,
    savePairingSession,
    clearPairingSession,
    addPendingCapture,
    getPendingCaptures,
    removePendingCapture,
    recordCaptureEvent,
    getCaptureHistory,
    clearCaptureHistory,
  };
})(globalThis);
