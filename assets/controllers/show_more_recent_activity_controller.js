import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['button'];

    revealRows() {
        // Remove the 'hidden' class from all rows
        this.element.querySelectorAll('tr.d-none').forEach(row => {
            row.classList.remove('d-none');
        });


        this.buttonTarget.remove();
    }
}
