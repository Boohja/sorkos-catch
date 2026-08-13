const key = 'catch-theme';

function applyTheme(value) {
  if (value === 'system') delete document.documentElement.dataset.theme;
  else document.documentElement.dataset.theme = value;
}

export function initTheme() {
  const selects = Array.from(document.querySelectorAll('[data-theme-select]'));
  const saved = localStorage.getItem(key) || 'system';
  applyTheme(saved);

  selects.forEach((select) => {
    select.value = saved;
    select.addEventListener('change', () => {
      const value = select.value;
      localStorage.setItem(key, value);
      applyTheme(value);
      document.querySelector('[data-preference-status]')?.replaceChildren('Saved on this device.');
    });
  });
}
