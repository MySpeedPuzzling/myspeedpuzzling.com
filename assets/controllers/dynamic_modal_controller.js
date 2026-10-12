import { Controller } from '@hotwired/stimulus';
import { Modal } from 'bootstrap';
import layers from '../layer_history.js';

// The modal's id in assets/layer_history.js
const LAYER = 'modal';

// Turbo's own test of an HTML response (FetchResponse#isHTML)
const HTML_CONTENT_TYPE = /^(?:text\/([^\s;,]+\b)?html|application\/xhtml\+xml)\b/;

// A Turbo Stream answer (StreamMessage.contentType) - it also passes the HTML test above
const STREAM_CONTENT_TYPE = /^text\/vnd\.turbo-stream\.html\b/;

// Content that names a page to go to when a form submitted from it is answered by something else than modal content -
// the session ended meanwhile and the firewall sent the POST to sign in (the Help sheet, HELP-05 (d))
const FALLBACK_URL_ATTRIBUTE = 'data-modal-fallback-url';

// Inside a form of the content: shown when its send is answered by a server error or lost on the way - the modal and
// what was typed stay (the Help sheet's feedback box, HELP review F2)
const SUBMIT_ERROR = '[data-modal-submit-error]';

// A Turbo Stream answer that never rendered (no <turbo-stream> in it): the modal it left empty closes after this
const STREAM_SETTLE_MS = 3000;

// A response faster than this opens the modal straight with its content; a slower one shows a spinner first
const LOADING_DELAY_MS = 150;

// A click this recent is what started the frame request (focus goes back to it on close)
const TRIGGER_CLICK_WINDOW_MS = 1000;

// Given to a .modal-title without an id, so the dialog can be aria-labelledby it
const TITLE_ID = 'dynamic-modal-title';

// Bootstrap's instance of the container: the focus trap stays off (tom-select dropdowns render outside the dialog)
const DEFAULT_CONFIG = { focus: false };

// A trigger with data-modal-scrim="own" (every Help sheet opener, help_opener_attrs()) opens the modal without
// Bootstrap's backdrop: the controller draws its own scrim (HELP-01b, implementation plan §7)
const OWN_SCRIM = 'own';
const OWN_SCRIM_CONFIG = { focus: false, backdrop: false };
const SCRIM_CLASS = 'dynamic-modal-scrim';

// Bootstrap's backdrop fade (.fade, 150 ms): the own scrim fades the same way
const SCRIM_FADE_MS = 150;

// The sent Help sheet: on screen, but no longer blocking the page (release())
const RELEASED_CLASS = 'is-released';

// The Menu panel hands its opener over when this modal takes its history entry (menu_layer_controller.js)
const MENU_HANDOVER_EVENT = 'menu-layer:handover';

// Focus goes back here when the element that opened the modal is no longer shown (the ☰, NAV-03). The top bar renders
// two (NAV-04: desktop first, phones last) and shows one - the shown one takes the focus
const MENU_BUTTON = '[data-bar-action="menu"]';

