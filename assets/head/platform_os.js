/* GEST-09: <html data-os> = the device's OS, not the app; read only via assets/platform.js os() */
(function (w) {
  var IOS_MARKERS = ['Hotwire Native iOS', 'Turbo Native iOS', 'MySpeedPuzzling iOS'];
  var ANDROID_MARKERS = ['Hotwire Native Android', 'Turbo Native Android', 'MySpeedPuzzling Android'];
  function has(ua, markers) {
    for (var i = 0; i < markers.length; i++) if (ua.indexOf(markers[i]) !== -1) return true;
    return false;
  }
  var os = 'other';
  try {
    var n = w.navigator || {}, ua = String(n.userAgent || ''), p = n.userAgentData ? n.userAgentData.platform : '';
    if (has(ua, IOS_MARKERS)) os = 'ios';
    else if (has(ua, ANDROID_MARKERS)) os = 'android';
    else if (typeof p === 'string' && p !== '') os = p === 'Android' ? 'android' : (p === 'iOS' ? 'ios' : 'other');
    else if (ua.indexOf('Android') !== -1) os = 'android';
    else if (/iPhone|iPad|iPod/.test(ua) || (ua.indexOf('Macintosh') !== -1 && n.maxTouchPoints > 1)) os = 'ios';
    else if (w.CSS && w.CSS.supports && w.CSS.supports('-webkit-touch-callout', 'none')) os = 'ios';
  } catch (e) {
    os = 'other';
  }
  try { w.document.documentElement.setAttribute('data-os', os); } catch (e) {}
})(window);
