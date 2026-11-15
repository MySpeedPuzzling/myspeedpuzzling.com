import { Controller } from '@hotwired/stimulus';

/*
 * A form the browser sends itself (`data-turbo="false"` - the row editor of the data import): its buttons are
 * switched off once it is sent, so a second click can not send it again. A switched-off button is left out of what
 * the browser sends, so the clicked button's name and value travel in a hidden field instead. Coming back to the
 * page from the browser's history switches them on again.
 */
export default class extends Controller {
    connect() {
        this.unlock = this.unlock.bind(this);
        window.addEventListener('pageshow', this.unlock);
    }

    disconnect() {
        window.removeEventListener('pageshow', this.unlock);
    }

    lock(event) {
        if (this.sent) {
            event.preventDefault();

            return;
        }

        this.sent = true;
        const submitter = event.submitter;

        if (submitter && submitter.name) {
            this.carried = document.createElement('input');
            this.carried.type = 'hidden';
            this.carried.name = submitter.name;
            this.carried.value = submitter.value;
            this.element.appendChild(this.carried);
        }

        this.buttons().forEach((button) => {
            button.disabled = true;
        });
    }

    unlock() {
        this.sent = false;

        if (this.carried) {
            this.carried.remove();
            this.carried = null;
        }

        this.buttons().forEach((button) => {
            button.disabled = false;
        });
    }

    buttons() {
        return this.element.querySelectorAll('button[type="submit"]');
    }
}
