import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static values = {
        targetId: String,
    };

    scroll(event) {
        event.preventDefault();

        const target = document.getElementById(this.targetIdValue);
        if (target) {
            target.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }
    }
}
