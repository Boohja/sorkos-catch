export function initUserMenu() {
  const root = document.querySelector('[data-user-menu]');
  if (!root) return;

  const trigger = root.querySelector('[data-user-menu-trigger]');
  const panel = root.querySelector('[data-user-menu-panel]');
  if (!trigger || !panel) return;

  const close = ({ focus = false } = {}) => {
    panel.hidden = true;
    trigger.setAttribute('aria-expanded', 'false');
    if (focus) trigger.focus();
  };
  const open = () => {
    panel.hidden = false;
    trigger.setAttribute('aria-expanded', 'true');
  };

  trigger.addEventListener('click', () => {
    if (panel.hidden) open();
    else close();
  });
  document.addEventListener('click', (event) => {
    if (!root.contains(event.target)) close();
  });
  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape' && !panel.hidden) close({ focus: true });
  });
}
