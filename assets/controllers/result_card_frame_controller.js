import { Controller } from '@hotwired/stimulus';

// Ends the shimmer of the recap page's share card once the picture arrived - or could not. A picture served from the
// browser's cache is complete before this controller connects and never fires `load` for it.
export default class extends Controller {
    static targets = ['image'];

    connect() {
        if (this.imageTarget.complete) {
            this.settle();
        }
    }

    settle() {
        this.element.classList.remove('is-loading');
    }
}
