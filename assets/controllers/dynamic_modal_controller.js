import { Controller } from '@hotwired/stimulus';
import { Modal } from 'bootstrap';

// A response faster than this opens the modal straight with its content; a slower one shows a spinner first
const LOADING_DELAY_MS = 150;

// A click this recent is what started the frame request (focus goes back to it on close)
const TRIGGER_CLICK_WINDOW_MS = 1000;

// Given to a .modal-title without an id, so the dialog can be aria-labelledby it
const TITLE_ID = 'dynamic-modal-title';

/**
 * Dynamic Modal Controller
 *
 * Handles the one global modal (#dynamic-modal-container in base.html.twig) that loads its content
 * via the Turbo Frame `modal-frame`.
 *
 * Lifecycle:
 * - turbo:before-fetch-request on the frame (a link with data-turbo-frame="modal-frame" was followed,
 *   or a form inside the modal was submitted) marks that the modal should open when content arrives.
 *   When the modal is not open yet and the response takes longer than LOADING_DELAY_MS, the modal
 *   opens with a spinner placeholder. Form submissions inside an open modal never show it.
 * - turbo:frame-load opens the modal (or swaps the spinner for the content) and applies the size
 *   from the first [data-modal-size] element in the content.
 * - The modal closes when the frame becomes empty (Turbo Stream `update` with an empty template,
 *   see _modal_close_stream.html.twig), on a `modal:close` document event, Escape or a backdrop click.
 *   The frame is cleared only after the hide animation finished.
 * - Responses that are not modal content (error pages, a redirect to the login page) are shown
 *   as a normal full page visit instead of an empty or "Content missing" modal.
 *
 * Accessibility: the dialog gets focus when shown (the Bootstrap focus trap is off for tom-select),
 * focus goes back to the element that opened it when closed, the dialog is aria-busy while loading
 * and aria-labelledby the content's .modal-title.
 *
 * A link with data-turbo-frame="_top" inside the modal is a normal Turbo Drive visit: the modal
 * stays visible until the new page renders, and since Turbo replaces the whole <body> (backdrop,
 * modal-open class and Bootstrap's inline body styles included), nothing is left behind.
 *
 * Layout, read from the content's [data-modal-size] element like the size:
 * - data-modal-scrollable: header (and footer) stay pinned, only the body scrolls (modal-dialog-scrollable)
 * - data-modal-sheet: on phones a near full screen sheet (a strip of the page stays visible above it)
 *
 * Back button / back gesture (data-modal-history on the same element): the open modal gets a history entry
 * of its own, so "back" closes it instead of leaving the page. Turbo's history is paused meanwhile - Turbo
 * answers every pop with a restoration visit, which app.js turns into a full page load. Closing the modal
 * otherwise takes the entry back out; a Turbo visit from inside the modal resumes Turbo's history first,
 * which turns the entry into a normal one of the page.
 */
export default class extends Controller {
    static targets = ['frame', 'loading'];

    modal = null;
    observer = null;
    pendingOpen = false;
    loadingTimer = null;

    // Element that opened the modal - gets focus back when it closes
    trigger = null;
    lastClicked = null;
    lastClickedAt = 0;

    // 'showing' | 'hiding' while Bootstrap animates; Bootstrap ignores show()/hide() during a transition,
    // so a request made meanwhile is queued and replayed when the transition ends
    transition = null;
    queued = null;

    // The history entry of the open modal (data-modal-history)
    historyEntry = false;
    leavingHistoryEntry = false;

