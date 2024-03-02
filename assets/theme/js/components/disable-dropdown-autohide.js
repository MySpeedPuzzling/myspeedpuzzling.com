/**
 * Disable dropdown autohide when select is clicked
*/

const disableDropdownAutohide = (() => {

  let elements = document.querySelectorAll('.disable-autohide .form-select');

  for (let i = 0; i < elements.length; i++) {
    elements[i].addEventListener('click', (e) => {
      e.stopPropagation();
    });
  }

})();

export default disableDropdownAutohide;
