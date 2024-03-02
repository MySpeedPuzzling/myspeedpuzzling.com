/**
 * Anchor smooth scrolling
 * @requires https://github.com/cferdinandi/smooth-scroll/
*/

const smoothScroll = (() => {

  let selector = '[data-scroll]',
  fixedHeader = '[data-scroll-header]',
  scroll = new SmoothScroll(selector, {
    speed: 800,
    speedAsDuration: true,
    offset: 40,
    header: fixedHeader,
    updateURL: false
  });

})();

export default smoothScroll;