    connect() {
        // Disable focus trap to allow interaction with tom-select dropdowns
        // that render outside the modal dialog
        this.modal = Modal.getOrCreateInstance(this.element, {
            focus: false
        });

        // Track when a fetch starts (we want to open modal when content arrives)
        this.frameTarget.addEventListener('turbo:before-fetch-request', this.handleBeforeFetch);
        this.frameTarget.addEventListener('turbo:before-fetch-response', this.handleBeforeFetchResponse);
        this.frameTarget.addEventListener('turbo:fetch-request-error', this.handleFetchError);

        // Open modal when content arrives
        this.frameTarget.addEventListener('turbo:frame-load', this.handleFrameLoad);

        // Response without <turbo-frame id="modal-frame"> (e.g. a bare 500 page)
        this.frameTarget.addEventListener('turbo:frame-missing', this.handleFrameMissing);

        // Watch for frame becoming empty (close trigger)
        this.observer = new MutationObserver(this.handleMutation);
        this.observer.observe(this.frameTarget, { childList: true, subtree: true });

        // Close modal on Escape key (Bootstrap only sees it when focus is inside the modal)
        document.addEventListener('keydown', this.handleKeydown);

        // Listen for programmatic close events
        document.addEventListener('modal:close', this.handleClose);

        // Remember the clicked link/button - Safari does not focus links on click,
        // so document.activeElement is not enough to return focus later
        document.addEventListener('click', this.handleDocumentClick, true);

        this.element.addEventListener('show.bs.modal', this.handleShow);
        this.element.addEventListener('shown.bs.modal', this.handleShown);
        this.element.addEventListener('hide.bs.modal', this.handleHide);

        // Clear frame content only after close animation finishes
        this.element.addEventListener('hidden.bs.modal', this.handleHidden);

        window.addEventListener('popstate', this.handlePopState);
        document.addEventListener('turbo:before-visit', this.handleBeforeVisit);
    }

    disconnect() {
        clearTimeout(this.loadingTimer);
        this.frameTarget.removeEventListener('turbo:before-fetch-request', this.handleBeforeFetch);
        this.frameTarget.removeEventListener('turbo:before-fetch-response', this.handleBeforeFetchResponse);
        this.frameTarget.removeEventListener('turbo:fetch-request-error', this.handleFetchError);
        this.frameTarget.removeEventListener('turbo:frame-load', this.handleFrameLoad);
        this.frameTarget.removeEventListener('turbo:frame-missing', this.handleFrameMissing);
        this.observer?.disconnect();
        document.removeEventListener('keydown', this.handleKeydown);
        document.removeEventListener('modal:close', this.handleClose);
        document.removeEventListener('click', this.handleDocumentClick, true);
        this.element.removeEventListener('show.bs.modal', this.handleShow);
        this.element.removeEventListener('shown.bs.modal', this.handleShown);
        this.element.removeEventListener('hide.bs.modal', this.handleHide);
        this.element.removeEventListener('hidden.bs.modal', this.handleHidden);
        window.removeEventListener('popstate', this.handlePopState);
        document.removeEventListener('turbo:before-visit', this.handleBeforeVisit);
        this.releaseHistory();
    }

    handleDocumentClick = (event) => {
        const clicked = event.target instanceof Element ? event.target.closest('a, button') : null;
        if (clicked) {
            this.lastClicked = clicked;
            this.lastClickedAt = Date.now();
        }
    };

    handleBeforeFetch = () => {
        // Mark that we want to open the modal when content arrives
        this.pendingOpen = true;
        this.element.setAttribute('aria-busy', 'true');

        // Navigation or form submission inside the already open modal - no spinner, keep the trigger
        if (this.isOpen()) {
            return;
        }

        this.trigger = this.findTrigger();

        clearTimeout(this.loadingTimer);
        this.loadingTimer = setTimeout(this.showLoading, LOADING_DELAY_MS);
    };

    handleBeforeFetchResponse = () => {
        // Turbo Stream responses never fire turbo:frame-load, so the busy state ends here
        this.element.removeAttribute('aria-busy');
    };

    handleFetchError = () => {
        // Network error: nothing will arrive
        this.pendingOpen = false;
        this.stopLoading();

        // Keep an open modal with a form in it (the visitor can submit again),
        // close the one that only shows the spinner
        if (this.frameTarget.innerHTML.trim() === '') {
            this.close();
        }
    };

