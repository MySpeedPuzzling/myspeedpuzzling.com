/**
 * Image zoom on hover (used inside Product Gallery)
 * @requires https://github.com/imgix/drift
*/

const imageZoom = (() => {

  let images = document.querySelectorAll('.image-zoom');

  for (let i = 0; i < images.length; i++) {
    new Drift(images[i], {
      paneContainer: images[i].parentElement.querySelector('.image-zoom-pane')
    });
  }

})();

export default imageZoom;
