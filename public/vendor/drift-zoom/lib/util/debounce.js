"use strict";

Object.defineProperty(exports, "__esModule", {
  value: true
});
exports.default = debounce;

/* eslint-disable */
// http://underscorejs.org/docs/underscore.html#section-83
function debounce(func, wait, immediate) {
  var timeout;
  var args;
  var context;
  var timestamp;
  var result;

  var later = function later() {
    var last = Date.now() - timestamp;

    if (last < wait && last >= 0) {
      timeout = setTimeout(later, wait - last);
    } else {
      timeout = null;

      if (!immediate) {
        result = func.apply(context, args);

        if (!timeout) {
          context = args = null;
        }
      }
    }
  };

  return function () {
    context = this;
    args = arguments;
    timestamp = Date.now();
    var callNow = immediate && !timeout;

    if (!timeout) {
      timeout = setTimeout(later, wait);
    }

    if (callNow) {
      result = func.apply(context, args);
      context = args = null;
    }

    return result;
  };
}
//# sourceMappingURL=debounce.js.map