    handleFrameLoad = () => {
        const wasLoading = this.isLoading();
        this.stopLoading();

        if (!this.pendingOpen) {
            // The visitor closed the modal while it was loading - drop the late content
            if (!this.isOpen()) {
                this.frameTarget.innerHTML = '';
            }
            return;
        }

        this.pendingOpen = false;

        // Opening from a link, but the response had an empty modal-frame: it is a full page that is not modal
        // content (error page extending base.html.twig, redirect to the login page) - show it as a page.
        // (Inside an open modal an empty frame keeps meaning "close", handled by the MutationObserver.)
        if (this.frameTarget.innerHTML.trim() === '' && (!this.isOpen() || wasLoading)) {
            this.visitFrameUrl();
            return;
        }

        this.open();
    };

    handleFrameMissing = (event) => {
        // The response has no <turbo-frame id="modal-frame"> at all - instead of Turbo's "Content missing",
        // render the response itself as a full page (the visitor sees the real error page)
        event.preventDefault();
        this.pendingOpen = false;
        this.stopLoading();
        this.trigger = null;
        this.close();
        event.detail.visit(event.detail.response);
    };

    handleMutation = () => {
        // Close modal if frame content is empty (cleared by Turbo Stream)
        if (this.frameTarget.innerHTML.trim() === '' && this.isOpen() && !this.isLoading()) {
            this.close();
        }
    };

    handleKeydown = (event) => {
        if (event.key === 'Escape' && this.isOpen()) {
            this.close();
        }
    };

    handleClose = () => {
        this.close();
    };

    handleShow = () => {
        this.transition = 'showing';
    };

    handleShown = () => {
        this.transition = null;

        if (this.queued === 'close') {
            this.queued = null;
            this.modal.hide();
            return;
        }

        this.claimHistory();
        this.focusDialog();
    };

    handleHide = () => {
        this.transition = 'hiding';
    };

    handleHidden = () => {
        this.transition = null;
        document.body.classList.remove('modal-open');

        // A new link was followed while the modal was closing
        if (this.queued === 'open') {
            this.queued = null;

            // Still waiting for the content: drop the old one, show the spinner
            if (this.pendingOpen) {
                this.frameTarget.innerHTML = '';
                this.showLoading();
            } else {
                this.open();
            }
            return;
        }

        this.frameTarget.innerHTML = '';
        this.element.removeAttribute('aria-labelledby');
        this.setLayout(null);
        this.stopLoading();

        // Closed with the close button, Escape, the backdrop or a stream: take the modal's history entry back out
        if (this.historyEntry) {
            this.historyEntry = false;
            this.leavingHistoryEntry = true;
            history.back();
        }

        // Return focus to whatever opened the modal (unless the page changed meanwhile)
        const trigger = this.trigger;
        this.trigger = null;
        if (trigger && trigger.isConnected && typeof trigger.focus === 'function') {
            trigger.focus({ preventScroll: true });
        }
    };

    showLoading = () => {
        clearTimeout(this.loadingTimer);
        this.loadingTimer = null;

        // Content already arrived, or the request was abandoned
        if (!this.pendingOpen || this.isOpen()) {
            return;
        }

        if (this.transition === 'hiding') {
            this.queued = 'open';
            return;
        }

        if (!this.hasLoadingTarget) {
            return;
        }

        this.setSize(null);
        this.setLayout(null);
        this.element.removeAttribute('aria-labelledby');
        this.element.setAttribute('aria-busy', 'true');
        this.loadingTarget.hidden = false;
        this.modal.show();
    };

    stopLoading() {
        clearTimeout(this.loadingTimer);
        this.loadingTimer = null;
        this.element.removeAttribute('aria-busy');

        if (this.hasLoadingTarget) {
            this.loadingTarget.hidden = true;
        }
    }

    isLoading() {
        return this.hasLoadingTarget && !this.loadingTarget.hidden;
    }

