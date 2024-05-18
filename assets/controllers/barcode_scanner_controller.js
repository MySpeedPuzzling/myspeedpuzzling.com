import { Controller } from '@hotwired/stimulus';
import Quagga from '@ericblade/quagga2';

export default class extends Controller {
    static targets = ["video"]
    static values = {
        url: String
    }

    connect() {
        this.startScanner();
    }

    startScanner() {
        Quagga.init({
            inputStream: {
                name: "Live",
                type: "LiveStream",
                target: this.videoTarget
            },
            decoder: {
                readers: ["ean_reader"]
            },
            locate: true,
            debug: true
        }, function(err) {
            if (err) {
                console.error(err);
                return;
            }
            console.log("Initialization finished. Ready to start");
            Quagga.start();
        });

        Quagga.onDetected((result) => this.onDetected(result));
    }

    onDetected(result) {
        alert(result)
        const code = result.codeResult.code;

        if (/^\d{10,15}$/.test(code)) { // Match only numbers with a length between 10 and 15
            const finalUrl = this.urlValue.replace('EAN_PLACEHOLDER', code);
            window.location.replace(finalUrl);  // Replace the current URL without pushing to history
        }
    }

    disconnect() {
        Quagga.stop();
    }
}
