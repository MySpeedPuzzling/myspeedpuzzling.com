/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus';
import * as Turbo from '@hotwired/turbo';

/**
 * Answers of the import's screens that are shown in place (docs/features/data-import/pairing-screens.md "Answers in
 * place"): "Yes, this is it", "Keep it just for me", one answer for a pile, the chooser's taps, who a person of the
 * file is. Every `<form data-import-answer>` inside the element is sent by this script instead of the browser:
 *
 * - the tapped button shows it is working, and neither it nor the other buttons of its card can be tapped again
 *   until the answer is back - a second tap never sends a second answer;
 * - the server answers with Turbo Streams: the card, the pile or the screen as it stands after the answer. They are
 *   put in place; the page does not move. Focus goes to the next thing to answer - the first button of the card
 *   that moved up, the card itself where it stayed, the beginning of a new screen - so a keyboard or a screen reader
 *   is not left on a button that is gone;
 * - an answer that could not be saved - no connection, a timeout, an error page - changes nothing on the screen:
 *   the card stands as it was, its buttons work again, and one short line under them says so;
 * - when the server answers with a page instead (the redirect of a screen that is another one now), that page is
 *   shown - the one that was already fetched, not asked for twice.
 *
 * Answers are sent one after another, in the order they were tapped, so the screen never shows an older state over
 * a newer one. Without this script - or before it has loaded - the forms are plain forms: Turbo sends them and puts
 * the same streams in place; without any script the server redirects.
 */

const STREAM = 'text/vnd.turbo-stream.html';
const GIVE_UP_AFTER_MS = 20000;
// The site's fixed header: what is brought into view is not put under it
const HEADER_PX = 90;

export default class extends Controller {
    static values = {
        // "That did not save…" - the page's language
        failed: String,
        // "Saving…" - for a screen reader, on the tapped button
        working: String,
    };

    connect() {
        this.queue = Promise.resolve();
        // Cards an answer is on its way for (their ids), and whether an answer for the whole screen is
        this.waiting = new Set();
        this.screenWaits = false;

        this.onSubmit = (event) => this.submit(event);
        this.element.addEventListener('submit', this.onSubmit);
    }

    disconnect() {
        this.element.removeEventListener('submit', this.onSubmit);
    }

    submit(event) {
        const form = event.target;

        if (!(form instanceof HTMLFormElement) || !form.hasAttribute('data-import-answer')) {
            return;
        }

        // Ours: neither the browser nor Turbo sends it
        event.preventDefault();

        const scope = form.closest('[data-import-answers-scope]');
        const key = scope !== null && scope.id !== '' ? scope.id : null;

        if (this.screenWaits || (key === null && this.waiting.size > 0) || (key !== null && this.waiting.has(key))) {
            // The answer before is still on its way
            return;
        }

        const submitter = event.submitter instanceof HTMLElement ? event.submitter : null;
        const body = new URLSearchParams(new FormData(form));

        if (submitter !== null && submitter.getAttribute('name')) {
            body.append(submitter.getAttribute('name'), submitter.getAttribute('value') || '');
        }

        // Where focus goes when the card is gone: the one that moves up, else the one before
        const around = scope === null ? [] : [scope.nextElementSibling, scope.previousElementSibling]
            .filter((element) => element instanceof HTMLElement && element.id !== '')
            .map((element) => element.id);
        const held = this.hold(scope || form, submitter);

        if (key === null) {
            this.screenWaits = true;
        } else {
            this.waiting.add(key);
        }

        this.queue = this.queue
            .then(() => this.send(form.action, body))
            .then(async (answer) => {
                if (answer.kind === 'streams') {
                    Turbo.renderStreamMessage(answer.html);
                    await this.rendered();
                    // What the streams left where it was works again; what they replaced is gone anyway
                    held.letGo();
                    this.release(key);

                    try {
                        this.afterStreams(key, around);
                    } catch (error) {
                        // The answer is saved and shown: where focus went is not worth a "not saved"
                    }
                } else if (answer.kind === 'page') {
                    this.release(key);
                    Turbo.visit(answer.url, { action: 'replace', response: { statusCode: answer.status, responseHTML: answer.html, redirected: true } });
                } else {
                    this.release(key);
                    held.letGo();
                    this.sayFailed(scope || form, submitter);
                }
            })
            .catch(() => {
                this.release(key);
                held.letGo();
                this.sayFailed(scope || form, submitter);
            });
    }

    async send(url, body) {
        const abort = new AbortController();
        const timer = window.setTimeout(() => abort.abort(), GIVE_UP_AFTER_MS);

        try {
            const response = await fetch(url, {
                method: 'POST',
                body,
                credentials: 'same-origin',
                redirect: 'follow',
                signal: abort.signal,
                headers: { Accept: `${STREAM}, text/html, application/xhtml+xml` },
            });
            const type = response.headers.get('Content-Type') || '';

            if (response.ok && type.startsWith(STREAM)) {
                return { kind: 'streams', html: await response.text() };
            }

            if (response.ok && response.redirected && type.includes('html')) {
                return { kind: 'page', url: response.url, status: response.status, html: await response.text() };
            }

            return { kind: 'failed' };
        } catch (error) {
            return { kind: 'failed' };
        } finally {
            window.clearTimeout(timer);
        }
    }

