import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static values = {
        title: String,
        imageUrl: String
    }

    connect() {
        // Check if navigator.canShare is supported
        const shareData = { files: [new File([], '')] }; // Dummy data to test support
        if (!(navigator.canShare && navigator.canShare(shareData))) {
            this.element.style.display = 'none'; // Hide the element if sharing is not supported
        }
    }

    async shareImageAsset() {
        try {
            const response = await fetch(this.imageUrlValue);
            const blobImageAsset = await response.blob();

            // The file takes the type the server answered with: a share started from a page cached before the card
            // became a JPEG can still get a PNG
            const type = blobImageAsset.type === 'image/png' ? 'image/png' : 'image/jpeg';
            const extension = type === 'image/png' ? 'png' : 'jpg';

            const filesArray = [
                new File([blobImageAsset], `${this.titleValue}.${extension}`, {
                    type,
                    lastModified: new Date().getTime(),
                }),
            ];
            const shareData = {
                files: filesArray,
            };

            if (navigator.canShare && navigator.canShare(shareData)) {
                await navigator.share(shareData);
            }
        } catch (error) {
            console.error('Error sharing the image:', error);
        }
    }
}
