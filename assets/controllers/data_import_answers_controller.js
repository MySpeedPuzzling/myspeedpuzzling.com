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
 *   put in place; the page does not move. What the player set on the screen and did not send - boxes unticked, the
 *   answer picked for a pile, folded parts opened - stays as they set it. Focus goes to the next thing to answer - the first button of the card
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
        // The card focus was put on last - where it returns when the line at the bottom is closed
        this.last = null;

        this.onSubmit = (event) => this.submit(event);
        this.element.addEventListener('submit', this.onSubmit);
    }

    disconnect() {
        this.element.removeEventListener('submit', this.onSubmit);
        document.documentElement.style.scrollPaddingBottom = '';
        document.documentElement.style.removeProperty('--import-said-height');
        document.body.style.paddingBottom = '';
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
        // The attribute, never the form's property of that name: these forms hold `<button name="action">`, and a
        // control's name shadows the property (it is then the button, not the address)
        const url = new URL((submitter && submitter.getAttribute('formaction')) || form.getAttribute('action') || '', window.location.href).href;
        const body = new URLSearchParams(new FormData(form));

        if (submitter !== null && submitter.getAttribute('name')) {
            body.append(submitter.getAttribute('name'), submitter.getAttribute('value') || '');
        }

        // Where focus goes when the card is gone: the one that moves up, else the one before
        const around = scope === null ? [] : [scope.nextElementSibling, scope.previousElementSibling]
            .filter((element) => element instanceof HTMLElement && element.id !== '')
            .map((element) => element.id);
        // The card an answer of the line brings back ("Undo the last one"): where focus goes then
        const returns = form.getAttribute('data-import-answers-returns');
        // The button that was tapped, by its name on the page: on a card that stays where it is focus returns to it
        this.used = submitter === null ? null : submitter.getAttribute('data-testid');
        const held = this.hold(scope || form, submitter);

        if (key === null) {
            this.screenWaits = true;
        } else {
            this.waiting.add(key);
        }

        this.queue = this.queue
            .then(() => this.send(url, body))
            .then(async (answer) => {
                if (answer.kind === 'streams') {
                    // What the player set on the screen and did not send - an answered card is the server's now
                    const chosen = this.chosen(key === null ? null : scope);
                    Turbo.renderStreamMessage(answer.html);
                    await this.rendered();
                    this.chooseAgain(chosen);
                    // What the streams left where it was works again; what they replaced is gone anyway
                    held.letGo();
                    this.release(key);

                    try {
                        this.afterStreams(key, key === null && returns ? [returns] : around);
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
        this.element.querySelectorAll('[data-import-answers-failed]').forEach((line) => line.remove());

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

    /**
     * What the player set on the screen and did not send yet: boxes unticked or ticked, the answer picked for a
     * pile, the folded parts they opened. A screen rendered again comes with the server's defaults - these are the
     * player's, and stay.
     */
    chosen(answered) {
        return {
            boxes: [...this.element.querySelectorAll('input[type="checkbox"], input[type="radio"]')]
                .filter((box) => (box.id !== '' || box.name !== '') && (answered === null || !answered.contains(box)))
                .map((box) => ({ key: this.boxKey(box), checked: box.checked })),
            opened: [...this.element.querySelectorAll('details[open][data-testid]')].map((part) => part.getAttribute('data-testid')),
        };
    }

    boxKey(box) {
        if (box.id !== '') {
            return `#${box.id}`;
        }

        // A name is only one of its card ("answer" of every person): the card's id is part of the key
        const card = box.closest('[data-import-answers-scope][id]');

        return `${card === null ? '' : card.id}|${box.type}|${box.name}|${box.value}`;
    }

    chooseAgain(chosen) {
        // Another screen took the place (the next twenty, the next puzzle): it starts as the server rendered it
        if (this.element.querySelector('[data-import-answers-show]') !== null) {
            return;
        }

        const before = new Map(chosen.boxes.map((box) => [box.key, box.checked]));

        this.element.querySelectorAll('input[type="checkbox"], input[type="radio"]').forEach((box) => {
            const checked = before.get(this.boxKey(box));

            if (checked !== undefined && box.checked !== checked) {
                box.checked = checked;
                // Whoever counts the boxes counts again
                box.dispatchEvent(new Event('input', { bubbles: true }));
                box.dispatchEvent(new Event('change', { bubbles: true }));
            }
        });

        this.element.querySelectorAll('details[data-testid]:not([open])').forEach((part) => {
            if (chosen.opened.includes(part.getAttribute('data-testid'))) {
                part.setAttribute('open', '');
            }
        });
    }

    /**
     * "Close" on the line that stays in view, "No" on a question asked in place: the part goes, nothing is sent.
     */
    dismiss(event) {
        const part = event.currentTarget.closest('[data-import-answers-dismissable]');

        if (part === null) {
            return;
        }

        event.preventDefault();

        const line = part.closest('#import-answer-said') !== null;

        part.remove();
        this.address();
        this.room();

        if (!line) {
            this.focus(this.element.querySelector('#import-chooser h1, #import-chooser h2'));

            return;
        }

        // The line is closed: focus goes back to what is answered next - the card focus was on, else the first
        // thing on the screen that can be answered
        const card = this.last ? document.getElementById(this.last) : null;
        const next = (card === null ? null : card.querySelector('button:not([disabled]), a[href]'))
            || this.element.querySelector('[data-import-answers-scope] button:not([disabled]), form[data-import-answer] button:not([disabled])');

        if (next !== null) {
            // Never focus on what is not in view: Enter would answer a card nobody sees
            // ... and never move anything when it is in view already (closing the line is not an answer)
            if (!this.inView(next)) {
                this.reveal(next.closest('[data-import-answers-scope]') || next, false);
            }

            next.focus({ preventScroll: true });
        }
    }

    /**
     * Typing in a field that belongs to one answer picks that answer ("It is the player with this code:" - the
     * typed code is never sent beside another answer).
     */
    pick(event) {
        const field = event.currentTarget;
        const form = field.closest('form');
        const answer = form === null ? null : form.querySelector(`input[type="radio"][value="${field.getAttribute('data-import-answers-picks')}"]`);

        if (answer !== null && field.value.trim() !== '') {
            answer.checked = true;
        }
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

        this.address();
        this.afterAddress(key, around);
    }

    /**
     * The screen in the element may be another one now (the next puzzle of a round, the next twenty): the address
     * says which, so loading the page again shows it.
     */
    address() {
        const screen = this.element.querySelector('[data-import-answers-url]');

        if (screen !== null) {
            const url = new URL(screen.getAttribute('data-import-answers-url'), window.location.href);

            if (url.href !== window.location.href) {
                // Through Turbo's own history where it has one, so Turbo knows the address too
                const history = Turbo.navigator ? Turbo.navigator.history : null;

                if (history && typeof history.replace === 'function') {
                    history.replace(url);
                } else {
                    window.history.replaceState(window.history.state, '', url.href);
                }
            }
        }

    }

    afterAddress(key, around) {
        this.room();

        // A new screen: its beginning is brought into view, below the site's header
        const fresh = this.element.querySelector('[data-import-answers-show]');

        if (fresh !== null) {
            fresh.removeAttribute('data-import-answers-show');
            this.reveal(fresh, true);

            // A screen that only says what was done and where to go ("Nothing left here", the step is done): its
            // ONE way on is focused - else the screen's heading
            const ended = fresh.querySelector('[data-testid="import-pile-nothing-left"], [data-testid="import-step-done"]') !== null;
            const wayOn = ended ? fresh.querySelector('a.btn-primary') : null;
            this.focus(wayOn || fresh.querySelector('[role="alert"]') || fresh.querySelector('h1, h2') || fresh);
            this.last = null;

            return;
        }

        // The card where it stands, else the one that moved up, else the one before
        const ids = key === null ? around : [key, ...around];

        for (const id of ids) {
            const card = document.getElementById(id);

            if (card === null) {
                continue;
            }

            // An answer that was not taken says why on the card: that is shown, below the header, and focused
            const attention = card.querySelector('[data-import-answers-attention]');

            if (attention !== null) {
                this.reveal(attention, false);
                this.focus(attention);
                this.last = id;

                return;
            }

            // A card that stayed where it is (a person, a card of a list): the button that was used, and nothing
            // moves when it is in view - the player is looking at it. A card that moved up or came back: its first
            // button, and the whole card - what the button answers must be seen, not only the button
            const stayed = id === key;
            const same = stayed && this.used ? card.querySelector(`[data-testid="${this.used}"]:not([disabled])`) : null;
            const action = same || card.querySelector('button:not([disabled]), a[href]');

            if (action !== null) {
                this.reveal(stayed ? action : card, false);
                action.focus({ preventScroll: true });
                this.last = id;

                return;
            }
        }

        // No card is left on the screen: what the screen says now - "Nothing left here" and its ONE way on - is
        // brought into view (the page got shorter under the player) and the way on is focused
        const wayOn = this.element.querySelector('#import-answer-said a.btn-primary, #import-pile a.btn-primary, #import-pile button.btn-primary:not([disabled])');

        if (key !== null && wayOn !== null) {
            // From the screen's beginning (below the header) when the way on is then in view - else the way on itself
            this.reveal(this.element.querySelector('#import-pile') || wayOn, true);
            this.reveal(wayOn, false);
            wayOn.focus({ preventScroll: true });
            this.last = null;

            return;
        }

        // Nothing of the kind is left: the line that says what happened - its button, or the line itself
        const said = this.element.querySelector('#import-answer-said');

        if (said !== null && said.childElementCount > 0) {
            this.focus(said.querySelector('a.btn, button') || said);
        }
    }

    /**
     * The line that stays in view lies over the bottom of the screen: the page keeps that much room free - for
     * the browser's own scrolling (`scroll-padding-bottom`) and under the screen's last element.
     */
    room() {
        const said = this.element.querySelector('#import-answer-said');
        const height = said === null || said.childElementCount === 0 ? 0 : Math.ceil(said.getBoundingClientRect().height);

        document.documentElement.style.scrollPaddingBottom = height > 0 ? `${height + 8}px` : '';
        // The line is fixed to the window: the page itself is that much longer, so its end (the footer's links) is
        // never under the line - and a bar that sticks to the bottom of the screen (the grid's) stands on it
        document.body.style.paddingBottom = height > 0 ? `${height}px` : '';
        document.documentElement.style.setProperty('--import-said-height', `${height}px`);

        return height;
    }

    inView(element) {
        const header = parseFloat(window.getComputedStyle(document.documentElement).getPropertyValue('--header-height')) || HEADER_PX;
        const box = element.getBoundingClientRect();

        return box.top >= header && box.bottom <= window.innerHeight - this.room();
    }

    /**
     * The element (a whole card, not only its button) is in view - between the bottom of the site's header and the
     * top of the line at the bottom. Its top is put just below the header when it stands above; when it stands
     * below, the page moves up only as far as the top stays below the header - so an element taller than the room
     * shows its beginning (the puzzle's name and picture), and its buttons are a scroll away. Nothing moves when it
     * already fits. `toTop`: a new screen is shown from its beginning.
     */
    reveal(element, toTop) {
        const header = parseFloat(window.getComputedStyle(document.documentElement).getPropertyValue('--header-height')) || HEADER_PX;
        const top = header + 20;
        const bottom = window.innerHeight - this.room() - 8;
        const box = element.getBoundingClientRect();

        if (box.top < top) {
            window.scrollBy({ top: box.top - top, behavior: 'auto' });
        } else if (!toTop && box.bottom > bottom) {
            window.scrollBy({ top: Math.min(box.bottom - bottom, box.top - top), behavior: 'auto' });
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

        const line = (className) => {
            const element = document.createElement('div');
            element.className = className;
            element.setAttribute('data-import-answers-failed', '');
            element.setAttribute('data-testid', 'import-answer-failed');
            element.textContent = this.failedValue;

            return element;
        };
        const said = this.element.querySelector('#import-answer-said');

        if (said !== null && said.contains(scope)) {
            // "Undo the last one" itself failed: said beside it - the button stays, to be tapped again
            const inLine = line('alert alert-warning fs-sm py-2 mt-2 mb-0');
            inLine.setAttribute('role', 'alert');
            scope.append(inLine);
        } else {
            // In the line that stays in view - instead of what the answer before did, until the next answer - so it
            // is seen wherever the card is; and a card keeps its own line under its buttons
            if (said !== null) {
                const inView = line('bg-body border-top border-warning py-2 px-3 fs-sm fw-medium text-center');
                inView.style.pointerEvents = 'auto';
                inView.setAttribute('role', 'alert');
                said.replaceChildren(inView);
            }

            if (scope.hasAttribute('data-import-answers-scope')) {
                scope.append(line('alert alert-warning fs-sm py-2 mt-2 mb-0'));
            }
        }

        this.room();

        if (submitter !== null && submitter.isConnected) {
            // The tapped button, wholly above the line - and focus back on it
            this.reveal(submitter.closest('[data-import-answers-scope]') || submitter, false);
            submitter.focus({ preventScroll: true });
        }
    }
}
