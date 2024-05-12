import { Controller } from '@hotwired/stimulus';
import { BrowserMultiFormatReader, NotFoundException } from '@zxing/library';

export default class extends Controller {
    static targets = ["video"]
    static values = {
        url: String
    }

    connect() {
        this.reader = new BrowserMultiFormatReader();
        this.startScanner();
    }

    async startScanner() {
        try {
            const videoInputDevices = await this.reader.listVideoInputDevices();
            const selectedDeviceId = videoInputDevices[0].deviceId;
            this.reader.decodeFromVideoDevice(selectedDeviceId, this.videoTarget, (result, err) => {
                if (result) {
                    console.log(result.text);  // Log the barcode content
                    if (this.isValidEAN(result.text)) {
                        const finalUrl = this.urlValue.replace('EAN_PLACEHOLDER', result.text);
                        window.location.replace(finalUrl);  // Redirect to the dynamically created URL
                    } else {
                        console.log('Invalid EAN scanned:', result.text);
                    }
                }
                if (err && !(err instanceof NotFoundException)) {
                    console.error(err);
                }
            });
        } catch (error) {
            console.error('Error with ZXing:', error);
        }
    }

    isValidEAN(barcode) {
        return /^\d{10,15}$/.test(barcode);
    }

    disconnect() {
        this.reader.reset();
    }
}
