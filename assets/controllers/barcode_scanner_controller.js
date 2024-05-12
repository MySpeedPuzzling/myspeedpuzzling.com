import { Controller } from '@hotwired/stimulus';
import { BrowserMultiFormatReader, NotFoundException } from '@zxing/library';

export default class extends Controller {
    static targets = ["video"]

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
                    console.log(result);
                    // Process result here (e.g., fetch data from backend)
                }
                if (err && !(err instanceof NotFoundException)) {
                    console.error(err);
                }
            });
        } catch (error) {
            console.error('Error with ZXing:', error);
        }
    }

    disconnect() {
        this.reader.reset();
    }
}
