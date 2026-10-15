/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus';
import { renderStreamMessage } from '@hotwired/turbo';
import { haptic } from '../haptics.js';
import { clearSnapshots, notePost } from '../instant_back.js';
import layers from '../layer_history.js';
import { createPostAction, spaceActivation } from '../post_action.js';

const postAction = createPostAction({
    fetch: (...args) => window.fetch(...args),
    FormData: window.FormData,
    renderStream: (html) => renderStreamMessage(html),
    // A tap inside a layer (the Menu, a sheet) leaves it with a replace visit (GEST-01 (d))
    visit: (url, options) => layers.visitFromLayer(url, options),
    clearSnapshots: () => clearSnapshots(),
    notePost: () => notePost(),
    haptic: (name) => haptic(name),
    toast: (message) => document.dispatchEvent(new CustomEvent('toast:show', { detail: { message, type: 'error' } })),
    currentUrl: () => window.location.href,
});

/**
 * A one-tap action of a browse page (docs/features/navigation GEST-15, decision 99; assets/post_action.js): on a link
 * (`post_action_attributes()`), a plain tap posts the action at once; a modified click or the middle button opens the
 * link's confirmation view as a link does. On a GET form to the confirmation view (the Players country nudge), the
 * submit posts the form's fields the same way. Without this controller (no JavaScript, a new tab) the link or form opens
 * the view.
 *
 * The links were buttons (Instant Back review F9): role="button" (rendered), and Space taps them as it does a button
 * (spaceActivation()). The action that asks first (Block) carries this controller for Space only - no URL: its tap is
 * Turbo's, opening the confirmation view in the modal frame.
 */
export default class extends Controller {
    static values = {
        url: String,
        fields: Object,
        token: String,
        failed: String,
    };

    connect() {
        this.onActivate = (event) => this.activate(event);
        this.onKey = (event) => this.key(event);
        this.element.addEventListener(this.isForm() ? 'submit' : 'click', this.onActivate);

        if (!this.isForm()) {
            this.element.addEventListener('keydown', this.onKey);
            this.element.addEventListener('keyup', this.onKey);
        }
    }

    disconnect() {
        this.element.removeEventListener(this.isForm() ? 'submit' : 'click', this.onActivate);
        this.element.removeEventListener('keydown', this.onKey);
        this.element.removeEventListener('keyup', this.onKey);
    }

    isForm() {
        return this.element.tagName === 'FORM';
    }

    key(event) {
        const what = spaceActivation(event);

        if (what === null) {
            return;
        }

        // Space never scrolls the page from a button
        event.preventDefault();

        if (what === 'activate') {
            this.element.click();
        }
    }

    activate(event) {
        // Block: Turbo opens its confirmation view (this controller is there for Space only)
        if (!this.hasUrlValue) {
            return;
        }

        if (event.defaultPrevented || (!this.isForm() && (event.button > 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey))) {
            return;
        }

        // Turbo leaves a prevented click alone: the confirmation view is the way without JavaScript only. The click still
        // bubbles (a Bootstrap dropdown it sits in closes, as it did for the form's button)
        event.preventDefault();

        const formEntries = this.isForm() ? Array.from(new FormData(this.element, event.submitter || null).entries()) : [];

        this.element.setAttribute('aria-busy', 'true');

        postAction.run({
            key: this.urlValue + JSON.stringify(this.fieldsValue),
            url: this.urlValue,
            fields: this.fieldsValue,
            formEntries,
            token: this.tokenValue,
            failedText: this.failedValue,
        }).finally(() => {
            this.element.removeAttribute('aria-busy');
        });
    }
}
