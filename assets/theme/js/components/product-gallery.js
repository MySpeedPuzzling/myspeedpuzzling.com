/**
 * Shop product page gallery with thumbnails
 * @requires https://github.com/sachinchoolur/lightgallery.js
*/

const productGallery = (() => {

  let gallery = document.querySelectorAll('.product-gallery');
  if (gallery.length) {

    for (let i = 0; i < gallery.length; i++) {
      
      let thumbnails = gallery[i].querySelectorAll('.product-gallery-thumblist-item:not(.video-item)'),
          previews = gallery[i].querySelectorAll('.product-gallery-preview-item'),
          videos = gallery[i].querySelectorAll('.product-gallery-thumblist-item.video-item');


      for (let n = 0; n < thumbnails.length; n++) {
        thumbnails[n].addEventListener('click', changePreview);
      }

      // Changer preview function
      function changePreview(e) {
        e.preventDefault();
        for (let i = 0; i < thumbnails.length; i++) {
          previews[i].classList.remove('active');
          thumbnails[i].classList.remove('active');
        }
        this.classList.add('active');
        gallery[i].querySelector(this.getAttribute('href')).classList.add('active');
      }

      // Video thumbnail - open video in lightbox
      for (let m = 0; m < videos.length; m++) {
        lightGallery(videos[m], {
          selector: 'this',
          plugins: [lgVideo],
          licenseKey: 'D4194FDD-48924833-A54AECA3-D6F8E646',
          download: false,
          autoplayVideoOnSlide: true,
          zoomFromOrigin: false,
          youtubePlayerParams: {
            modestbranding: 1,
            showinfo: 0,
            rel: 0,
            controls: 0
          },
          vimeoPlayerParams: {
            byline: 0,
            portrait: 0,
            color: 'fe696a'
          }
        });
      }
    }
  }

})();

export default productGallery;
