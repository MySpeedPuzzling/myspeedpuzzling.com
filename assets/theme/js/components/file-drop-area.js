/**
 * Custom file drag and drop area
*/

const fileDropArea = (() => {

  const fileArea = document.querySelectorAll('.file-drop-area');

  for (let i = 0; i < fileArea.length; i++) {
    let input = fileArea[i].querySelector('.file-drop-input'),
        message = fileArea[i].querySelector('.file-drop-message'),
        icon = fileArea[i].querySelector('.file-drop-icon'),
        button = fileArea[i].querySelector('.file-drop-btn');
    
    button.addEventListener('click', function() {
      input.click();
    });

    input.addEventListener('change', function() {
      if (input.files && input.files[0]) {
        let reader = new FileReader();
        reader.onload = (e) => {
          let fileData = e.target.result;
          let fileName = input.files[0].name;
          message.innerHTML = fileName;

          if (fileData.startsWith('data:image')) {

            let image = new Image();
            image.src = fileData;

            image.onload = function() {
              icon.className = 'file-drop-preview img-thumbnail rounded';
              icon.innerHTML = '<img src="' + image.src + '" alt="' + fileName + '">';
            }

          } else if (fileData.startsWith('data:video')) {
            icon.innerHTML = '';
            icon.className = '';
            icon.className = 'file-drop-icon ci-video';

          } else {
            icon.innerHTML = '';
            icon.className = '';
            icon.className = 'file-drop-icon ci-document';
          }
        }
        reader.readAsDataURL(input.files[0]);
      }

    });
  }
})();

export default fileDropArea;
