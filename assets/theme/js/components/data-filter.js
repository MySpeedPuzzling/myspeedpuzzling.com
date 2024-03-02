/**
 * Data filtering (Comparison table)
*/

const dataFilter = (() => {

  let trigger = document.querySelector('[data-filter-trigger]'),
  target = document.querySelectorAll('[data-filter-target]');

  if (trigger === null) return;

  trigger.addEventListener('change', function() {
    let selected = this.options[this.selectedIndex].value.toLowerCase();
    if (selected === 'all') {
      for (let i = 0; i < target.length; i++) {
        target[i].classList.remove('d-none');
      }
    } else {
      for (let n = 0; n < target.length; n++) {
        target[n].classList.add('d-none');
      }
      document.querySelector('#' + selected).classList.remove('d-none');
    }
  });
})();

export default dataFilter;
