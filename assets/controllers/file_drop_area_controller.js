import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    connect() {
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

                                // Add edit button if not exists
                                let editBtn = fileArea[i].querySelector('.file-drop-edit-btn');
                                if (!editBtn) {
                                    // Get translation from image-editor controller if available
                                    const imageEditorEl = fileArea[i].closest('[data-image-editor-edit-value]');
                                    const editLabel = imageEditorEl ? imageEditorEl.dataset.imageEditorEditValue : 'Edit';

                                    editBtn = document.createElement('button');
                                    editBtn.type = 'button';
                                    editBtn.className = 'file-drop-edit-btn btn btn-sm btn-outline-secondary';
                                    editBtn.innerHTML = '<i class="bi bi-crop me-1"></i> ' + editLabel;
                                    editBtn.setAttribute('data-action', 'click->image-editor#openEditor');

                                    const chooseBtn = fileArea[i].querySelector('.file-drop-btn');
                                    chooseBtn.parentNode.insertBefore(editBtn, chooseBtn.nextSibling);
                                }
                            }

                        } else if (fileData.startsWith('data:video')) {
                            icon.innerHTML = '';
                            icon.className = '';
                            icon.className = 'file-drop-icon ci-video';
                            removeEditButton(fileArea[i]);

                        } else {
                            icon.innerHTML = '';
                            icon.className = '';
                            icon.className = 'file-drop-icon ci-document';
                            removeEditButton(fileArea[i]);
                        }
                    }
                    reader.readAsDataURL(input.files[0]);
                }

            });
        }

        function removeEditButton(area) {
            const editBtn = area.querySelector('.file-drop-edit-btn');
            if (editBtn) {
                editBtn.remove();
            }
        }
    }
}
