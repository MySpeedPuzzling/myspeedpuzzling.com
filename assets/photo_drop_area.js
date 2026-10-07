// A photo drop area (.file-drop-area - photo_stash/_photo_drop_area.html.twig, admin/_puzzle_record_image.html.twig)
// in a form that offers images to choose from as well: whether it holds a photo - a chosen file or one kept from a
// refused submit (FormPhotoStash) - and emptying it again when another image is picked instead.

export function hasPhoto(area) {
    const input = area.querySelector('.file-drop-input');
    const token = area.querySelector('[data-kept-photo-target="token"]');

    return Boolean(input && input.files && input.files.length > 0) || Boolean(token && token.value);
}

// Back to an empty drop area: no file, no kept photo, no crop button
export function clearPhoto(area, dropText) {
    if (!hasPhoto(area)) {
        return;
    }

    const icon = area.querySelector('[data-role="drop-icon"]');
    const token = area.querySelector('[data-kept-photo-target="token"]');

    area.querySelector('.file-drop-input').value = '';

    if (token) {
        token.value = '';
    }

    area.querySelector('[data-kept-photo-target="note"]')?.remove();
    area.querySelector('.file-drop-edit-btn')?.remove();

    if (icon) {
        icon.className = 'file-drop-icon';
        icon.innerHTML = '<i class="ci-cloud-upload"></i>';
    }

    area.querySelector('.file-drop-message').textContent = dropText || '';
}
