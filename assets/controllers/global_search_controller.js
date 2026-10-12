import { Controller } from '@hotwired/stimulus';
import { LAYER_CLOSED_EVENT } from '../layer_history.js';

// Whatever opens the page's search (the header's search tool, the 404 page's button) - one delegated listener
const TOGGLE = '[data-global-search-toggle]';

// The Menu panel's shell (templates/navigation/_menu_layer.html.twig): searching there hides the Menu (CSS)
const SHELL = '.menu-layer';
const SEARCHING = 'is-searching';

/**
 * The page search's own behaviour (templates/components/GlobalSearch.html.twig; navigation MENU-03, R3): one
 * controller per GlobalSearch instance, on the component's root, reading only its own elements (targets) - never "the
 * first search input on the page". Mode:
 * - `bar`: the page's search, a dropdown under the header opened from any [data-global-search-toggle]
 *   (<body class="global-search-shown">); × or a click outside closes it and clears the field.
 * - `menu`: the Menu panel's field. Typing shows the results inside the panel (the shell gets .is-searching, CSS hides
 *   #site-menu) - same layer, same history entry. Esc clears the field first and closes the panel only when it is
 *   empty (assets/layer_history.js handles that Esc); the field is cleared whenever the panel closes.
 * Both close the barcode scanner when the field gets focus. "Pages & settings" is nav_search_controller.js's.
 */
export default class extends Controller {
    static targets = ['searchInput'];
    static values = { mode: { type: String, default: 'bar' } };

    connect() {
        this.searchInputTarget.addEventListener('focus', this.handleFocus);
        this.searchInputTarget.addEventListener('keydown', this.handleKeydown);
        this.searchInputTarget.addEventListener('input', this.handleInput);

        if (this.isMenu()) {
            document.addEventListener(LAYER_CLOSED_EVENT, this.handleLayerClosed);
            this.syncSearching();
        } else {
            document.addEventListener('click', this.handleToggleClick);
        }
    }

    disconnect() {
        this.searchInputTarget.removeEventListener('focus', this.handleFocus);
        this.searchInputTarget.removeEventListener('keydown', this.handleKeydown);
        this.searchInputTarget.removeEventListener('input', this.handleInput);
        document.removeEventListener(LAYER_CLOSED_EVENT, this.handleLayerClosed);
        document.removeEventListener('click', this.handleToggleClick);
        document.removeEventListener('click', this.handleOutsideClick);
    }

    isMenu() {
        return this.modeValue === 'menu';
    }

    handleFocus = () => {
        window.dispatchEvent(new CustomEvent('barcode-scan:close'));
    };

    handleInput = () => {
        if (this.isMenu()) {
            this.syncSearching();
        }
    };

    /** Menu: Esc clears a filled field (and stops there - the panel stays); an empty field lets Esc close the panel */
    handleKeydown = (event) => {
        if (event.key !== 'Escape' || !this.isMenu() || this.searchInputTarget.value === '') {
            return;
        }

        event.preventDefault();
        this.clear();
    };

    /** Menu: the panel closed (Back, ×, Esc, a modal taking over) - the next open starts with an empty field */
    handleLayerClosed = (event) => {
        const detail = event && event.detail ? event.detail : {};

        if (detail.id === 'menu' && detail.reason !== 'leave') {
            this.clear();
        }
    };

    syncSearching() {
        const shell = this.element.closest(SHELL);

        if (shell) {
            shell.classList.toggle(SEARCHING, this.searchInputTarget.value.trim() !== '');
        }
    }

    clear() {
        if (this.searchInputTarget.value !== '') {
            this.searchInputTarget.value = '';
            // The Live model and "Pages & settings" follow the field
            this.searchInputTarget.dispatchEvent(new Event('input', { bubbles: true }));
        }

        if (this.isMenu()) {
            this.syncSearching();
        }
    }

    // ---- bar ---------------------------------------------------------------------------------------------------

    handleToggleClick = (event) => {
        const toggle = event.target && typeof event.target.closest === 'function' ? event.target.closest(TOGGLE) : null;

        if (!toggle) {
            return;
        }

        event.preventDefault();
        this.toggleSearchBar();
    };

    toggleSearchBar(event) {
        if (event) {
            event.preventDefault();
        }

        if (this.isSearchBarOpen()) {
            this.closeSearchBar();
        } else {
            this.openSearchBar();
        }
    }

    openSearchBar() {
        document.body.classList.add('global-search-shown');
        this.searchInputTarget.focus();
        // Added while the opening click is still being dispatched on the document: that click never reaches it
        document.addEventListener('click', this.handleOutsideClick);

        // Close the Bootstrap navbar collapse menu
        const navbarCollapse = document.querySelector('.navbar-collapse.show');

        if (navbarCollapse) {
            navbarCollapse.classList.remove('show');

            const navbarToggler = document.querySelector('.navbar-toggler[aria-expanded="true"]');

            if (navbarToggler) {
                navbarToggler.setAttribute('aria-expanded', 'false');
            }
        }
    }

    closeSearchBar(event) {
        if (event) {
            event.preventDefault();
        }

        document.body.classList.remove('global-search-shown');
        document.removeEventListener('click', this.handleOutsideClick);
        window.dispatchEvent(new CustomEvent('barcode-scan:close'));
        this.clear();
    }

    handleOutsideClick = (event) => {
        const target = event.target;

        if (!target || typeof target.closest !== 'function' || target.closest(TOGGLE)) {
            return;
        }

        if (target.closest('.modal') || target.closest('.modal-backdrop')) {
            return;
        }

        // Close on the overlay or anywhere outside the search
        if (target.classList.contains('global-search-overlay') || !target.closest('.global-search')) {
            this.closeSearchBar();
        }
    };

    isSearchBarOpen() {
        return document.body.classList.contains('global-search-shown');
    }
}
