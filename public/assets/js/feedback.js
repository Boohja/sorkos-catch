(() => {
  const trigger = document.querySelector('#footer-feedback-trigger');
  if (!trigger || !window.PulseSignals) return;
  window.PulseSignals.init(trigger.dataset.pulseToken, {
    launcher: 'text',
    launcherText: 'Feedback',
    target: '#footer-feedback',
    trigger,
  });
})();
