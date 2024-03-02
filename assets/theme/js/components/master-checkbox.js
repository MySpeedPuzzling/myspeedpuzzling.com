/**
 * Master checkbox that checkes / unchecks all target checkboxes at once
*/

const masterCheckbox = (() => {

  const masterCheckbox = document.querySelectorAll('[data-master-checkbox-for]');
      
  if (masterCheckbox.length === 0) return;
  
  for (let i = 0; i < masterCheckbox.length; i++) {

    masterCheckbox[i].addEventListener('change', function() {
      let targetWrapper = document.querySelector(this.dataset.masterCheckboxFor),
          targetCheckboxes = targetWrapper.querySelectorAll('input[type="checkbox"]');
      if (this.checked) {
        for(let n = 0; n < targetCheckboxes.length; n++) {
          targetCheckboxes[n].checked = true;
          if (targetCheckboxes[n].dataset.checkboxToggleClass) {
            document.querySelector(targetCheckboxes[n].dataset.target).classList.add(targetCheckboxes[n].dataset.checkboxToggleClass);
          }
        }
      } else {
        for(let m = 0; m < targetCheckboxes.length; m++) {
          targetCheckboxes[m].checked = false;
          if (targetCheckboxes[m].dataset.checkboxToggleClass) {
            document.querySelector(targetCheckboxes[m].dataset.target).classList.remove(targetCheckboxes[m].dataset.checkboxToggleClass);
          }
        }
      }
    });
  }
})();

export default masterCheckbox;
