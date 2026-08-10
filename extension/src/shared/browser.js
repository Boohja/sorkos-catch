(function (root) {
  const CatchExt = root.CatchExt = root.CatchExt || {};
  if (CatchExt.browser) return;

  const raw = root.browser || root.chrome;
  const promiseApi = Boolean(root.browser);

  function call(namespace, method, ...args) {
    if (promiseApi) return Promise.resolve(namespace[method](...args));
    return new Promise((resolve, reject) => {
      namespace[method](...args, (result) => {
        const error = raw.runtime.lastError;
        if (error) reject(new Error(error.message));
        else resolve(result);
      });
    });
  }

  function createContextMenu(details) {
    if (promiseApi) return Promise.resolve(raw.contextMenus.create(details));
    return new Promise((resolve, reject) => {
      const id = raw.contextMenus.create(details, () => {
        const error = raw.runtime.lastError;
        if (error) reject(new Error(error.message));
        else resolve(id);
      });
    });
  }

  CatchExt.browser = {
    raw,
    storage: {
      get: (keys) => call(raw.storage.local, 'get', keys),
      set: (values) => call(raw.storage.local, 'set', values),
      remove: (keys) => call(raw.storage.local, 'remove', keys),
    },
    tabs: {
      query: (queryInfo) => call(raw.tabs, 'query', queryInfo),
      get: (tabId) => call(raw.tabs, 'get', tabId),
      sendMessage: (tabId, message) => call(raw.tabs, 'sendMessage', tabId, message),
      create: (properties) => call(raw.tabs, 'create', properties),
      captureVisibleTab: (windowId, options) => call(raw.tabs, 'captureVisibleTab', windowId, options),
    },
    runtime: {
      sendMessage: (message) => call(raw.runtime, 'sendMessage', message),
      openOptionsPage: () => call(raw.runtime, 'openOptionsPage'),
      getURL: (path) => raw.runtime.getURL(path),
    },
    action: {
      setBadgeText: (details) => call(raw.action, 'setBadgeText', details),
      setBadgeBackgroundColor: (details) => call(raw.action, 'setBadgeBackgroundColor', details),
      setTitle: (details) => call(raw.action, 'setTitle', details),
    },
    alarms: {
      create: (name, alarmInfo) => raw.alarms.create(name, alarmInfo),
      clear: (name) => call(raw.alarms, 'clear', name),
    },
    contextMenus: {
      removeAll: () => call(raw.contextMenus, 'removeAll'),
      create: createContextMenu,
    },
  };
})(globalThis);
