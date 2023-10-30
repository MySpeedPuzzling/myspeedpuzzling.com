function _typeof(obj) { "@babel/helpers - typeof"; return _typeof = "function" == typeof Symbol && "symbol" == typeof Symbol.iterator ? function (obj) { return typeof obj; } : function (obj) { return obj && "function" == typeof Symbol && obj.constructor === Symbol && obj !== Symbol.prototype ? "symbol" : typeof obj; }, _typeof(obj); }

// This is not really a perfect check, but works fine.
// From http://stackoverflow.com/questions/384286
var HAS_DOM_2 = (typeof HTMLElement === "undefined" ? "undefined" : _typeof(HTMLElement)) === "object";
export function isDOMElement(obj) {
  return HAS_DOM_2 ? obj instanceof HTMLElement : obj && _typeof(obj) === "object" && obj !== null && obj.nodeType === 1 && typeof obj.nodeName === "string";
}
export function addClasses(el, classNames) {
  classNames.forEach(function (className) {
    el.classList.add(className);
  });
}
export function removeClasses(el, classNames) {
  classNames.forEach(function (className) {
    el.classList.remove(className);
  });
}
//# sourceMappingURL=dom.js.map