    open() {
        // Don't open modal if frame is empty (safety check for edge cases)
        if (this.frameTarget.innerHTML.trim() === '') {
            return;
        }

        // Dynamic modal size and layout: check first child for data-modal-size
        const sizeEl = this.frameTarget.querySelector('[data-modal-size]');
        this.setSize(sizeEl ? sizeEl.dataset.modalSize : null);
        this.setLayout(sizeEl);

        // Name the dialog after the content's title
        const title = this.frameTarget.querySelector('.modal-title');
        if (title) {
            if (!title.id) {
                title.id = TITLE_ID;
            }
            this.element.setAttribute('aria-labelledby', title.id);
        } else {
            this.element.removeAttribute('aria-labelledby');
        }

        if (this.transition === 'hiding') {
            this.queued = 'open';
            return;
        }

        this.queued = null;

        if (this.isOpen()) {
            // Spinner swapped for the content, or the content re-rendered (form with errors):
            // when the focused element was replaced, bring focus back into the dialog
            if (this.transition === null && !this.element.contains(document.activeElement)) {
                this.focusDialog();
            }
            return;
        }

        this.modal.show();
    }

    close() {
        this.pendingOpen = false;
        this.stopLoading();

        if (this.transition === 'showing') {
            this.queued = 'close';
            return;
        }

        this.queued = null;
        this.modal.hide();
        // Content cleanup and focus return deferred to handleHidden (after animation)
    }

    isOpen() {
        return this.element.classList.contains('show');
    }

    setSize(size) {
        const dialog = this.element.querySelector('.modal-dialog');
        dialog.classList.remove('modal-sm', 'modal-lg', 'modal-xl');
        if (size) {
            dialog.classList.add(size);
        }
    }

    setLayout(contentRoot) {
        const dialog = this.element.querySelector('.modal-dialog');
        dialog.classList.toggle('modal-dialog-scrollable', contentRoot !== null && contentRoot.hasAttribute('data-modal-scrollable'));
        dialog.classList.toggle('modal-sheet-sm-down', contentRoot !== null && contentRoot.hasAttribute('data-modal-sheet'));
    }

    handlePopState = () => {
        // Our own history.back() after the modal was closed - Turbo listens again from here on
        if (this.leavingHistoryEntry) {
            this.leavingHistoryEntry = false;
            this.resumeTurboHistory();
            return;
        }

        // Back button / back gesture while the modal is open: the entry is gone already, just close
        if (this.historyEntry) {
            this.historyEntry = false;
            this.resumeTurboHistory();
            this.close();
        }
    };

    handleBeforeVisit = () => {
        // A link from inside the modal leaves the page: Turbo needs its history back for the new page,
        // and starting it again replaces the modal's entry with a normal entry of this page
        if (this.historyEntry) {
            this.historyEntry = false;
            this.resumeTurboHistory();
        }
    };

    claimHistory() {
        const contentRoot = this.frameTarget.querySelector('[data-modal-size]');

        if (this.historyEntry || contentRoot === null || !contentRoot.hasAttribute('data-modal-history')) {
            return;
        }

        const turboHistory = window.Turbo?.session?.history;
        if (!turboHistory || typeof turboHistory.stop !== 'function') {
            return;
        }

        turboHistory.stop();
        history.pushState({ dynamicModal: true }, '', window.location.href);
        this.historyEntry = true;
    }

    releaseHistory() {
        if (this.historyEntry) {
            this.historyEntry = false;
            this.resumeTurboHistory();
        }
    }

    resumeTurboHistory() {
        const turboHistory = window.Turbo?.session?.history;
        if (turboHistory && typeof turboHistory.start === 'function') {
            turboHistory.start();
        }
    }

    focusDialog() {
        // The dialog itself (tabindex="-1", labelled by the title) - screen readers announce it,
        // the next Tab reaches the close button
        this.element.focus({ preventScroll: true });
    }

    findTrigger() {
        if (this.lastClicked && this.lastClicked.isConnected && Date.now() - this.lastClickedAt < TRIGGER_CLICK_WINDOW_MS) {
            return this.lastClicked;
        }

        const active = document.activeElement;
        return active && active !== document.body && !this.element.contains(active) ? active : null;
    }

    visitFrameUrl() {
        // Turbo sets src to the requested URL (or the redirect target)
        const url = this.frameTarget.src;
        this.trigger = null;
        this.close();

        if (!url) {
            return;
        }

        if (window.Turbo) {
            window.Turbo.visit(url);
        } else {
            window.location.assign(url);
        }
    }
}
