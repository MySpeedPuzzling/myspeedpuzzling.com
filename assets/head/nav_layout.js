/* NAV-13: <html data-nav-layout="docked|rail"> and data-nav-collapsed="<group ids>" from localStorage before first paint (CSS reserves the desktop column/rail from them); written by assets/navigation/nav_layout.js */
(function (w) {
  try {
    var d = w.document.documentElement;
    if (d.hasAttribute('data-native-app')) return;
    var s = w.localStorage;
    var layout = s.getItem('msp:nav-layout');
    if (layout === 'docked' || layout === 'rail') d.setAttribute('data-nav-layout', layout);
    var collapsed = s.getItem('msp:nav-collapsed');
    if (typeof collapsed === 'string' && /^[a-z_ ]{0,80}$/.test(collapsed) && /[a-z_]/.test(collapsed)) d.setAttribute('data-nav-collapsed', collapsed);
  } catch (e) {}
})(window);