function shownMenuButton() {
    return Array.from(document.querySelectorAll(MENU_BUTTON)).find((element) => element.getClientRects().length > 0) || null;
}

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
 * Network error while loading (nothing arrived, the frame is empty): the modal shows "Couldn't load this" with
 * Try again (the same URL once more) and Close, instead of disappearing - a link followed on a bad connection
 * otherwise looks like it did nothing. A form submitted from outside the frame cannot be repeated, so that one closes.
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
 * Back button / back gesture (data-modal-history on the same element): the open modal is a layer of
 * assets/layer_history.js (GEST-01) - one history entry of its own, taken when the content arrives, so Back closes it
 * instead of leaving the page. ×, Escape, the backdrop, a `modal:close`, a `bottom-sheet:dismiss` from the content
 * and an emptied frame then close it through history.back() (the modal hides in the helper's popstate); a page visit
 * from inside it replaces its entry (the helper marks it), so history never reads [page, page, next page].
 * A modal opened while another layer (the Menu) holds the entry takes that entry over (decision 58 D-1), with or
 * without data-modal-history. Without an entry the modal closes straight through Bootstrap, as before.
 *
 * Own scrim (the Help sheet only, HELP-01b, implementation plan §7, review F6/F7): a trigger carrying
 * data-modal-scrim="own" opens the modal without Bootstrap's backdrop - the instance is re-created through public API
 * (dispose() + new Modal(…, {backdrop: false})) right before it is shown, never while Bootstrap animates, and the default
 * instance comes back on hidden.bs.modal. The controller then draws its own scrim (.dynamic-modal-scrim, display: none
 * while closed, ending above the phone bottom bar) and closes on a tap outside the dialog itself (the .modal covers the
 * scrim, so the tap lands on the .modal). The content can ask for release() (`modal:release`, the Help sheet's sent
 * state): the scrim goes, taps reach the page through the .modal, the page scrolls again and the dialog is no longer
 * aria-modal - while ×, Esc, a swipe and Back still close it. New content that does not ask for the release again blocks
 * the page again (reclaim()): a modal-frame link on the page behind it (review F5), a link inside it (the puzzle's
 * "Suggest a correction" row of the sent sheet, HELP review F1), a form answered by anything but the sent state. Every
 * other modal-frame partial keeps Bootstrap's backdrop and outside-tap close.
 *
 * Focus on close: back to the trigger; a trigger no longer shown (the Menu row of a Menu → Help swap, back in the hidden
 * slot) gives way to what opened the Menu (handed over by menu_layer_controller.js), else the ☰ (review F7).
 *
 * Never a grey page without a modal (HELP-05, var/navigation-delivery/HELP-05/repro.md): the modal counts as open from
 * show.bs.modal on - Bootstrap locks the page and draws its backdrop at once but sets .show only after the 150 ms fade -
 * so an emptied frame, Esc, ×, a dismiss button or a tap outside during the opening animation queue a close (Bootstrap
 * itself ignores hide() then); a modal shown empty closes; a Turbo Stream answer ends the request (no endless spinner)
 * and closes the modal when it left it empty; and once no modal is shown, an orphan .modal-backdrop and Bootstrap's body
 * lock are swept away. Content may name a page for a form answered by something else than modal content
 * (data-modal-fallback-url, the Help sheet): a full visit there instead of an emptied, closed modal. A form holding a
 * [data-modal-submit-error] answered by a server error (5xx) or a network error keeps the modal with what was typed and
 * shows that error instead (HELP review F2). A Turbo Stream answer that renders nothing closes the empty modal after
 * STREAM_SETTLE_MS (review Q3). A dismiss button that also opens another Bootstrap modal (the "members only" buttons),
 * tapped while this one opens, opens that modal once this one has closed (review Q4).
 */
export default class extends Controller {
    static targets = ['frame', 'loading', 'error'];

    modal = null;
    observer = null;
    pendingOpen = false;
    loadingTimer = null;

    // The URL the frame is loading - Try again requests it once more
    requestedUrl = null;

    // Element that opened the modal - gets focus back when it closes
    trigger = null;
    lastClicked = null;
    lastClickedAt = 0;

    // 'showing' | 'hiding' while Bootstrap animates; Bootstrap ignores show()/hide() during a transition,
    // so a request made meanwhile is queued and replayed when the transition ends
    transition = null;
    queued = null;

    // Own scrim: what the current trigger asked for, what the Bootstrap instance is, the scrim element (created on
    // first use, next to the container), and whether the content released the modal
    wantOwnScrim = false;
    ownScrim = false;
    scrim = null;
    scrimTimer = null;
    released = false;
    downOnModal = false;

    // claimLayer() is taking the history entry over from another layer
    claiming = false;

    // The modal stays on screen until a page visit from it renders (visitFrameUrl, the fallback): never closed meanwhile
    leaving = false;

    // A form of the content was submitted from content naming a fallback page (data-modal-fallback-url)
    submissionFallbackUrl = null;

    // A Turbo Stream answered the modal's request: once it rendered, an emptied modal closes
    streamPending = false;
    streamTimer = null;

    // The submitted form's [data-modal-submit-error] (HELP review F2)
    submissionError = null;

    // The content asked for the release since the last request (HELP review F1): new content that did not stays blocking
    releaseRenewed = false;

    // A modal to open once this one has closed: the target of a dismiss-and-toggle button tapped while it opened (Q4)
    afterHidden = null;

    connect() {
        // Disable focus trap to allow interaction with tom-select dropdowns
        // that render outside the modal dialog
        this.modal = Modal.getOrCreateInstance(this.element, DEFAULT_CONFIG);

        // Track when a fetch starts (we want to open modal when content arrives)
        this.frameTarget.addEventListener('turbo:before-fetch-request', this.handleBeforeFetch);
        this.frameTarget.addEventListener('turbo:before-fetch-response', this.handleBeforeFetchResponse);
        this.frameTarget.addEventListener('turbo:fetch-request-error', this.handleFetchError);

        // A form outside the frame that targets it (data-turbo-frame="modal-frame", e.g. the collection selection bar):
        // Turbo fires the fetch events on that form, never on the frame
        document.addEventListener('turbo:before-fetch-request', this.handleExternalFormFetch);
        document.addEventListener('turbo:before-fetch-response', this.handleExternalFormResponse);
        document.addEventListener('turbo:fetch-request-error', this.handleExternalFormError);

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

        // A sheet's swipe down (bottom_sheet_controller.js, GEST-02) asks the host to close
        this.element.addEventListener('bottom-sheet:dismiss', this.handleClose);

        // Own scrim: a tap outside the dialog (Bootstrap's own outside click is off without its backdrop), and the
        // content asking to release the modal (help_sheet_controller.js, the sent state)
        this.element.addEventListener('mousedown', this.handleModalMouseDown);
        this.element.addEventListener('click', this.handleModalClick);
        this.element.addEventListener('modal:release', this.handleRelease);

        // ×, a dismiss button or a tap outside while the modal opens: queued instead of lost (HELP-05); a dismiss that
        // also opens another modal: before Bootstrap's delegated toggle (review Q4)
        this.element.addEventListener('click', this.handleTransitionClick, true);
        window.addEventListener('click', this.handleTransitionToggleClick, true);

        // A Turbo Stream that answered the modal's request has rendered (HELP-05)
        document.addEventListener('turbo:before-stream-render', this.handleBeforeStreamRender);

        document.addEventListener(MENU_HANDOVER_EVENT, this.handleMenuHandover);
    }

    disconnect() {
        clearTimeout(this.loadingTimer);
        this.frameTarget.removeEventListener('turbo:before-fetch-request', this.handleBeforeFetch);
        this.frameTarget.removeEventListener('turbo:before-fetch-response', this.handleBeforeFetchResponse);
        this.frameTarget.removeEventListener('turbo:fetch-request-error', this.handleFetchError);
        document.removeEventListener('turbo:before-fetch-request', this.handleExternalFormFetch);
        document.removeEventListener('turbo:before-fetch-response', this.handleExternalFormResponse);
        document.removeEventListener('turbo:fetch-request-error', this.handleExternalFormError);
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
        this.element.removeEventListener('bottom-sheet:dismiss', this.handleClose);
        this.element.removeEventListener('mousedown', this.handleModalMouseDown);
        this.element.removeEventListener('click', this.handleModalClick);
        this.element.removeEventListener('modal:release', this.handleRelease);
        this.element.removeEventListener('click', this.handleTransitionClick, true);
        window.removeEventListener('click', this.handleTransitionToggleClick, true);
        document.removeEventListener('turbo:before-stream-render', this.handleBeforeStreamRender);
        document.removeEventListener(MENU_HANDOVER_EVENT, this.handleMenuHandover);
        clearTimeout(this.scrimTimer);
        clearTimeout(this.streamTimer);
        this.scrim?.remove();
        this.scrim = null;
    }

    handleDocumentClick = (event) => {
        const clicked = event.target instanceof Element ? event.target.closest('a, button') : null;
        if (clicked) {
            this.lastClicked = clicked;
            this.lastClickedAt = Date.now();
        }
    };

    handleBeforeFetch = (event) => {
        // Mark that we want to open the modal when content arrives
        this.pendingOpen = true;
        this.leaving = false;
        this.streamPending = false;
        clearTimeout(this.streamTimer);
        this.releaseRenewed = false;
        this.hideError();

        // A form of the content submitted: the page its content names for an answer that is no modal content (HELP-05 (d))
        const form = event?.target instanceof HTMLFormElement && this.frameTarget.contains(event.target) ? event.target : null;
        const fallbackRoot = form !== null ? form.closest(`[${FALLBACK_URL_ATTRIBUTE}]`) : null;
        this.submissionFallbackUrl = fallbackRoot !== null ? fallbackRoot.getAttribute(FALLBACK_URL_ATTRIBUTE) : null;

        // Its error line for a failed send (HELP review F2): hidden again for the new try
        this.submissionError = form !== null ? form.querySelector(SUBMIT_ERROR) : null;

        if (this.submissionError !== null) {
            this.submissionError.hidden = true;
        }

        const url = event?.detail?.url;
        const method = String(event?.detail?.fetchOptions?.method ?? 'GET').toUpperCase();
        this.requestedUrl = url && method === 'GET' && !this.externalForm ? String(url) : null;
        this.element.setAttribute('aria-busy', 'true');

        // Navigation or form submission inside the already open modal - no spinner, keep the trigger
        if (this.isOpen()) {
            // New content for a released sheet blocks the page again at once (scrim, aria-modal, outside tap closes): a
            // modal-frame link on the page behind it (review F5) - focus goes back to that link - or a link inside it (the
            // "Suggest a correction" row of the sent sheet, HELP review F1) - focus keeps the sheet's opener. A form of the
            // sheet ("Send another") waits for its answer: the sent state stays released, anything else reclaims on load
            if (this.released) {
                const trigger = this.findTrigger();
                const outside = trigger !== null && !this.element.contains(trigger);

                if (outside || (method === 'GET' && form === null)) {
                    if (outside) {
                        this.trigger = trigger;
                    }

                    this.reclaim();
                }
            }

            return;
        }

        this.trigger = this.findTrigger();
        this.wantOwnScrim = this.trigger !== null && typeof this.trigger.getAttribute === 'function'
            && this.trigger.getAttribute('data-modal-scrim') === OWN_SCRIM;

        clearTimeout(this.loadingTimer);
        this.loadingTimer = setTimeout(this.showLoading, LOADING_DELAY_MS);
    };

    externalForm = null;

    handleExternalFormFetch = (event) => {
        const form = event.target;

        if (!(form instanceof HTMLFormElement) || this.frameTarget.contains(form)) {
            return;
        }

        if (event.detail?.fetchOptions?.headers?.['Turbo-Frame'] !== this.frameTarget.id) {
            return;
        }

        this.externalForm = form;
        this.handleBeforeFetch(null);
    };

    handleExternalFormResponse = (event) => {
        if (event.target === this.externalForm) {
            this.externalForm = null;
            this.handleBeforeFetchResponse(event);
        }
    };

    handleExternalFormError = (event) => {
        if (event.target === this.externalForm) {
            this.externalForm = null;
            this.handleFetchError();
        }
    };

    handleBeforeFetchResponse = (event) => {
        // Turbo Stream responses never fire turbo:frame-load, so the busy state ends here
        this.element.removeAttribute('aria-busy');

        const response = event?.detail?.fetchResponse ?? null;
        const stream = response !== null && STREAM_CONTENT_TYPE.test(String(response.contentType || ''));
        const fallbackUrl = this.submissionFallbackUrl;
        this.submissionFallbackUrl = null;
        const submitError = this.submissionError;
        this.submissionError = null;

        if (stream) {
            // The request ends here: no spinner left running, nothing left to open (repro step 3) - and a modal the stream
            // leaves empty closes once it rendered (handleBeforeStreamRender); a stream that renders nothing (no
            // <turbo-stream> in it) never gets there: the same check after STREAM_SETTLE_MS (review Q3)
            this.pendingOpen = false;
            this.stopLoading();
            this.streamPending = true;
            clearTimeout(this.streamTimer);
            this.streamTimer = setTimeout(this.settleAfterStream, STREAM_SETTLE_MS);

            return;
        }

        // A send answered by a server error (HELP review F2): its error page would replace the form - or, extending the
        // base layout, empty the frame and close the modal with the message silently lost. The modal stays with what
        // was typed and says the send failed; the next send tries again
        if (submitError !== null && response !== null && response.serverError) {
            event.preventDefault();
            this.pendingOpen = false;
            this.showSubmitError(submitError);

            return;
        }

        // A send from content naming a fallback page, answered by a redirect - the session ended and the firewall sent
        // the POST to sign in, whose page would empty the frame and close the modal with the message lost (HELP-05 (d)):
        // a full visit to that page instead (the help page with the same page attached, "Sign in to send feedback")
        if (fallbackUrl !== null && response !== null && response.redirected) {
            event.preventDefault();
            this.leaveFor(fallbackUrl);
        }
    };

    /**
     * After a Turbo Stream that answered the modal's request has rendered (Turbo renders streams on the next repaint, all
     * of one answer together): a modal left empty - no content, no spinner, no error - closes (HELP-05)
     */
    handleBeforeStreamRender = (event) => {
        const render = event?.detail?.render;

        if (!this.streamPending || typeof render !== 'function') {
            return;
        }

        event.detail.render = async (streamElement) => {
            await render(streamElement);
            clearTimeout(this.streamTimer);
            this.streamTimer = setTimeout(this.settleAfterStream, 0);
        };
    };

    settleAfterStream = () => {
        this.streamPending = false;

        if (this.isOpen() && this.isEmptyAndIdle()) {
            this.close();
        }
    };

    handleFetchError = () => {
        // Network error: nothing will arrive
        this.pendingOpen = false;
        this.stopLoading();
        const submitError = this.submissionError;
        this.submissionError = null;
        this.submissionFallbackUrl = null;

        // A send lost on the way (HELP review F2): the form and what was typed stay - say that it did not go
        if (submitError !== null && submitError.isConnected) {
            this.showSubmitError(submitError);
        }

        // Keep an open modal with a form in it (the visitor can submit again); when nothing arrived, say so and
        // offer Try again - closing would look like the tap did nothing. Without a URL to repeat: close.
        if (this.frameTarget.innerHTML.trim() === '') {
            if (this.requestedUrl && this.hasErrorTarget) {
                this.showError();
            } else {
                this.close();
            }
        }
    };

    showError() {
        this.setSize(null);
        this.setLayout(null);
        this.element.removeAttribute('aria-labelledby');
        this.errorTarget.hidden = false;

        if (this.transition === 'hiding') {
            this.queued = 'open';
            return;
        }

        if (this.isOpen()) {
            this.focusDialog();
        } else {
            this.showModal();
        }
    }

    hideError() {
        if (this.hasErrorTarget) {
            this.errorTarget.hidden = true;
        }
    }

    /**
     * The send did not go (HELP review F2): its error line (role="alert", announced) - the form and its text stay
     */
    showSubmitError(element) {
        element.hidden = false;

        if (typeof element.scrollIntoView === 'function') {
            element.scrollIntoView({ block: 'nearest' });
        }
    }

    retry() {
        if (!this.requestedUrl) {
            this.close();
            return;
        }

        const url = this.requestedUrl;
        this.hideError();
        // A changed src makes Turbo fetch again; the same src would be ignored
        this.frameTarget.removeAttribute('src');
        this.frameTarget.src = url;
    }

    dismiss() {
        this.close();
    }

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

        // New content in a released sheet that did not ask for the release again - a form back with an error, a page
        // loaded from inside it (HELP review F1): it blocks the page again like any modal. The sent state asks again
        // (help_sheet_controller.js connects before this event; if it ever connected later, its release() still wins)
        if (this.released && !this.releaseRenewed) {
            this.reclaim();
        }
    };

    handleFrameMissing = (event) => {
        // The response has no <turbo-frame id="modal-frame"> at all - instead of Turbo's "Content missing",
        // render the response itself as a full page (the visitor sees the real error page)
        event.preventDefault();
        this.pendingOpen = false;
        this.stopLoading();
        this.trigger = null;

        if (!layers.isOpen()) {
            this.closeUi();
            event.detail.visit(event.detail.response);

            return;
        }

        // A layer holds a history entry: the visit replaces it. Holding it itself, the modal stays until the page
        // renders; under another layer (the Menu) it never showed
        if (!layers.isOpen(LAYER)) {
            this.closeUi();
        }

        this.leaving = layers.isOpen(LAYER);
        this.visitResponseFromLayer(event.detail.response);
    };

    async visitResponseFromLayer(response) {
        // Turbo's own frame-missing visit (FrameController#visitResponse) as a replace visit, through the layer helper
        const location = response?.url || this.frameTarget.src;
        let responseHTML;

        try {
            if (HTML_CONTENT_TYPE.test(response.headers.get('Content-Type') || '')) {
                responseHTML = await response.clone().text();
            }
        } catch (e) {
            responseHTML = undefined;
        }

        if (!location) {
            return;
        }

        if (responseHTML === undefined) {
            layers.visitFromLayer(location);

            return;
        }

        layers.visitFromLayer(location, { response: { redirected: response.redirected, statusCode: response.status, responseHTML } });
    }

    handleMutation = () => {
        // Close modal if frame content is empty (cleared by Turbo Stream) - also while it is still opening (HELP-05)
        if (this.frameTarget.innerHTML.trim() === '' && this.isOpen() && !this.isLoading() && !this.leaving) {
            this.close();
        }
    };

    handleKeydown = (event) => {
        // A modal with a history entry: Escape is the layer helper's (one history.back())
        if (event.key === 'Escape' && this.isOpen() && !layers.isOpen(LAYER)) {
            this.close();
        }
    };

    handleClose = () => {
        this.close();
    };

    handleShow = () => {
        this.transition = 'showing';

        if (this.ownScrim) {
            this.showScrim();
        }

        // Opened while another layer (the Menu) holds the history entry: take it over before Bootstrap focuses
        // (decision 58 D-1) - the Menu closes and drops its inert
        if (layers.isOpen() && !layers.isOpen(LAYER)) {
            this.claimLayer();
        }
    };

    handleShown = () => {
        this.transition = null;

        if (this.queued === 'close') {
            this.queued = null;
            this.modal.hide();
            return;
        }

        // Never leave an empty modal open (HELP-05): its content went while it was opening - nothing to show, nothing to
        // wait for (a grey page with an empty dialog otherwise)
        if (this.isEmptyAndIdle()) {
            this.close();
            return;
        }

        this.focusDialog();
    };

    handleHide = (event) => {
        // ×, the backdrop or Bootstrap's Escape while the modal holds its entry: close through Back instead
        // (the helper's popstate hides it, no longer holding)
        if (layers.isOpen(LAYER)) {
            event.preventDefault();
            this.close();

            return;
        }

        this.transition = 'hiding';

        if (this.ownScrim) {
            this.hideScrim(true);
        }
    };

    handleHidden = () => {
        this.transition = null;
        this.leaving = false;
        const afterHidden = this.afterHidden;
        this.afterHidden = null;
        document.body.classList.remove('modal-open');

        // Nothing may keep the page grey or locked once no modal is shown (HELP-05 (c))
        this.sweepOrphans();

        // The own-scrim path ends here: scrim gone, the default instance (Bootstrap's backdrop) back for every other
        // modal - Bootstrap's hide is over, so the instance can be swapped (the next show() picks its trigger's again)
        this.hideScrim(false);
        this.unrelease();
        this.useInstance(false);

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
        this.hideError();
        this.requestedUrl = null;
        this.wantOwnScrim = false;

        // Return focus to whatever opened the modal (unless the page changed meanwhile). A trigger still in the page but
        // not shown - the Menu row the panel took back into its hidden slot (review F7) - gives way to the ☰
        let trigger = this.trigger;
        this.trigger = null;

        if (trigger && trigger.isConnected && typeof trigger.getClientRects === 'function' && trigger.getClientRects().length === 0) {
            trigger = shownMenuButton();
        }

        if (trigger && trigger.isConnected && typeof trigger.focus === 'function') {
            trigger.focus({ preventScroll: true });
        }

        // The modal a dismiss-and-toggle button asked for while this one opened (review Q4)
        if (afterHidden !== null) {
            this.showOtherModal(afterHidden);
        }
    };

    showOtherModal(selector) {
        let element = null;

        try {
            element = document.querySelector(selector);
        } catch (e) {
            element = null;
        }

        if (element !== null && element !== this.element && element.classList.contains('modal')) {
            Modal.getOrCreateInstance(element).show();
        }
    }

    /**
     * The Menu panel closed because this modal took its history entry (Send feedback → the Help sheet): the row that
     * opened the modal is going back into the hidden slot - focus returns to what opened the Menu instead (review F7)
     */
    handleMenuHandover = (event) => {
        const opener = event?.detail?.opener ?? null;
        const panel = event.target instanceof Element ? event.target : null;

        if (!this.claiming || !opener || !opener.isConnected) {
            return;
        }

        if (this.trigger === null || (panel !== null && panel.contains(this.trigger))) {
            this.trigger = opener;
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
        this.showModal();
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

    isErrorShown() {
        return this.hasErrorTarget && !this.errorTarget.hidden;
    }

    /**
     * Nothing to show and nothing coming: no content, no spinner, no error - and not staying for a page visit
     */
    isEmptyAndIdle() {
        return this.frameTarget.innerHTML.trim() === '' && !this.isLoading() && !this.isErrorShown() && !this.leaving;
    }

    /**
     * The defensive sweep (HELP-05 (c)): once no modal is shown, an orphan .modal-backdrop and Bootstrap's body lock
     * (modal-open, the inline overflow / padding-right) go - whatever left them behind
     */
    sweepOrphans() {
        if (document.querySelector('.modal.show') !== null) {
            return;
        }

        document.querySelectorAll('.modal-backdrop').forEach((backdrop) => backdrop.remove());
        document.body.classList.remove('modal-open');
        document.body.style.removeProperty('overflow');
        document.body.style.removeProperty('padding-right');
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

        // Content asking for Back (data-modal-history): the entry is taken when it arrives
        if (sizeEl !== null && sizeEl.hasAttribute('data-modal-history') && !layers.isOpen(LAYER)) {
            this.claimLayer();
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

        this.showModal();
    }

    close() {
        this.pendingOpen = false;
        this.stopLoading();

        // Holding a history entry: Back closes it (the helper calls onLayerClose in its popstate)
        if (layers.isOpen(LAYER)) {
            layers.requestClose(LAYER);
            return;
        }

        this.closeUi();
    }

    closeUi() {
        if (this.transition === 'showing') {
            this.queued = 'close';
            return;
        }

        this.queued = null;
        this.modal.hide();
        // Content cleanup and focus return deferred to handleHidden (after animation)
    }

    isOpen() {
        // From show.bs.modal on (HELP-05): Bootstrap locks the page and draws the backdrop at once, .show comes only after
        // the backdrop's fade - a request, an emptied frame or Esc in that window belongs to this modal
        return this.transition === 'showing' || this.element.classList.contains('show');
    }

    showModal() {
        this.useInstance(this.wantOwnScrim);
        this.modal.show();
    }

    /**
     * Bootstrap's instance with or without its backdrop, through public API only (no Bootstrap private API, review F7).
     * Never while Bootstrap animates: its queued callbacks belong to the old instance.
     */
    useInstance(ownScrim) {
        if (this.ownScrim === ownScrim || this.transition !== null) {
            return;
        }

        this.modal.dispose();
        this.modal = new Modal(this.element, ownScrim ? OWN_SCRIM_CONFIG : DEFAULT_CONFIG);
        this.ownScrim = ownScrim;
    }

    ensureScrim() {
        if (this.scrim === null || !this.scrim.isConnected) {
            this.scrim = document.createElement('div');
            this.scrim.className = SCRIM_CLASS;
            this.scrim.hidden = true;
            this.scrim.setAttribute('aria-hidden', 'true');
            this.element.before(this.scrim);
        }

        return this.scrim;
    }

    showScrim() {
        const scrim = this.ensureScrim();
        clearTimeout(this.scrimTimer);
        scrim.hidden = false;
        // Laid out once while transparent, so the fade in runs
        scrim.getBoundingClientRect();
        scrim.classList.add('show');
    }

    hideScrim(fade) {
        clearTimeout(this.scrimTimer);

        if (this.scrim === null) {
            return;
        }

        const scrim = this.scrim;
        scrim.classList.remove('show');

        if (!fade) {
            scrim.hidden = true;
            return;
        }

        this.scrimTimer = setTimeout(() => {
            if (!scrim.classList.contains('show')) {
                scrim.hidden = true;
            }
        }, SCRIM_FADE_MS);
    }

    handleModalMouseDown = (event) => {
        this.downOnModal = event.target === this.element;
    };

    /**
     * ×, a dismiss button or a tap outside the dialog while the modal is still opening (HELP-05): Bootstrap ignores hide()
     * during its transition and fires no hide.bs.modal, so the tap would be lost - close() queues it like Esc. Capture
     * phase: Bootstrap's own handlers never see it. The own scrim's outside tap is handleModalClick's.
     */
    handleTransitionClick = (event) => {
        if (this.transition !== 'showing') {
            return;
        }

        const target = event.target instanceof Element ? event.target : null;
        const dismiss = target !== null ? target.closest('[data-bs-dismiss="modal"]') : null;
        const outside = target === this.element && this.downOnModal && !this.ownScrim;

        if ((dismiss === null || !this.element.contains(dismiss)) && !outside) {
            return;
        }

        event.preventDefault();
        event.stopImmediatePropagation();
        this.downOnModal = false;
        this.close();
    };

    /**
     * A dismiss button that also opens another modal (data-bs-toggle="modal": the "members only" buttons of the
     * collection and sell/swap modals), tapped while this modal still opens (review Q4): Bootstrap's delegated toggle
     * (a capture listener on document, before handleTransitionClick) would show that modal over this one still opening,
     * and this one's hide later drops the page lock under it. Window capture runs first: this modal closes (queued like
     * any dismiss) and the other one opens once it is hidden (handleHidden).
     */
    handleTransitionToggleClick = (event) => {
        if (this.transition !== 'showing') {
            return;
        }

        const target = event.target instanceof Element ? event.target : null;
        const button = target !== null ? target.closest('[data-bs-dismiss="modal"][data-bs-toggle="modal"]') : null;

        if (button === null || !this.element.contains(button)) {
            return;
        }

        event.preventDefault();
        event.stopImmediatePropagation();
        this.afterHidden = button.getAttribute('data-bs-target') || null;
        this.close();
    };

    handleModalClick = (event) => {
        const outside = this.downOnModal && event.target === this.element;
        this.downOnModal = false;

        // Only the own-scrim instance: Bootstrap's backdrop instance closes on its own outside click
        if (outside && this.ownScrim && !this.released && this.isOpen()) {
            this.close();
        }
    };

    handleRelease = () => {
        this.releaseRenewed = true;
        this.release();
    };

    /**
     * The sent Help sheet (HELP-01b; HELP-05 "the page is usable without reload"): the confirmation stays in the
     * sheet, the page behind works again - no scrim, taps go through the .modal to the page (its dialog keeps them),
     * the page scrolls (Bootstrap's body class and inline styles go; it restores its own values on hide), and the
     * dialog is no longer aria-modal. ×, Esc, a swipe and Back still close it. Own-scrim modals only.
     */
    release() {
        if (!this.ownScrim || this.released || !this.isOpen()) {
            return;
        }

        this.released = true;
        this.hideScrim(true);
        this.element.classList.add(RELEASED_CLASS);
        this.element.removeAttribute('aria-modal');
        document.body.classList.remove('modal-open');
        document.body.style.removeProperty('overflow');
        document.body.style.removeProperty('padding-right');
    }

    unrelease() {
        this.released = false;
        this.element.classList.remove(RELEASED_CLASS);
    }

    /**
     * The released sheet blocks the page again (review F5: a modal-frame link on the page behind it loads new content
     * into it): the scrim, aria-modal, body.modal-open and the scroll lock come back, an outside tap closes it again
     */
    reclaim() {
        if (!this.released) {
            return;
        }

        this.unrelease();
        this.element.setAttribute('aria-modal', 'true');
        document.body.classList.add('modal-open');
        document.body.style.overflow = 'hidden';
        this.showScrim();
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

    claimLayer() {
        // While taking the entry over, the layer it swaps (the Menu panel) may hand its opener over (handleMenuHandover)
        this.claiming = true;

        try {
            layers.open(LAYER, { element: this.element, onClose: this.onLayerClose });
        } finally {
            this.claiming = false;
        }
    }

    onLayerClose = ({ reason }) => {
        // Leaving the page from inside the modal: it stays visible until the new page renders
        if (reason === 'leave') {
            return;
        }

        this.pendingOpen = false;
        this.stopLoading();
        this.closeUi();
    };

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

        // Holding a history entry, the modal stays until the page renders: the visit replaces the entry
        if (!url || !layers.isOpen(LAYER)) {
            this.close();
        }

        if (!url) {
            return;
        }

        this.leaving = layers.isOpen(LAYER);
        layers.visitFromLayer(url);
    }

    /**
     * A full visit to the page the content named (data-modal-fallback-url, HELP-05 (d)), like visitFrameUrl()
     */
    leaveFor(url) {
        this.pendingOpen = false;
        this.stopLoading();
        this.trigger = null;

        if (!layers.isOpen(LAYER)) {
            this.close();
        }

        this.leaving = layers.isOpen(LAYER);
        layers.visitFromLayer(url);
    }
}
