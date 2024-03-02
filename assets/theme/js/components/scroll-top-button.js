/**
 * Animate scroll to top button in/off view
*/

const scrollTopButton = (() => {

  let element = document.querySelector('.btn-scroll-top'),
      scrollOffset = 600;
  
  if (element == null) return;

  let offsetFromTop = parseInt(scrollOffset, 10);
  
  window.addEventListener('scroll', (e) => {
    if (e.currentTarget.pageYOffset > offsetFromTop) {
      element.classList.add('show');
    } else {
      element.classList.remove('show');
    }
  });
})();

export default scrollTopButton;
