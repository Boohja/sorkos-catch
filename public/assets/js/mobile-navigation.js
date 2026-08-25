export function initMobileNavigation() {
  const navigation = document.querySelector('[data-mobile-tab-bar]');
  if (!navigation) return;

  const mobile = window.matchMedia('(max-width: 760px)');
  const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)');
  const HIDE_DISTANCE = 24;
  const SHOW_DISTANCE = 12;
  let previousY = Math.max(0, window.scrollY);
  let downwardTravel = 0;
  let upwardTravel = 0;
  let ticking = false;

  const show = () => {
    document.body.removeAttribute('data-mobile-nav-hidden');
  };

  const hide = () => {
    document.body.dataset.mobileNavHidden = 'true';
  };

  const resetTravel = () => {
    downwardTravel = 0;
    upwardTravel = 0;
  };

  const sync = () => {
    ticking = false;
    if (!mobile.matches) {
      show();
      previousY = Math.max(0, window.scrollY);
      resetTravel();
      return;
    }

    const currentY = Math.max(0, window.scrollY);
    const delta = currentY - previousY;
    const canScroll = document.documentElement.scrollHeight > window.innerHeight + 24;

    if (delta > 0) {
      downwardTravel += delta;
      upwardTravel = 0;
    } else if (delta < 0) {
      upwardTravel -= delta;
      downwardTravel = 0;
    }

    if (!canScroll || currentY < 24) {
      show();
      resetTravel();
    } else if (upwardTravel >= SHOW_DISTANCE) {
      show();
      resetTravel();
    } else if (downwardTravel >= HIDE_DISTANCE && currentY > 72) {
      hide();
      resetTravel();
    }

    previousY = currentY;
  };

  const requestSync = () => {
    if (ticking) return;
    ticking = true;
    window.requestAnimationFrame(sync);
  };

  window.addEventListener('scroll', requestSync, { passive: true });
  window.addEventListener('resize', requestSync, { passive: true });
  navigation.addEventListener('focusin', () => {
    show();
    resetTravel();
  });
  mobile.addEventListener?.('change', requestSync);
  reducedMotion.addEventListener?.('change', requestSync);
  sync();
}
