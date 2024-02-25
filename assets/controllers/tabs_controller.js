import { Controller } from '@hotwired/stimulus';
// import { Tab } from 'bootstrap';

export default class extends Controller {
/*
    connect() {
        // Wait for the DOM content to fully load, ensuring accurate positioning.
        setTimeout(() => {
            this.showTabFromURLHash();
            this.adjustScrollPosition();
            this.handleHashChange();
        }, 100);
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

    // Stimulus action referenced from templates
    showTab(event) {
        event.preventDefault();
        const tabElement = event.currentTarget;
        if (tabElement) {
            new Tab(tabElement).show();
            const newHash = tabElement.getAttribute('href');
            history.replaceState(null, null, newHash);
        }
    }

    adjustScrollPosition() {
        const hash = window.location.hash;
        if (hash) {
            const targetElement = document.querySelector(hash);
            if (targetElement) {
                const navHeight = document.querySelector('.nav').offsetHeight; // Adjust this selector as needed
                const offsetPosition = targetElement.offsetTop - navHeight - 60; // Additional spacing above the target

                window.scrollTo({
                    top: offsetPosition,
                    behavior: 'smooth' // Smooth scroll is optional
                });
            }
        }
    }

    handleHashChange() {
        window.addEventListener('hashchange', () => {
            this.showTabFromURLHash();
            this.adjustScrollPosition();
        }, false);
    }
*/
}
