/**
 * Cascading (Masonry) grid layout
 * 
 * @requires https://github.com/desandro/imagesloaded
 * @requires https://github.com/Vestride/Shuffle
*/

const masonryGrid = (() => {

  let grid = document.querySelectorAll('.masonry-grid'),
      masonry;

  if (grid === null) return;
  
  for (let i = 0; i < grid.length; i++) {
    masonry = new Shuffle(grid[i], {
      itemSelector: '.masonry-grid-item',
      sizer: '.masonry-grid-item'
    });

    imagesLoaded(grid[i]).on('progress', () => {
      masonry.layout();
    });
  }
})();

export default masonryGrid;
