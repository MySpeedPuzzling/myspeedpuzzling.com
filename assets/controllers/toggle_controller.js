import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    toggle(event) {
        event.preventDefault();

        // Get the target element's identifier from the action parameter
        const targetSelector = event.params.target;
        const clickedElement = event.currentTarget;
        clickedElement.classList.add('hidden');

        // Use the identifier to find the target element
        const targetElement = this.element.querySelector(`[data-toggle-target="${targetSelector}"]`);

        // Toggle the 'hidden' class on the target element
        if (targetElement) {
            targetElement.classList.remove('hidden');
        }
    }
}
