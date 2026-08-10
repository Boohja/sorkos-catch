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
  };
})(globalThis);
