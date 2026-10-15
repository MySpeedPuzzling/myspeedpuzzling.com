/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus';
import { BEFORE_CACHE_EVENT, clearSnapshots, notePost } from '../instant_back.js';

/**
 * The follow star of the events page and the detail pages' headers (docs/features/events-page/README.md, "Follow";
 * templates/event_parts/_follow_star.html.twig) - the labelled variant (`.ev-star-labelled`) flips its visible text.
 *
 * Signed in (on the star's <form>): the follow / unfollow is posted with fetch (Accept: application/json; the stateless
 * CSRF token from data-token) and every star of the same target on the page flips at once - optimistically, put back
 * when the answer is not a success ("That didn't work. Please try again." under the row). The form itself is a GET form
 * to the one-tap action's confirmation view (GEST-15, decision 99: no POST form on a page Turbo may restore from its
 * cache) - without JavaScript that view's single button posts it and the server redirects back with a flash.
 *
 * The form's action is always the confirmation view of what a submit would do now (data-follow-view /
 * data-unfollow-view): a submit before this controller connected - a page restored from Turbo's cache and tapped at once -
 * opens that view, never the POST-only route (Instant Back review F6).
 *
 * Guests (on the star's sign-in link): the tap shows "Sign in to follow events and series." under the row, with a link
 * to sign in and come back; a second tap hides it - also a note a restored page still shows. Without JavaScript the star
 * is that sign-in link. A note never goes into Turbo's copy of the page (`msp:before-cache`, Instant Back review F7).
 */
const NOTE_CLASS = 'ev-follow-note';
const pending = new Set();

const rowOf = (element) => element.closest('.ev-row, .ev-series-line, .ev-detail-actions, li') ?? element.parentElement;

// Under the row's text, not in the narrow column of the star; on a detail page under the header's actions
const noteHostOf = (row) => (row.matches('.ev-detail-actions') ? row : row.querySelector('.ev-row-body, .ev-series-main') ?? row);

const currentUrl = () => window.location.pathname + window.location.search;

export default class extends Controller {
    connect() {
        this.onBeforeCache = () => this.removeNote();
    }

    disconnect() {
        this.removeNote();
    }

    async submit(event) {
        // Before Turbo sees it: this form never navigates
        event.preventDefault();

        const form = this.element;
        const button = form.querySelector('[data-follow-target]');

        if (button === null) {
            return;
        }

        const target = button.dataset.followTarget;

        if (pending.has(target)) {
            return;
        }

        const following = button.getAttribute('aria-pressed') !== 'true';
        const url = following ? form.dataset.followUrl : form.dataset.unfollowUrl;

        this.removeNote();
        pending.add(target);
        this.flip(target, following);

        let ok = false;

        try {
            const body = new FormData(form);
            body.set('return', currentUrl());
            body.delete('back');

            if (form.dataset.token) {
                body.set('_token', form.dataset.token);
            }

            const response = await fetch(url, {
                method: 'POST',
                body,
                credentials: 'same-origin',
                headers: { Accept: 'application/json' },
            });

            if (response.ok) {
                const data = await response.json();
                ok = data.following === following;
                // GEST-15 (decision 65): a page restored by Back never shows the star before the tap
                clearSnapshots();
                notePost();
            }
        } catch {
            ok = false;
        } finally {
            pending.delete(target);
        }

        if (!ok) {
            this.flip(target, !following);
            this.showNote(this.textNote(form.dataset.error));
        }

        if (button.isConnected) {
            button.focus({ preventScroll: true });
        }
    }

    guest(event) {
        const note = this.signInNote();

        // No note on this page: the link signs in
        if (note === null || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
            return;
        }

        event.preventDefault();

        // Shown by this controller, or still on a page Turbo restored (its note is no stranger: a second tap hides it)
        const shown = this.note && this.note.isConnected ? this.note : noteHostOf(rowOf(this.element)).querySelector(`:scope > .${NOTE_CLASS}`);

        if (shown) {
            this.note = shown;
            this.removeNote();
            return;
        }

        this.showNote(note);
        this.element.setAttribute('aria-expanded', 'true');
    }

    // Every star of the target on the page: rows, the series directory, search results
    flip(target, following) {
        document.querySelectorAll('button[data-follow-target]').forEach((star) => {
            if (star.dataset.followTarget !== target) {
                return;
            }

            star.setAttribute('aria-pressed', following ? 'true' : 'false');
            star.title = following ? star.dataset.titleFollowing : star.dataset.titleFollow;

            // The icon-only star is named by its aria-label; the labelled one by its visible text
            if (star.dataset.labelFollow !== undefined) {
                star.setAttribute('aria-label', following ? star.dataset.labelFollowing : star.dataset.labelFollow);
            }

            const text = star.querySelector('[data-follow-text]');

            if (text !== null) {
                text.textContent = following ? star.dataset.textFollowing : star.dataset.textFollow;
            }

            // The confirmation view of what the next submit does (a GET route - F6), never the POST route itself
            const form = star.closest('form');
            const view = form === null ? undefined : following ? form.dataset.unfollowView : form.dataset.followView;

            if (view) {
                form.action = view;
            }
        });
    }

    signInNote() {
        const template = document.querySelector('template[data-ev-signin-note-template]');
        const note = template?.content.firstElementChild?.cloneNode(true);

        if (!note) {
            return null;
        }

        // The page's URL may have changed since it was rendered (scope, search, calendar)
        const link = note.querySelector('a[href]');

        if (link !== null) {
            const loginUrl = new URL(link.href, window.location.origin);
            loginUrl.searchParams.set('return', currentUrl());
            link.href = loginUrl.pathname + loginUrl.search;
        }

        return note;
    }

    textNote(text) {
        const note = document.createElement('p');
        note.className = 'ev-signin-note';
        note.setAttribute('role', 'alert');
        note.textContent = text || '';

        return note;
    }

    showNote(note) {
        this.removeNote();
        note.classList.add(NOTE_CLASS);
        noteHostOf(rowOf(this.element)).appendChild(note);
        this.note = note;
        document.addEventListener(BEFORE_CACHE_EVENT, this.onBeforeCache);
    }

    removeNote() {
        document.removeEventListener(BEFORE_CACHE_EVENT, this.onBeforeCache);
        this.note?.remove();
        this.note = null;

        if (this.element.hasAttribute('aria-expanded')) {
            this.element.setAttribute('aria-expanded', 'false');
        }
    }
}
