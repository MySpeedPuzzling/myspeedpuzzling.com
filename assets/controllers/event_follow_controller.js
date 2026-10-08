/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus';

/**
 * The follow star of the events page (docs/features/events-page/README.md, "Follow"; templates/events/_follow_star.html.twig).
 *
 * Signed in (on the star's <form>): the submit is sent with fetch (Accept: application/json) instead of a full page
 * post, and every star of the same target on the page flips at once - optimistically, put back when the answer is not
 * a success ("That didn't work. Please try again." under the row). Without JavaScript the form posts and the server
 * redirects back with a flash.
 *
 * Guests (on the star's sign-in link): the tap shows "Sign in to follow events and series." under the row, with a link
 * to sign in and come back; a second tap hides it. Without JavaScript the star is that sign-in link.
 */
const NOTE_CLASS = 'ev-follow-note';
const pending = new Set();

const rowOf = (element) => element.closest('.ev-row, .ev-series-line, li') ?? element.parentElement;

// Under the row's text, not in the narrow column of the star
const noteHostOf = (row) => row.querySelector('.ev-row-body, .ev-series-main') ?? row;

const currentUrl = () => window.location.pathname + window.location.search;

export default class extends Controller {
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

            const response = await fetch(url, {
                method: 'POST',
                body,
                credentials: 'same-origin',
                headers: { Accept: 'application/json' },
            });

            if (response.ok) {
                const data = await response.json();
                ok = data.following === following;
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

        if (this.note && this.note.isConnected) {
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
            star.setAttribute('aria-label', following ? star.dataset.labelFollowing : star.dataset.labelFollow);
            star.title = following ? star.dataset.titleFollowing : star.dataset.titleFollow;

            const form = star.closest('form');

            if (form !== null) {
                form.action = following ? form.dataset.unfollowUrl : form.dataset.followUrl;
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
    }

    removeNote() {
        this.note?.remove();
        this.note = null;

        if (this.element.hasAttribute('aria-expanded')) {
            this.element.setAttribute('aria-expanded', 'false');
        }
    }
}
