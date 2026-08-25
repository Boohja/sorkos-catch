export function initMobileNavigation() {
  const navigation = document.querySelector('[data-mobile-tab-bar]');
  if (!navigation) return;

  const mobile = window.matchMedia('(max-width: 760px)');
  const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)');
  let previousY = window.scrollY;
  let ticking = false;

  const show = () => {
    document.body.removeAttribute('data-mobile-nav-hidden');
  };

  const sync = () => {
    ticking = false;
    if (!mobile.matches || reducedMotion.matches) {
      show();
      previousY = window.scrollY;
      return;
    }

    const currentY = Math.max(0, window.scrollY);
    const delta = currentY - previousY;
    const canScroll = document.documentElement.scrollHeight > window.innerHeight + 24;

    if (!canScroll || currentY < 24 || delta < -5) show();
    else if (delta > 7 && currentY > 72) document.body.dataset.mobileNavHidden = 'true';

    previousY = currentY;
  };

  const requestSync = () => {
    if (ticking) return;
    ticking = true;
    window.requestAnimationFrame(sync);
  };

  window.addEventListener('scroll', requestSync, { passive: true });
  window.addEventListener('resize', requestSync, { passive: true });
  navigation.addEventListener('focusin', show);
  mobile.addEventListener?.('change', requestSync);
  reducedMotion.addEventListener?.('change', requestSync);
  sync();
}
