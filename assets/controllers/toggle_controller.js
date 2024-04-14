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
    }
}
