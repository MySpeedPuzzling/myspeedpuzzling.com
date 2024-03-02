/**
 * Change tabs with radio buttons
*/

const creditCard = (() => {
        
  let selector = document.querySelector('.credit-card-form');
      
  if (selector === null) return;

  let card = new Card({
    form: selector,
    container: '.credit-card-wrapper'
  });
})();

export default creditCard;
