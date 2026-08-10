(function (root) {
  const CatchExt = root.CatchExt = root.CatchExt || {};
  CatchExt.config = Object.freeze({
    apiBase: 'https://catch.sorkos.net',
    source: 'browser-extension',
    pendingLimit: 20,
  });
})(globalThis);
