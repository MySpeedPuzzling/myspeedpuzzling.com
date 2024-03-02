/**
 * Ajaxify MailChimp subscription form
*/

const subscriptionForm = (() => {

  const form = document.querySelectorAll('.subscription-form');

  if (form === null) return;

  for (let i = 0; i < form.length; i++) {

    let button = form[i].querySelector('button[type="submit"]'),
        buttonText = button.innerHTML,
        input = form[i].querySelector('.form-control'),
        antispam = form[i].querySelector('.subscription-form-antispam'),
        status = form[i].querySelector('.subscription-status');
    
    form[i].addEventListener('submit', function(e) {
      if (e) e.preventDefault();
      if (antispam.value !== '') return;
      register(this, button, input, buttonText, status);
    });
  }

  let register = (form, button, input, buttonText, status) => {
    button.innerHTML = 'Sending...';

    // Get url for MailChimp
    let url = form.action.replace('/post?', '/post-json?');

    // Add form data to object
    let data = '&' + input.name + '=' + encodeURIComponent(input.value);

    // Create and add post script to the DOM
    let script = document.createElement('script');
    script.src = url + '&c=callback' + data
    document.body.appendChild(script);
    
    // Callback function
    let callback = 'callback';
    window[callback] = (response) => {

      // Remove post script from the DOM
      delete window[callback];
      document.body.removeChild(script);

      // Change button text back to initial
      button.innerHTML = buttonText;

      // Display content and apply styling to response message conditionally
      if(response.result == 'success') {
        input.classList.remove('is-invalid');
        input.classList.add('is-valid');
        status.classList.remove('status-error');
        status.classList.add('status-success');
        status.innerHTML = response.msg;
        setTimeout(() => {
          input.classList.remove('is-valid');
          status.innerHTML = '';
          status.classList.remove('status-success');
        }, 6000)
      } else {
        input.classList.remove('is-valid');
        input.classList.add('is-invalid');
        status.classList.remove('status-success');
        status.classList.add('status-error');
        status.innerHTML = response.msg.substring(4);
        setTimeout(() => {
          input.classList.remove('is-invalid');
          status.innerHTML = '';
          status.classList.remove('status-error');
        }, 6000)
      }
    }
  }
})();

export default subscriptionForm;
