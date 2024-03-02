/**
 * Change tabs with radio buttons
*/

const radioTab = (() => {

  let radioBtns = document.querySelectorAll('[data-bs-toggle="radioTab"]');

  for (let i = 0; i < radioBtns.length; i++ ) {
    radioBtns[i].addEventListener('click', function() {
      let target = this.dataset.bsTarget,
          parent = document.querySelector(this.dataset.bsParent),
          children = parent.querySelectorAll('.radio-tab-pane');

      children.forEach(function(element) {
        element.classList.remove('active');
      });

      document.querySelector(target).classList.add('active');
    });
  }
})();

export default radioTab;
