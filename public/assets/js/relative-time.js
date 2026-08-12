function relativeTime(date, now = Date.now()) {
  const seconds = Math.max(0, Math.floor((now - date.getTime()) / 1000));
  if (seconds < 60) return '<1m';
  if (seconds < 3_600) return `${Math.floor(seconds / 60)}m`;
  if (seconds < 86_400) return `${Math.floor(seconds / 3_600)}h`;
  if (seconds < 2_592_000) return `${Math.floor(seconds / 86_400)}d`;
  if (seconds < 31_536_000) return `${Math.floor(seconds / 2_592_000)}mo`;
  return `${Math.floor(seconds / 31_536_000)}y`;
}

export function initRelativeTime() {
  const elements = Array.from(document.querySelectorAll('[data-relative-time]'));
  if (!elements.length) return;

  const formatter = new Intl.DateTimeFormat(undefined, {
    dateStyle: 'medium',
    timeStyle: 'short',
  });
  const update = () => {
    const now = Date.now();
    elements.forEach((element) => {
      const date = new Date(element.dateTime);
      if (Number.isNaN(date.getTime())) return;
      element.textContent = relativeTime(date, now);
      element.title = formatter.format(date);
    });
  };

  update();
  window.setInterval(update, 60_000);
}
