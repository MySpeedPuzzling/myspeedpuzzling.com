/**
 * Form validation
*/

const formValidation = (() => {

  const selector = 'needs-validation';

  window.addEventListener('load', () => {
    // Fetch all the forms we want to apply custom Bootstrap validation styles to
    let forms = document.getElementsByClassName(selector);
    // Loop over them and prevent submission
    let validation = Array.prototype.filter.call(forms, (form) => {
      form.addEventListener('submit', (e) => {
        if (form.checkValidity() === false) {
          e.preventDefault();
          e.stopPropagation();
        }
        form.classList.add('was-validated');
      }, false);
    });
  }, false);
})();

export default formValidation;
