(async function () {
  const form = document.querySelector('[data-capture-form]');
  const text = document.querySelector('[data-capture-text]');
  const source = document.querySelector('[data-source]');
  const selectionNote = document.querySelector('[data-selection-note]');
  const screenshot = document.querySelector('[data-screenshot]');
  const submit = document.querySelector('[data-submit]');
  const status = document.querySelector('[data-status]');
  const setup = document.querySelector('[data-setup]');
  let tab = null;
  let context = { title: '', url: '', domain: '' };

  function setStatus(message, state = '') {
    status.textContent = message;
    status.dataset.state = state;
  }

  try {
    [tab] = await CatchExt.browser.tabs.query({ active: true, currentWindow: true });
    context = { title: tab?.title || '', url: tab?.url || '', domain: '' };
    try {
      context = { ...context, ...await CatchExt.browser.tabs.sendMessage(tab.id, { type: 'page.context' }) };
    } catch {}
    try { context.domain ||= new URL(context.url).hostname.replace(/^www\./, ''); } catch {}
    const selected = context.selectedMarkdown || context.selectedText || '';
    text.value = selected || context.title;
    source.textContent = context.domain || 'Current page';
    source.title = context.url;
    selectionNote.hidden = !selected;
    text.focus();
    text.setSelectionRange(text.value.length, text.value.length);
  } catch (error) {
    setStatus('This page cannot be read by the extension.', 'error');
  }

  setup.addEventListener('click', () => CatchExt.browser.runtime.openOptionsPage());

  form.addEventListener('submit', async (event) => {
    event.preventDefault();
    const value = text.value.trim();
    if (!value) { text.focus(); return; }
    submit.disabled = true;
    submit.textContent = 'Catching...';
    setStatus(screenshot.checked ? 'Capturing the visible tab...' : 'Sending to Catch...');
    try {
      const result = await CatchExt.browser.runtime.sendMessage({
        type: 'capture.submit',
        capture: {
          text: value,
          title: context.title,
          pageTitle: context.title,
          url: context.url,
          tabId: tab?.id,
          windowId: tab?.windowId,
          screenshotRequested: screenshot.checked,
          context: context.selectedText ? 'popup-selection' : 'popup-page',
        },
      });
      if (!result?.ok) throw new Error(result?.error || 'Catch could not save this capture.');
      if (result.setupRequired) {
        setStatus('Saved. Connect Catch to send it.', 'success');
        submit.textContent = 'Waiting for setup';
      } else {
        setStatus(`Saved as Catch #${result.catchNumber}.`, 'success');
        submit.textContent = 'Caught';
      }
      setTimeout(() => window.close(), 900);
    } catch (error) {
      setStatus(error.message, 'error');
      submit.disabled = false;
      submit.textContent = 'Try again';
    }
  });
})();