    /**
     * The card (or the form) waits for its answer: its buttons are off, the tapped one turns. `letGo()` puts
     * everything back as it was.
     */
    hold(scope, submitter) {
        scope.querySelectorAll('[data-import-answers-failed]').forEach((line) => line.remove());

        const buttons = [...scope.querySelectorAll('button, input[type="submit"]')].filter((button) => !button.disabled);
        let spinner = null;

        scope.setAttribute('aria-busy', 'true');
        buttons.forEach((button) => {
            button.disabled = true;
        });

        if (submitter !== null) {
            spinner = document.createElement('span');
            spinner.className = 'spinner-border spinner-border-sm me-1';
            spinner.setAttribute('role', 'status');
            spinner.setAttribute('data-import-answers-spinner', '');

            if (this.workingValue !== '') {
                const text = document.createElement('span');
                text.className = 'visually-hidden';
                text.textContent = this.workingValue;
                spinner.append(text);
            }

            submitter.prepend(spinner);
        }

        return {
            letGo: () => {
                if (spinner !== null) {
                    spinner.remove();
                }

                scope.removeAttribute('aria-busy');
                buttons.forEach((button) => {
                    button.disabled = false;
                });
            },
        };
    }

    release(key) {
        if (key === null) {
            this.screenWaits = false;
        } else {
            this.waiting.delete(key);
        }
    }

    /**
     * Turbo puts a stream in place on the next frame (at once in a tab that is not looked at).
     */
    rendered() {
        return new Promise((resolve) => {
            const timer = window.setTimeout(resolve, 250);

            window.requestAnimationFrame(() => window.requestAnimationFrame(() => {
                window.clearTimeout(timer);
                resolve();
            }));
        });
    }

    afterStreams(key, around) {
        // Pictures the screen kept: shown (a morph takes their "loaded" off, app.js puts it back - once more for
        // the ones that were not morphed but inserted from the cache)
        this.element.querySelectorAll('img.lazy-img:not(.loaded)').forEach((img) => {
            if (img.complete && img.naturalWidth > 0) {
                img.classList.add('loaded');
            }
        });

        // Cards whose answers are still on their way stay switched off on a screen that was rendered again
        this.waiting.forEach((id) => {
            const card = document.getElementById(id);

            if (card !== null) {
                card.querySelectorAll('button, input[type="submit"]').forEach((button) => {
                    button.disabled = true;
                });
            }
        });

        // The screen in the element is another one now (the next puzzle of a round): the address says so
        const screen = this.element.querySelector('[data-import-answers-url]');

        if (screen !== null) {
            const url = new URL(screen.getAttribute('data-import-answers-url'), window.location.href);

            if (url.href !== window.location.href) {
                window.history.replaceState(window.history.state, '', url.href);
            }
        }

        // A new screen: its beginning is brought into view and focused
        const fresh = this.element.querySelector('[data-import-answers-show]');

        if (fresh !== null) {
            fresh.removeAttribute('data-import-answers-show');
            fresh.style.scrollMarginTop = `${HEADER_PX}px`;

            if (fresh.getBoundingClientRect().top < HEADER_PX) {
                fresh.scrollIntoView({ block: 'start' });
            }

            this.focus(fresh.querySelector('h1, h2') || fresh);

            return;
        }

        // The card where it stands, else the one that moved up, else the one before - its first button or link
        const ids = key === null ? [] : [key, ...around];

        for (const id of ids) {
            const card = document.getElementById(id);
            const action = card === null ? null : card.querySelector('button:not([disabled]), a[href]');

            if (action !== null) {
                action.focus({ preventScroll: true });

                return;
            }
        }

        // Nothing of the kind is left: the line that says what happened - its button, or the line itself
        const said = this.element.querySelector('#import-answer-said');

        if (said !== null && said.childElementCount > 0) {
            this.focus(said.querySelector('a.btn, button') || said);
        }
    }

    focus(element) {
        if (!(element instanceof HTMLElement)) {
            return;
        }

        if (!element.matches('a[href], button, input, select, textarea') && !element.hasAttribute('tabindex')) {
            element.setAttribute('tabindex', '-1');
        }

        element.focus({ preventScroll: true });
    }

    /**
     * The answer was not saved: one short line under the card's buttons, said to a screen reader, and focus back
     * on the button that was tapped.
     */
    sayFailed(scope, submitter) {
        if (!scope.isConnected) {
            return;
        }

        const line = document.createElement('div');
        line.className = 'alert alert-warning fs-sm py-2 mt-2 mb-0';
        line.setAttribute('role', 'alert');
        line.setAttribute('data-import-answers-failed', '');
        line.setAttribute('data-testid', 'import-answer-failed');
        line.textContent = this.failedValue;
        scope.append(line);

        if (submitter !== null && submitter.isConnected) {
            submitter.focus({ preventScroll: true });
        }
    }
}
