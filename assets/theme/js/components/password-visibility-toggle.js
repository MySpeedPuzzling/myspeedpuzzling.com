/**
 * Toggling password visibility in password input
*/

const passwordVisibilityToggle = (() => {

  let elements = document.querySelectorAll('.password-toggle');

  for (let i = 0; i < elements.length; i++) {
    let passInput = elements[i].querySelector('.form-control'),
    passToggle = elements[i].querySelector('.password-toggle-btn');

    passToggle.addEventListener('click', (e) => {
      
      if (e.target.type !== 'checkbox') return;
      if (e.target.checked) {
        passInput.type = 'text';
      } else {
        passInput.type = 'password';
      }

    }, false);
  }
})();

export default passwordVisibilityToggle;
