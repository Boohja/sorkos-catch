(function (root) {
  const CatchExt = root.CatchExt = root.CatchExt || {};
  const { apiBase } = CatchExt.config;

  async function jsonRequest(path, options = {}) {
    const response = await fetch(`${apiBase}${path}`, {
      ...options,
      headers: { Accept: 'application/json', ...(options.headers || {}) },
    });
    let data = null;
    try { data = await response.json(); } catch {}
    if (!response.ok && response.status !== 202) {
      const error = new Error(data?.error?.message || `Catch returned HTTP ${response.status}.`);
      error.status = response.status;
      error.code = data?.error?.code || 'request_failed';
      throw error;
    }
    return { response, data };
  }

  async function startPairing({ deviceName, platform, codeChallenge }) {
    const { data } = await jsonRequest('/api/extension/pairing-requests', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ device_name: deviceName, platform, code_challenge: codeChallenge }),
    });
    return data;
  }

  async function exchangePairing({ requestId, codeVerifier }) {
    const { response, data } = await jsonRequest(`/api/extension/pairing-requests/${encodeURIComponent(requestId)}/exchange`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ code_verifier: codeVerifier }),
    });
    return response.status === 202 ? { status: 'pending' } : data;
  }

  function dataUrlToBlob(dataUrl) {
    const [header, encoded] = dataUrl.split(',', 2);
    const mime = header.match(/^data:([^;]+)/)?.[1] || 'image/png';
    const bytes = atob(encoded);
    const output = new Uint8Array(bytes.length);
    for (let index = 0; index < bytes.length; index += 1) output[index] = bytes.charCodeAt(index);
    return new Blob([output], { type: mime });
  }

  function captureType(capture, hasScreenshot) {
    if (hasScreenshot) return 'mixed';
    if (capture.text && capture.url) return 'mixed';
    if (capture.url) return 'url';
    return 'text';
  }

  async function createCapture(token, capture, screenshotDataUrl = null) {
    const body = new FormData();
    body.append('client_capture_id', capture.id);
    body.append('type', captureType(capture, Boolean(screenshotDataUrl)));
    if (capture.title) body.append('title', capture.title);
    if (capture.text) body.append('text', capture.text);
    if (capture.url) body.append('url', capture.url);
    body.append('source', CatchExt.config.source);
    body.append('metadata', JSON.stringify(capture.metadata || {}));
    if (screenshotDataUrl) body.append('attachments[]', dataUrlToBlob(screenshotDataUrl), 'catch-viewport.png');
    const { data } = await jsonRequest('/api/v1/captures', {
      method: 'POST',
      headers: { Authorization: `Bearer ${token}`, 'Idempotency-Key': capture.id },
      body,
    });
    return data;
  }

  async function disconnect(token) {
    const { data } = await jsonRequest('/api/extension/disconnect', {
      method: 'POST',
      headers: { Authorization: `Bearer ${token}` },
    });
    return data;
  }

  CatchExt.api = { startPairing, exchangePairing, createCapture, disconnect };
})(globalThis);
