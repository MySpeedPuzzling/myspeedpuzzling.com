import { Controller } from '@hotwired/stimulus';
import { visit } from '@hotwired/turbo';

export default class extends Controller {
    static targets = ["checkbox"];

    update() {
        // Save the current scroll position
        const scrollPosition = window.scrollY;

        const url = this.checkboxTarget.dataset.toggleFilterUrl;

        visit(url);

        document.addEventListener(
            'turbo:load',
            () => {
                window.scrollTo(0, scrollPosition);
            },
            { once: true } // Ensure the event listener is only triggered once
        );
    }
}
