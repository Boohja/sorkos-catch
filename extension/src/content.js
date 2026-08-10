(function () {
  const runtime = (globalThis.browser || globalThis.chrome).runtime;

  function showToast(message, tone) {
    document.querySelector('[data-catch-extension-toast]')?.remove();
    const toast = document.createElement('div');
    toast.dataset.catchExtensionToast = '';
    toast.setAttribute('role', 'status');
    toast.textContent = message;
    Object.assign(toast.style, {
      all: 'initial', position: 'fixed', insetInlineEnd: '18px', insetBlockEnd: '18px', zIndex: '2147483647',
      padding: '10px 14px', borderRadius: '9px', background: tone === 'error' ? '#991b1b' : '#18181b', color: '#fafafa',
      boxShadow: '0 8px 28px rgba(0,0,0,.22)', font: '650 13px/1.4 ui-sans-serif,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif',
    });
    document.documentElement.appendChild(toast);
    setTimeout(() => toast.remove(), 2600);
  }

  runtime.onMessage.addListener((message, _sender, sendResponse) => {
    if (message?.type === 'feedback.toast') {
      showToast(String(message.message || 'Catch saved'), message.tone);
      sendResponse({ ok: true });
      return true;
    }
    if (message?.type !== 'page.context') return undefined;
    const selection = window.getSelection();
    const selectedText = selection?.toString().replace(/\s+/g, ' ').trim() || '';
    let domain = '';
    try { domain = location.hostname.replace(/^www\./, ''); } catch {}
    sendResponse({
      title: document.title.trim(),
      url: location.href,
      domain,
      selectedText,
      selectedMarkdown: selectedText ? CatchExt.selectionToMarkdown(selection) : '',
    });
    return true;
  });
})();
