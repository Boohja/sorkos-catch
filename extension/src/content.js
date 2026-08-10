(function () {
  const runtime = (globalThis.browser || globalThis.chrome).runtime;
  let contextMenuTarget = {};

  function compactText(value) {
    return String(value || '').replace(/\s+/g, ' ').trim();
  }
  function linkText(link) {
    return compactText(link?.innerText || link?.textContent || link?.getAttribute('aria-label') || link?.title || link?.querySelector('img')?.alt);
  }
  function normalizedUrl(value) { try { return new URL(value, location.href).href; } catch { return String(value || ''); } }

  document.addEventListener('contextmenu', (event) => {
    const element = event.target instanceof Element ? event.target : null;
    const link = element?.closest('a[href]');
    const image = element?.closest('img');
    contextMenuTarget = {
      linkUrl: link?.href || '',
      linkText: linkText(link),
      imageUrl: image?.currentSrc || image?.src || '',
      imageAlt: compactText(image?.alt || image?.getAttribute('aria-label') || image?.title),
    };
  }, true);

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
    if (message?.type === 'page.context-menu') {
      if (message.linkUrl && (!contextMenuTarget.linkText || normalizedUrl(contextMenuTarget.linkUrl) !== normalizedUrl(message.linkUrl))) {
        const requested = normalizedUrl(message.linkUrl);
        const matching = Array.from(document.querySelectorAll('a[href]')).find(candidate => normalizedUrl(candidate.href) === requested);
        if (matching) contextMenuTarget = { ...contextMenuTarget, linkUrl: matching.href, linkText: linkText(matching) };
      }
      sendResponse(contextMenuTarget);
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
