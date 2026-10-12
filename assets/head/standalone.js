/* NAV-03: <html class="is-standalone"> = the installed web app (README §4.1); read only via assets/platform.js isStandalone() */
(function (w) {
  try {
    var d = w.document.documentElement;
    if (d.hasAttribute('data-native-app')) return;
    var on = (w.matchMedia && w.matchMedia('(display-mode: standalone)').matches) || w.navigator.standalone === true;
    if (!on && w.navigator.webdriver === true) {
      try { on = w.localStorage.getItem('msp:test-standalone') === '1'; } catch (e) {}
    }
    if (on) d.classList.add('is-standalone');
  } catch (e) {}
})(window);
