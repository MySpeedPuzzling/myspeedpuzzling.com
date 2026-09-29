import { Controller } from '@hotwired/stimulus';
import { Collapse } from 'bootstrap';

/*
 * FAQ answers start collapsed, so a deep link like /en/faq#duplicate-accounts
 * would land on a closed question. Opens the question the URL fragment names
 * (on load and on in-page hash changes) and scrolls it into view.
 */
export default class extends Controller {
    connect() {
        this.onHashChange = () => this.openFromHash();
        window.addEventListener('hashchange', this.onHashChange);
        this.openFromHash();
    }

    disconnect() {
        window.removeEventListener('hashchange', this.onHashChange);
    }

    openFromHash() {
        let id;

        try {
            id = decodeURIComponent(window.location.hash.slice(1));
        } catch {
            return;
        }

        if (id === '') {
            return;
        }

        const item = document.getElementById(id);

        if (item === null || !this.element.contains(item)) {
            return;
        }

        const panel = item.querySelector('.accordion-collapse');

        if (panel !== null) {
            Collapse.getOrCreateInstance(panel, { toggle: false }).show();
        }

        item.scrollIntoView({ block: 'start' });
    }
}
