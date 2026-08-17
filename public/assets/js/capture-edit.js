function appendInlineMarkup(container, text) {
  const pattern = /\[([^\]]+)\]\((https?:\/\/[^\s)]+)\)|\*\*([^*]+)\*\*|`([^`]+)`|\*([^*\n]+)\*/g;
  let offset = 0;

  for (const match of text.matchAll(pattern)) {
    container.append(document.createTextNode(text.slice(offset, match.index)));
    let element;
    if (match[1] && match[2]) {
      element = document.createElement('a');
      element.href = match[2];
      element.target = '_blank';
      element.rel = 'noopener noreferrer';
      element.textContent = match[1];
    } else if (match[3]) {
      element = document.createElement('strong');
      element.textContent = match[3];
    } else if (match[4]) {
      element = document.createElement('code');
      element.textContent = match[4];
    } else {
      element = document.createElement('em');
      element.textContent = match[5];
    }
    container.append(element);
    offset = match.index + match[0].length;
  }

  container.append(document.createTextNode(text.slice(offset)));
}

function renderMarkup(element, raw) {
  element.replaceChildren();
  const lines = raw.split('\n');

  for (let index = 0; index < lines.length;) {
    if (lines[index].trim() === '') {
      index += 1;
      continue;
    }

    if (lines[index].trim() === '```') {
      const pre = document.createElement('pre');
      const code = document.createElement('code');
      index += 1;
      const codeLines = [];
      while (index < lines.length && lines[index].trim() !== '```') {
        codeLines.push(lines[index]);
        index += 1;
      }
      if (index < lines.length) index += 1;
      code.textContent = codeLines.join('\n');
      pre.append(code);
      element.append(pre);
      continue;
    }

    const heading = lines[index].match(/^(#{1,6})\s+(.+)$/);
    if (heading) {
      const block = document.createElement(`h${heading[1].length}`);
      appendInlineMarkup(block, heading[2]);
      element.append(block);
      index += 1;
      continue;
    }

    const unordered = /^[-*]\s+/.test(lines[index]);
    const ordered = /^\d+\.\s+/.test(lines[index]);
    if (unordered || ordered) {
      const pattern = ordered ? /^\d+\.\s+/ : /^[-*]\s+/;
      const list = ordered
        ? document.createElement('ol')
        : document.createElement('ul');
      while (index < lines.length && pattern.test(lines[index])) {
        const item = document.createElement('li');
        appendInlineMarkup(item, lines[index].replace(pattern, ''));
        list.append(item);
        index += 1;
      }
      element.append(list);
      continue;
    }

    const blockquote = lines[index].startsWith('> ');
    const block = document.createElement(blockquote ? 'blockquote' : 'p');
    let firstLine = true;
    while (
      index < lines.length
      && lines[index].trim() !== ''
      && !/^[-*]\s+/.test(lines[index])
      && !/^\d+\.\s+/.test(lines[index])
      && !/^#{1,6}\s+/.test(lines[index])
      && lines[index].trim() !== '```'
      && lines[index].startsWith('> ') === blockquote
    ) {
      if (!firstLine) block.append(document.createElement('br'));
      appendInlineMarkup(block, blockquote ? lines[index].slice(2) : lines[index]);
      firstLine = false;
      index += 1;
    }
    element.append(block);
  }
}

export function initCaptureEditing() {
  const root = document.querySelector('[data-capture-detail]');
  if (!root) return;

  const csrf = root.dataset.csrf || '';
  const captureId = root.dataset.captureId;
  const status = root.querySelector('[data-edit-status]');
  const fields = Array.from(root.querySelectorAll(
    '[data-capture-field][contenteditable="true"]',
  ));
  const announce = (message, error = false) => {
    if (!status) return;
    status.textContent = message;
    status.classList.toggle('is-error', error);
  };
  const valueOf = (element) => element.innerText.replace(/\r/g, '').trim();
  const initialValueOf = (element) => {
    const clone = element.cloneNode(true);
    clone.querySelectorAll('br').forEach((breakElement) => {
      breakElement.replaceWith('\n');
    });
    return clone.textContent.replace(/\r/g, '').trim();
  };

  const setValue = (element, value) => {
    element.dataset.raw = value;
    element.dataset.value = value;
    if (element.dataset.markup !== undefined && document.activeElement !== element) {
      renderMarkup(element, value);
    } else {
      element.textContent = value;
    }
    element.classList.toggle('is-empty', value === '');

    if (element.dataset.captureField === 'title') {
      const separator = root.querySelector('[data-title-separator]');
      if (separator) separator.hidden = value === '';
      document.title = `${value || 'Capture'} | Catch`;
    }
    if (element.dataset.captureField === 'url') {
      const link = root.querySelector('[data-open-capture-url]');
      if (link) link.href = value;
      const fallback = root.querySelector('[data-url-fallback]');
      if (fallback && !root.dataset.hasTitle) fallback.textContent = value || 'Add URL';
    }
  };

  const save = async (element) => {
    if (element.dataset.saving === 'true') return;
    const before = element.dataset.value || '';
    const value = valueOf(element);
    if (value === before) {
      setValue(element, before);
      return;
    }

    element.dataset.saving = 'true';
    element.setAttribute('aria-busy', 'true');
    element.setAttribute('contenteditable', 'false');
    announce('Saving…');
    try {
      const data = new FormData();
      data.set('_csrf', csrf);
      data.set('field', element.dataset.captureField);
      data.set('value', value);
      const response = await fetch(`/captures/${encodeURIComponent(captureId)}`, {
        method: 'POST',
        headers: { Accept: 'application/json' },
        body: data,
      });
      const json = await response.json().catch(() => ({}));
      if (!response.ok) {
        throw new Error(json.error || 'The change could not be saved.');
      }

      setValue(element, String(json.value ?? value));
      announce(json.reload ? 'Preview updated.' : 'Saved.');
      if (json.reload) window.location.reload();
    } catch (error) {
      setValue(element, before);
      announce(error.message, true);
    } finally {
      delete element.dataset.saving;
      element.removeAttribute('aria-busy');
      element.setAttribute('contenteditable', 'true');
    }
  };

  fields.forEach((element) => {
    const initialValue = initialValueOf(element);
    setValue(element, initialValue);
    element.addEventListener('focus', () => {
      if (element.dataset.markup !== undefined) {
        element.textContent = element.dataset.raw || '';
      }
      element.dataset.value = element.dataset.raw ?? valueOf(element);
    });
    element.addEventListener('mousedown', (event) => {
      const link = event.target.closest('a');
      if (!link || element.dataset.markup === undefined) return;

      event.preventDefault();
      window.open(link.href, '_blank', 'noopener,noreferrer');
    });
    element.addEventListener('blur', () => save(element));
    if (element.dataset.singleLine !== undefined) {
      element.addEventListener('keydown', (event) => {
        if (event.key === 'Enter') {
          event.preventDefault();
          element.blur();
        }
      });
    }
    element.addEventListener('paste', (event) => {
      if (element.dataset.singleLine === undefined) return;
      event.preventDefault();
      const text = (event.clipboardData?.getData('text/plain') || '')
        .replace(/\s+/g, ' ')
        .trim();
      document.execCommand('insertText', false, text);
    });
  });
}
