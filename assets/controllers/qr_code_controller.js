import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static values = {
        imageUrl: String,
        puzzleName: String,
    };

    static targets = ['shareButton'];

    connect() {
        const shareData = { files: [new File([], '')] };
        if (navigator.canShare && navigator.canShare(shareData)) {
            this.shareButtonTarget.style.display = '';
        }
    }

    async share() {
        try {
            const response = await fetch(this.imageUrlValue);
            const blob = await response.blob();
            const file = new File([blob], `${this.puzzleNameValue}-qr.png`, {
                type: 'image/png',
                lastModified: new Date().getTime(),
            });

            const shareData = { files: [file] };
            if (navigator.canShare && navigator.canShare(shareData)) {
                await navigator.share(shareData);
            }
        } catch (error) {
            console.error('Error sharing QR code:', error);
        }
    }
}
