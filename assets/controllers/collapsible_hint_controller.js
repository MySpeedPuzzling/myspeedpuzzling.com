import { Controller } from '@hotwired/stimulus';

/**
 * A hint that can be hidden and opened again (docs/features/hint-dismissing.md "Collapsible hints"): a <details>
 * open until the reader hides it, then only its summary line stays - a tap opens it again.
 *
 * Signed in: hiding stores the dismissal (dismiss_hint, the server renders it closed from then on). Guests: the
 * browser remembers it (localStorage), so the page stays the same for every guest.
 *
 *   <details open data-controller="collapsible-hint" data-collapsible-hint-url-value="…" data-collapsible-hint-type-value="…"
 *            data-collapsible-hint-signed-in-value="true|false">
 *     <summary>…</summary> … <button data-action="collapsible-hint#hide">…</button>
 *   </details>
 */
export default class extends Controller {
    static values = {
        url: String,
        type: String,
        signedIn: Boolean,
    };

    connect() {
        if (!this.signedInValue && this.storedHidden()) {
            this.element.open = false;
        }
    }

    hide() {
        this.element.open = false;
        this.element.querySelector('summary')?.focus();

        if (this.signedInValue) {
            fetch(this.urlValue, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: `type=${encodeURIComponent(this.typeValue)}`,
            });

            return;
        }

        try {
            window.localStorage.setItem(this.storageKey(), '1');
        } catch {
            // Private mode or blocked storage: it stays hidden for this page view only
        }
    }

    storedHidden() {
        try {
            return window.localStorage.getItem(this.storageKey()) === '1';
        } catch {
            return false;
        }
    }

    storageKey() {
        return `hint-hidden:${this.typeValue}`;
    }
}
