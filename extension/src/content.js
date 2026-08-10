(function () {
  const runtime = (globalThis.browser || globalThis.chrome).runtime;

  runtime.onMessage.addListener((message, _sender, sendResponse) => {
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
