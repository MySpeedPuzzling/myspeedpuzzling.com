/**
 * Content carousel with extensive options to control behaviour and appearance
 * @requires https://github.com/ganlanyuan/tiny-slider
*/

const carousel = (() => {

  // forEach function
  let forEach = function (array, callback, scope) {
    for (let i = 0; i < array.length; i++) {
      callback.call(scope, i, array[i]); // passes back stuff we need
    }
  };

  // Carousel initialisation
  let carousels = document.querySelectorAll('.tns-carousel .tns-carousel-inner');
  forEach(carousels, function (index, value) {
    let defaults = {
      container: value,
      controlsText: ['<i class="ci-arrow-left"></i>', '<i class="ci-arrow-right"></i>'],
      navPosition: 'bottom',
      mouseDrag: true,
      speed: 500,
      autoplayHoverPause: true,
      autoplayButtonOutput: false
    };
    let userOptions;
    if(value.dataset.carouselOptions != undefined) userOptions = JSON.parse(value.dataset.carouselOptions);
    let options = Object.assign({}, defaults, userOptions);
    let carousel = tns(options);
  });
})();

export default carousel;
