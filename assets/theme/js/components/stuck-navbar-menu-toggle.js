/**
 * Menu toggle for 3 level navbar stuck state
*/

const stuckNavbarMenuToggle = (() => {

  let toggler = document.querySelector('.navbar-stuck-toggler'),
  stuckMenu = document.querySelector('.navbar-stuck-menu');

  if (toggler == null) return;

  toggler.addEventListener('click', function (e) {
    stuckMenu.classList.toggle('show');
    e.preventDefault();
  });

})();

export default stuckNavbarMenuToggle;
