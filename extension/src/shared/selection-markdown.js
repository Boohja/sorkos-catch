(function (root) {
  const CatchExt = root.CatchExt = root.CatchExt || {};

  function normalizeInline(value) {
    return value.replace(/[\t\f\v ]+/g, ' ').replace(/ *\n */g, '\n');
  }

  function destination(href) {
    try {
      const url = new URL(href, document.baseURI);
      return ['http:', 'https:', 'mailto:'].includes(url.protocol) ? url.href : '';
    } catch { return ''; }
  }

  function convert(node, depth = 0) {
    if (node.nodeType === Node.TEXT_NODE) return normalizeInline(node.nodeValue || '');
    if (node.nodeType !== Node.ELEMENT_NODE) return '';
    const tag = node.tagName.toLowerCase();
    if (['script', 'style', 'noscript', 'svg', 'canvas', 'form', 'button'].includes(tag)) return '';
    const content = Array.from(node.childNodes).map((child) => convert(child, depth)).join('');
    if (tag === 'br') return '\n';
    if (['strong', 'b'].includes(tag)) return content.trim() ? `**${content.trim()}**` : '';
    if (['em', 'i'].includes(tag)) return content.trim() ? `*${content.trim()}*` : '';
    if (tag === 'code' && node.parentElement?.tagName.toLowerCase() !== 'pre') return content.trim() ? `\`${content.trim().replace(/`/g, '\\`')}\`` : '';
    if (tag === 'pre') return content.trim() ? `\n\n\`\`\`\n${node.textContent.trim()}\n\`\`\`\n\n` : '';
    if (tag === 'a') {
      const href = destination(node.getAttribute('href') || '');
      const label = content.trim();
      return href && label ? `[${label.replace(/\]/g, '\\]')}](${href})` : label;
    }
    if (/^h[1-6]$/.test(tag)) return `\n\n${'#'.repeat(Number(tag[1]))} ${content.trim()}\n\n`;
    if (tag === 'blockquote') return `\n\n${content.trim().split('\n').map((line) => `> ${line}`).join('\n')}\n\n`;
    if (tag === 'li') {
      const ordered = node.parentElement?.tagName.toLowerCase() === 'ol';
      const siblings = Array.from(node.parentElement?.children || []);
      const marker = ordered ? `${siblings.indexOf(node) + 1}.` : '-';
      return `${'  '.repeat(depth)}${marker} ${content.trim()}\n`;
    }
    if (tag === 'ul' || tag === 'ol') return `\n${Array.from(node.children).map((child) => convert(child, depth)).join('')}\n`;
    if (['p', 'div', 'section', 'article', 'header', 'footer', 'main', 'aside', 'figure', 'figcaption', 'dt', 'dd', 'tr'].includes(tag)) return `\n\n${content.trim()}\n\n`;
    return content;
  }

  function selectionToMarkdown(selection = window.getSelection()) {
    if (!selection || selection.isCollapsed || selection.rangeCount === 0) return '';
    const fragment = selection.getRangeAt(0).cloneContents();
    const wrapper = document.createElement('div');
    wrapper.append(fragment);
    return convert(wrapper)
      .replace(/\u00a0/g, ' ')
      .replace(/[ \t]+\n/g, '\n')
      .replace(/\n{3,}/g, '\n\n')
      .trim();
  }

  CatchExt.selectionToMarkdown = selectionToMarkdown;
})(globalThis);
