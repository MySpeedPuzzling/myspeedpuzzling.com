/**
 * Filter list of items by typing in the search field
*/

const filterList = (() => {

  let filterListWidget = document.querySelectorAll('.widget-filter')

  for (let i = 0; i < filterListWidget.length; i++) {
    
    let filterInput = filterListWidget[i].querySelector('.widget-filter-search'),
        filterList = filterListWidget[i].querySelector('.widget-filter-list'),
        filterItems = filterList.querySelectorAll('.widget-filter-item');

    if (! filterInput) {
      continue;
    }

    filterInput.addEventListener('keyup', filterListFunc);
    
    function filterListFunc() {
      
      let filterValue = filterInput.value.toLowerCase();
      
      for (let i = 0; i < filterItems.length; i++) {

        let filterText = filterItems[i].querySelector('.widget-filter-item-text').innerHTML;

        if(filterText.toLowerCase().indexOf(filterValue) > -1) {
          filterItems[i].classList.remove('d-none');
        } else {
          filterItems[i].classList.add('d-none');
        }

      }
      
    }
  }
})();

export default filterList;
