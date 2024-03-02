import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ["input", "buttonIcon"];

    selectInputContent(event) {
        event.preventDefault(); // Prevent default behavior
        this.inputTarget.select(); // Select the content of the input
    }

    copyToClipboard(event) {
        event.preventDefault();
        this.inputTarget.select(); // Ensure the input content is selected
        navigator.clipboard.writeText(this.inputTarget.value).then(() => {
            this.buttonIconTarget.classList.replace("ci-share", "ci-check");
        }).catch(err => {
            console.error('Failed to copy: ', err);
        });
    }
}
