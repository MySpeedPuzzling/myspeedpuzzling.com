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

    print() {
        const printWindow = window.open('', '_blank');
        if (!printWindow) return;

        printWindow.document.write(`<!DOCTYPE html>
<html><head><title>QR Code - ${this.puzzleNameValue}</title>
<style>
body { margin: 0; display: flex; flex-direction: column; align-items: center; justify-content: center; min-height: 100vh; font-family: system-ui, sans-serif; }
img { max-width: 300px; }
p { margin: 8px 0; }
</style>
</head><body>
<img src="${this.imageUrlValue}">
<p><strong>${this.puzzleNameValue}</strong></p>
</body></html>`);
        printWindow.document.close();

        const img = printWindow.document.querySelector('img');
        const triggerPrint = () => {
            printWindow.focus();
            setTimeout(() => printWindow.print(), 300);
        };

        if (img.complete) {
            triggerPrint();
        } else {
            img.addEventListener('load', triggerPrint);
        }
    }
}
