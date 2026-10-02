import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    toggle(event) {
        event.preventDefault();

        // Get the target element's identifier from the action parameter
        const targetSelector = event.params.target;
        const clickedElement = event.currentTarget;
        clickedElement.classList.add('hidden');

        const targetElements = this.element.querySelectorAll(`[data-toggle-target="${targetSelector}"]`);

        targetElements.forEach(targetElement => {
            if (targetElement) {
                targetElement.classList.remove('hidden');
            }
        });

        // Optional: what the revealed part replaces (e.g. the chosen puzzle card when the picker opens)
        if (event.params.hide) {
            this.element.querySelectorAll(`[data-toggle-target="${event.params.hide}"]`).forEach(element => {
                element.classList.add('hidden');
            });
        }
    }
}
