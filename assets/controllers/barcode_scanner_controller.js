import { Controller } from '@hotwired/stimulus';
import Quagga from 'quagga';

export default class extends Controller {
    static targets = ["stream"];

    connect() {
        Quagga.init({
            inputStream: {
                name: "Live",
                type: "LiveStream",
                target: this.streamTarget,  // This is where the video will be attached
                constraints: {
                    facingMode: "environment"
                },
            },
            decoder: {
                readers: ["ean_reader"]
            },
        }, (err) => {
            if (err) {
                console.error(err);
                return;
            }
            Quagga.start();
        });

        Quagga.onDetected(this.onDetected.bind(this));
    }

    onDetected(result) {
        console.log(result.codeResult.code); // Log barcode to console or handle as needed
        // Optionally, send the code to your backend here
    }

    disconnect() {
        Quagga.stop();
    }
}
