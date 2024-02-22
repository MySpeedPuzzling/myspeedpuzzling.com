import { Controller } from '@hotwired/stimulus';
import { Tab } from 'bootstrap';

export default class extends Controller {
    connect() {
        this.showTabFromURLHash();
        this.handleHashChange();
    }

    showTabFromURLHash() {
        const hash = window.location.hash;
        if (hash) {
            const tabElement = this.element.querySelector(`.nav-link[href="${hash}"]`);
            if (tabElement) {
                new Tab(tabElement).show();
            }
        }
    }

    showTab(event) {
        event.preventDefault();
        const tabElement = event.currentTarget;
        if (tabElement) {
            new Tab(tabElement).show();
            const newHash = tabElement.getAttribute('href');
            history.replaceState(null, null, newHash);
            this.showTabFromURLHash();
        }
    }

    handleHashChange() {
        window.addEventListener('hashchange', () => this.showTabFromURLHash(), false);
    }
}
