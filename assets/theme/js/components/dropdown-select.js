/**
 * Force dropdown to work as select box
*/

const dropdownSelect = (() => {

  let dropdownSelectList = document.querySelectorAll('[data-bs-toggle="select"]');

  for (let i = 0; i < dropdownSelectList.length; i++) {
    let dropdownItems = dropdownSelectList[i].querySelectorAll('.dropdown-item'),
        dropdownToggleLabel = dropdownSelectList[i].querySelector('.dropdown-toggle-label'),
        hiddenInput = dropdownSelectList[i].querySelector('input[type="hidden"]');
    
    for (let n = 0; n < dropdownItems.length; n++) {
      dropdownItems[n].addEventListener('click', function (e) {
        e.preventDefault();
        let dropdownLabel = this.querySelector('.dropdown-item-label').innerText;
        dropdownToggleLabel.innerText = dropdownLabel;
        if (hiddenInput !== null) {
          hiddenInput.value = dropdownLabel;
        }
      });
    }
  }
})();

export default dropdownSelect;
