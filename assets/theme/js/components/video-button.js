/**
 * Open YouTube video in lightbox
 * @requires https://github.com/sachinchoolur/lightGallery
*/

const videoButton = (() => {

  let button = document.querySelectorAll('[data-bs-toggle="video"]');
  if (button.length) {
    for (let i = 0; i < button.length; i++) {
      lightGallery(button[i], {
        selector: 'this',
        plugins: [lgVideo],
        licenseKey: 'D4194FDD-48924833-A54AECA3-D6F8E646',
        download: false,
        youtubePlayerParams: {
          modestbranding: 1,
          showinfo: 0,
          rel: 0
        },
        vimeoPlayerParams: {
          byline: 0,
          portrait: 0,
          color: '6366f1'
        }
      });
    }
  }

})();

export default videoButton;
