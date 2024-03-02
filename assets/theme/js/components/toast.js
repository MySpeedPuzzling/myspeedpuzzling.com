/**
 * Toast
 * @requires https://getbootstrap.com
*/

const toast = (() => {

  let toastElList = [].slice.call(document.querySelectorAll('.toast'));

  let toastList = toastElList.map((toastEl) => new bootstrap.Toast(toastEl));

})();

export default toast;
