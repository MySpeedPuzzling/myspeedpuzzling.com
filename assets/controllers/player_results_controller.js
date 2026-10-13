/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus';
import { renderStreamMessage, visit } from '@hotwired/turbo';
import layers, { PAGE_STATE } from '../layer_history.js';
import { isNativeApp } from '../platform.js';
import { cameBackByFullLoad, isSnapshotRestore, SNAPSHOT_RESTORES } from '../restore_reload.js';
import {
    OWNER,
    TYPING_DEBOUNCE_MS,
    formStateFromUrl,
    historyAction,
    parseSavedScroll,
    restoreTarget,
    sameState,
    scrollKey,
    scrollRestore,
    streamApplies,
    takesOver,
    urlFromForm,
    withTypedQuery,
} from '../player_results_state.js';

const FRAME_ID = 'player-results';
const FOOTER_ID = 'player-results-more';
const FORM_ID = 'player-results-form';
const SEARCH_ID = 'player-results-q';
const ROWS = '#player-results-rows > tr[id]';
const STREAM_TYPE = 'text/vnd.turbo-stream.html';
// The stream's actions render after the next repaint (Turbo): the new footer is the sign they are done
const RENDER_TIMEOUT_MS = 5000;

// Links that show another list (tab, view, sort, a chip, Clear all): a new entry, the list loaded into its frame - and
// "Show from the start" above a page that leaves its first rows out (paging: the entry replaced)
const LIST_LINKS = 'a[data-results-category], a[data-results-view], a[data-results-sort], a[data-results-chip], a[data-results-clear], a[data-results-start]';

// Links that drop the search: a text still being typed goes with them no further (review F4)
const DROPS_SEARCH = 'a[data-results-chip="q"], a[data-results-clear]';

const TEXT_TYPES = ['text', 'search', 'number', 'email', 'tel', 'url'];

/**
 * A player's Results page (docs/features/navigation README §4.7, PROF-05b/05c). Everything works without it: every
 * control is a link to the list's URL and the filters are a GET form. With it the page owns its history entries for as
 * long as it is shown (assets/layer_history.js: pauseTurboHistory('results') on connect, resumeTurboHistory at
 * turbo:before-visit / disconnect - never Turbo's history directly):
 *
 * - a discrete change (tab, view, sort, a filter of the form, a chip, Clear all, a submit) loads only the list (the
 *   `player-results` frame) and then pushes an entry, typing in the search (300 ms after the last key) and Show more /
 *   Show all replace it - the push-or-replace rule is assets/player_results_state.js. The entry is written once the
 *   list has rendered, with the server's form of the address the frame states ([data-results-state]): the address
 *   never names a list the page does not show. The form outside the frame (hidden tab/view/sort, the fields, the
 *   filters' count, Clear all) follows the frame too.
 * - Back / Forward between the page's own entries (popstate with Turbo paused) loads that entry's list - no page load.
 *   A pop onto another page's entry is layer_history's: it gives Turbo its history back and restores that page.
 * - Show more (`a[data-results-more]`, `?…&page=N+1`): the same URL with `fragment=more` answers a Turbo Stream - the
 *   next rows appended, the footer replaced (PlayerResultsController). Show all reloads the list with every row. The
 *   stream belongs to the list and the page it was asked for (review F2): a newer change of the list, a visit or the
 *   page leaving aborts it, and an answer that arrives for a list no longer shown is dropped - it never appends rows
 *   to another list, never writes another page's history entry.
 * - A change while the search still waits for its 300 ms (review F4): the typed text goes with it - a click's address
 *   gets the typed `q` (one entry, pushed for a discrete change), a filter of the form sends it anyway; a link that
 *   drops the search (its chip, Clear all) drops it. Typing that ends while a change is loading continues that change.
 * - A list answer without the list (`turbo:frame-missing`: the player blocked or turned private meanwhile, a deploy's
 *   error page) becomes a full visit of what the server answered, never "Content missing" (review F5).
 * - Back from another page: the address says `?page=N`, so a full load renders the same rows; where the page was when it
 *   was left (sessionStorage `msp:results-scroll:<address>`, saved at turbo:before-visit and pagehide: scrollY and the
 *   row clicked / first in view) is restored only when a full load brought the visitor back (app.js's restore reload,
 *   a back/forward load - assets/restore_reload.js), never after a snapshot restore (GEST-15, which keeps the page
 *   and its scroll), never on a page reached by a visit.
 *
 * Inside the Hotwire Native app the page writes no history and takes nothing over (the app's own navigation decides);
 * Show more / Show all still load in place.
 *
 * GEST-15 hooks, inert until it turns snapshots on: isSnapshotRestore() (no saved position applied), SNAPSHOT_RESTORES
 * (Turbo's scroll record for the entry is refreshed when the page is left), turbo:before-cache (the date pickers and
 * the open sort menu torn down, nothing pending). The filters are a <details>: its open state is DOM, kept as it was.
 */
export default class extends Controller {
    connect() {
        this.loading = false;
        this.nativeApp = isNativeApp();
        this.owning = takesOver(this.nativeApp);
        this.typingTimer = null;
        this.restoreCleanup = null;
        this.pending = null;
        // Show more's request in flight: {controller (AbortController), from (the list it was asked for)}
        this.moreRequest = null;
        // A visit is leaving the page: nothing writes an entry any more (taken back when the visit is cancelled)
        this.leaving = false;
        this.lastRow = null;
        this.pathname = window.location.pathname;
        this.currentUrl = window.location.href;
        this.shownUrl = window.location.href;

        // Kept: at disconnect the id may already be another page's frame
        this.listFrame = this.frame();

        if (this.listFrame) {
            this.listFrame.addEventListener('turbo:frame-load', this.onFrameLoad);
            this.listFrame.addEventListener('turbo:fetch-request-error', this.onFrameError);
            this.listFrame.addEventListener('turbo:frame-missing', this.onFrameMissing);
        }

        this.element.addEventListener('click', this.onClick);

        if (!this.owning) {
            return;
        }

        // Decided before the entry is written: did a full load bring the visitor back to this address?
        const key = scrollKey(window.location.href);
        const saved = this.readSaved(key);
        const restore = scrollRestore({
            nativeApp: this.nativeApp,
            snapshotRestore: isSnapshotRestore(),
            cameBack: cameBackByFullLoad(window.location.href),
            saved,
        });

        // The page owns its entries from now on: this one first (Turbo's identity kept, GEST-01 (a))
        layers.pauseTurboHistory(OWNER);
        layers.replacePageState(OWNER, window.location.href);

        this.element.addEventListener('input', this.onInput);
        this.element.addEventListener('change', this.onChange);
        this.element.addEventListener('submit', this.onSubmit);
        window.addEventListener('popstate', this.onPopState);
        window.addEventListener('pagehide', this.onPageHide);
        document.addEventListener('turbo:before-visit', this.onBeforeVisit);
        document.addEventListener('turbo:before-cache', this.onBeforeCache);

        if (restore === 'restore') {
            this.restoreScroll(saved, key);
        }
    }

    disconnect() {
        if (this.listFrame) {
            this.listFrame.removeEventListener('turbo:frame-load', this.onFrameLoad);
            this.listFrame.removeEventListener('turbo:fetch-request-error', this.onFrameError);
            this.listFrame.removeEventListener('turbo:frame-missing', this.onFrameMissing);
            this.listFrame = null;
        }

        this.abortMore();

        this.element.removeEventListener('click', this.onClick);
        this.element.removeEventListener('input', this.onInput);
        this.element.removeEventListener('change', this.onChange);
        this.element.removeEventListener('submit', this.onSubmit);
        window.removeEventListener('popstate', this.onPopState);
        window.removeEventListener('pagehide', this.onPageHide);
        document.removeEventListener('turbo:before-visit', this.onBeforeVisit);
        document.removeEventListener('turbo:before-cache', this.onBeforeCache);
        clearTimeout(this.typingTimer);
        this.typingTimer = null;
        this.stopRestoring();

        // A Results page rendered in this one's place owns the entries under the same name already - never resumed for it
        const successor = document.querySelector('[data-controller~="player-results"]');

        if (this.owning && (!successor || successor === this.element)) {
            layers.resumeTurboHistory(OWNER);
        }
    }

    // ---- Show more / Show all (PROF-05b) ------------------------------------------------------------------------------

    async more(event) {
        const link = event.currentTarget;

        if (!this.plainClick(event)) {
            return;
        }

        event.preventDefault();

        if (this.loading) {
            return;
        }

        const url = new URL(link.href, window.location.href);
        const typed = this.takeTyping();

        // The search still waited (review F4): the typed text makes it another list - its rows up to the next page load
        // into the frame instead of being appended to this one
        if (typed !== null && !sameState(withTypedQuery(url.href, typed), url.href)) {
            this.change('more', withTypedQuery(url.href, typed));

            return;
        }

        const streamUrl = new URL(url.href);
        streamUrl.searchParams.set('fragment', 'more');

        const before = this.rowsCount();
        const footer = document.getElementById(FOOTER_ID);
        const request = { controller: new AbortController(), from: this.shownUrl };
        this.abortMore();
        this.moreRequest = request;
        this.busy(link, footer);

        // The answer is the list's and the page's it was asked for, nobody else's (review F2)
        const applies = () => streamApplies({
            aborted: request.controller.signal.aborted,
            connected: this.element.isConnected,
            footerConnected: !footer || footer.isConnected,
            pending: this.pending !== null,
            requestedFrom: request.from,
            shownUrl: this.shownUrl,
        }, window.location.href);

        try {
            const response = await fetch(streamUrl.href, {
                headers: { Accept: STREAM_TYPE },
                credentials: 'same-origin',
                signal: request.controller.signal,
            });
            const type = response.headers.get('Content-Type') || '';

            if (!response.ok || !type.includes(STREAM_TYPE)) {
                throw new Error('Results: no stream (' + response.status + ')');
            }

            const stream = await response.text();

            if (!applies()) {
                this.endMore(request);

                return;
            }

            renderStreamMessage(stream);
            await this.replaced(footer);
        } catch (error) {
            this.endMore(request);

            // Aborted or superseded: another list or page is on its way - nothing to fall back to
            if (!applies()) {
                return;
            }

            // The link's own way: load the page with the rows
            visit(url.href);

            return;
        }

        this.endMore(request);

        // Rendered, then a visit started or the page went: the entry is the new page's now
        if (request.controller.signal.aborted || !this.element.isConnected) {
            return;
        }

        // The address says what the page shows (?page=N+1): a reload or a Back from a puzzle page renders the same rows
        this.write('more', url.href);
        this.shownUrl = url.href;
        this.focusRow(before);
    }

    /** Show more's request in flight is cancelled (a newer change of the list, a visit, the page leaving) */
    abortMore() {
        const request = this.moreRequest;

        if (request) {
            this.moreRequest = null;
            this.loading = false;
            request.controller.abort();
        }
    }

    endMore(request) {
        if (this.moreRequest === request) {
            this.moreRequest = null;
        }

        this.loading = false;
    }

    all(event) {
        const link = event.currentTarget;

        if (!this.plainClick(event) || !this.frame()) {
            return;
        }

        event.preventDefault();

        if (this.loading) {
            return;
        }

        const before = this.rowsCount();
        const typed = this.takeTyping();
        let url = new URL(link.href, window.location.href).href;

        // The search still waited (review F4): every row of the list it asks for
        if (typed !== null) {
            url = withTypedQuery(url, typed);
        }

        this.busy(link, document.getElementById(FOOTER_ID));
        // `loading` ends with the list's load, whichever load that is (review F3); `after` only puts the focus
        this.change('all', url, { after: () => this.focusRow(before) });
    }

    // ---- Changes of the list ------------------------------------------------------------------------------------------

    onClick = (event) => {
        const row = event.target && typeof event.target.closest === 'function' ? event.target.closest(ROWS) : null;

        if (row) {
            this.lastRow = row.id;
        }

        if (!this.owning || !this.plainClick(event)) {
            return;
        }

        const link = event.target && typeof event.target.closest === 'function' ? event.target.closest(LIST_LINKS) : null;

        if (!link || !this.element.contains(link) || link.hasAttribute('data-results-more') || link.hasAttribute('data-results-all')) {
            return;
        }

        event.preventDefault();

        const typed = this.takeTyping();
        let url = new URL(link.href, window.location.href).href;

        // The search still waited (review F4): the typed text goes with the click - unless the click drops the search
        if (typed !== null && !link.matches(DROPS_SEARCH)) {
            url = withTypedQuery(url, typed);
        }

        this.change(link.hasAttribute('data-results-start') ? 'start' : 'discrete', url, { focus: focusTargets(link) });
    };

    onInput = (event) => {
        if (!event.target || event.target.id !== SEARCH_ID) {
            return;
        }

        clearTimeout(this.typingTimer);
        this.typingTimer = setTimeout(() => {
            this.typingTimer = null;
            const pending = this.pending;

            // A change is loading (a click within the typing): the typed text continues it rather than undoing it -
            // the form's hidden tab/view/sort still name the list before it (review F4)
            if (pending) {
                this.change(pending.kind === 'pop' ? 'typing' : pending.kind, withTypedQuery(pending.url, event.target.value), {
                    focus: pending.focus,
                    after: pending.after,
                });

                return;
            }

            this.change('typing', this.formUrl());
        }, TYPING_DEBOUNCE_MS);
    };

    onChange = (event) => {
        const field = event.target;

        // The search follows typing; a field outside the filter form is none of the list's
        if (!field || !field.name || field.id === SEARCH_ID || !field.form || field.form.id !== FORM_ID) {
            return;
        }

        // The form sends the typed text with the change: the search's own wait is over (review F4)
        this.takeTyping();
        this.change('discrete', this.formUrl());
    };

    onSubmit = (event) => {
        const form = event.target;

        if (event.defaultPrevented || !form || form.id !== FORM_ID) {
            return;
        }

        event.preventDefault();

        // Enter while the search still waits for its 300 ms is that typing's end, anything else a change of its own
        const typing = this.typingTimer !== null;
        clearTimeout(this.typingTimer);
        this.typingTimer = null;

        const active = document.activeElement;

        // A phone's keyboard goes away after "Search", as after the page it used to load
        if (active && active.id === SEARCH_ID && window.matchMedia && window.matchMedia('(pointer: coarse)').matches) {
            active.blur();
        }

        this.change(typing ? 'typing' : 'submit', this.formUrl());
    };

    /**
     * A change of the list: loaded into its frame - unless it shows (or is loading) that list already. Its entry is
     * written once the list has rendered (onFrameLoad): the address never names a list the page does not show (a slow
     * or failed load keeps the old one), and it is the server's form of the address.
     */
    change(kind, url, { focus = null, after = null } = {}) {
        if (sameState(url, this.pending ? this.pending.url : this.shownUrl)) {
            // Nothing loads for it: a busy Show all is idle again (a load already on its way ends it itself)
            if (!this.pending && !this.moreRequest) {
                this.loading = false;
            }

            if (after) {
                after();
            }

            return;
        }

        this.load(url, { kind, focus, after });
    }

    /** The entry for `url` written as the rule says (assets/player_results_state.js): 'push' | 'replace' | 'none' */
    write(kind, url) {
        // Never for a page that went or is going: the entry is another page's then (review F2)
        if (this.leaving || !this.element.isConnected) {
            return 'none';
        }

        const action = historyAction(kind, window.location.href, url, this.nativeApp);

        if (action === 'push') {
            layers.pushPageState(OWNER, url);
        } else if (action === 'replace') {
            layers.replacePageState(OWNER, url);
        }

        if (action !== 'none') {
            this.currentUrl = url;
        }

        return action;
    }

    /** `kind` 'pop': the entry is there already (Back / Forward) - nothing is written for it */
    load(url, { kind = 'pop', focus = null, after = null } = {}) {
        const frame = this.frame();

        // Another list from now on: Show more's answer for the old one would land in it
        this.abortMore();

        if (!frame) {
            visit(url);

            return;
        }

        this.pending = { url, kind, focus, after };

        if (frame.getAttribute('src') === url) {
            frame.reload();
        } else {
            frame.src = url;
        }
    }

    onFrameLoad = () => {
        const frame = this.frame();
        const pending = this.pending;

        // An answer for an older request (a newer load is on its way and brings its own)
        if (pending && frame && !sameState(frame.getAttribute('src') || '', pending.url, window.location.href)) {
            return;
        }

        this.pending = null;
        // Whatever load this was (Show all's or one that superseded it): the list is idle again (review F3)
        this.loading = false;

        const state = this.frameState(frame);

        if (state) {
            // The entry, in the server's form of the address (a members-only value of another viewer dropped, a page
            // clamped, the parameters in order) - pushed for a discrete change, replaced for typing and Show all
            if (pending && pending.kind !== 'pop') {
                this.write(pending.kind, state.url);
            }

            this.shownUrl = state.url;
            this.syncForm(state);
        }

        if (pending && pending.focus) {
            this.restoreFocus(pending.focus);
        }

        if (pending && pending.after) {
            pending.after();
        }
    };

    onFrameError = () => {
        const pending = this.pending;
        this.pending = null;
        this.loading = false;

        // The link's own way: the page of that list (for Back / Forward the current entry's - Turbo replaces it then)
        if (pending) {
            visit(pending.url);
        }
    };

    /**
     * The answer holds no list (review F5): a 404 (the player blocked meanwhile, the account gone), the profile a private
     * player's Results redirect to, an error page - shown as the page it is, by a full visit of that answer (the player
     * card's rule), never Turbo's "Content missing"
     */
    onFrameMissing = (event) => {
        event.preventDefault();
        this.pending = null;
        this.loading = false;
        this.abortMore();
        event.detail.visit(event.detail.response);
    };

    /** Back / Forward between the page's own entries: that entry's list. Layer entries never reach this (layer_history) */
    onPopState = (event) => {
        const state = event.state;

        if (!state || state[PAGE_STATE] !== OWNER || window.location.pathname !== this.pathname) {
            return;
        }

        clearTimeout(this.typingTimer);
        this.typingTimer = null;
        this.currentUrl = window.location.href;

        // A change still loading is dropped (its entry was never written); the list of the entry is shown
        if (!this.pending && sameState(window.location.href, this.shownUrl)) {
            return;
        }

        this.load(window.location.href);
    };

    // ---- Leaving and coming back --------------------------------------------------------------------------------------

    onBeforeVisit = (event) => {
        this.saveScroll();
        this.leaving = true;
        this.abortMore();
        layers.resumeTurboHistory(OWNER);

        if (SNAPSHOT_RESTORES) {
            // GEST-15: Turbo keeps the scroll of an entry under its restoration identifier, recorded on scroll events;
            // the entry's identifier came back with the resume - record where the page is now under it
            window.dispatchEvent(new Event('scroll'));
        }

        // Another listener stopped the visit: the page stays, and keeps owning its entries
        queueMicrotask(() => {
            if (event.defaultPrevented && this.element.isConnected) {
                this.leaving = false;
                layers.pauseTurboHistory(OWNER);
                layers.replacePageState(OWNER, window.location.href);
            }
        });
    };

    onPageHide = () => {
        this.saveScroll();
    };

    /** GEST-15: Turbo caches this page for a restore - nothing pending, no widget that builds DOM, no open menu */
    onBeforeCache = () => {
        clearTimeout(this.typingTimer);
        this.typingTimer = null;
        this.stopRestoring();
        this.pending = null;
        this.abortMore();
        this.loading = false;

        const form = this.form();

        if (form) {
            form.querySelectorAll('.date-picker').forEach((input) => {
                if (input._flatpickr) {
                    input._flatpickr.destroy();
                }
            });
        }

        const toggle = document.getElementById('player-results-sort');
        const dropdown = toggle && window.bootstrap && window.bootstrap.Dropdown ? window.bootstrap.Dropdown.getInstance(toggle) : null;

        if (dropdown) {
            dropdown.hide();
        }

        const footer = document.getElementById(FOOTER_ID);

        if (footer) {
            footer.removeAttribute('aria-busy');
            footer.querySelectorAll('a.disabled').forEach((link) => {
                link.classList.remove('disabled');
                link.removeAttribute('aria-disabled');
            });
        }
    };

    saveScroll() {
        const key = scrollKey(this.currentUrl);

        if (key === null) {
            return;
        }

        const row = this.anchorRow();

        try {
            window.sessionStorage.setItem(key, JSON.stringify({
                y: Math.round(window.scrollY),
                row: row ? row.id : null,
                rowTop: row ? Math.round(row.getBoundingClientRect().top) : null,
            }));
        } catch (e) {
            // blocked storage: Back lands at the top, as before
        }
    }

    readSaved(key) {
        if (key === null) {
            return null;
        }

        try {
            return parseSavedScroll(window.sessionStorage.getItem(key));
        } catch (e) {
            return null;
        }
    }

    /** The row clicked last when it is still in view, else the first row in view */
    anchorRow() {
        const clicked = this.lastRow ? document.getElementById(this.lastRow) : null;

        if (clicked && clicked.matches(ROWS) && inViewport(clicked)) {
            return clicked;
        }

        return this.rows().find((row) => row.getBoundingClientRect().bottom > 0) || null;
    }

    /** The saved row back where it was (its scrollY when it is gone); again once the page has loaded, unless the visitor scrolled */
    restoreScroll(saved, key) {
        const apply = () => {
            const row = saved.row ? document.getElementById(saved.row) : null;
            const top = restoreTarget(saved, row ? row.getBoundingClientRect().top + window.scrollY : null);

            if (top !== null) {
                window.scrollTo({ top, left: 0, behavior: 'instant' });
            }

            if (row && !inViewport(row)) {
                row.scrollIntoView({ block: 'center', behavior: 'instant' });
            }
        };

        apply();

        try {
            window.sessionStorage.removeItem(key);
        } catch (e) {
            // nothing
        }

        if (document.readyState === 'complete') {
            return;
        }

        const stop = () => this.stopRestoring();
        const loaded = () => {
            this.stopRestoring();
            apply();
        };
        const events = ['wheel', 'touchstart', 'keydown', 'pointerdown'];

        events.forEach((type) => window.addEventListener(type, stop, { passive: true }));
        window.addEventListener('load', loaded);
        this.restoreCleanup = () => {
            events.forEach((type) => window.removeEventListener(type, stop, { passive: true }));
            window.removeEventListener('load', loaded);
        };
    }

    stopRestoring() {
        if (this.restoreCleanup) {
            const remove = this.restoreCleanup;
            this.restoreCleanup = null;
            remove();
        }
    }

    // ---- The form outside the frame -----------------------------------------------------------------------------------

    formUrl() {
        const form = this.form();
        const entries = form ? Array.from(new FormData(form).entries()).map(([name, value]) => [name, String(value)]) : [];

        return new URL(urlFromForm(entries, this.pathname), window.location.href).href;
    }

    frameState(frame) {
        const element = frame ? frame.querySelector('[data-results-state]') : null;

        if (!element) {
            return null;
        }

        return {
            url: new URL(element.dataset.url || window.location.href, window.location.href).href,
            category: element.dataset.category || '',
            view: element.dataset.view || '',
            sort: element.dataset.sort || '',
            filters: Number(element.dataset.filters || 0),
            clear: element.dataset.clear || '',
        };
    }

    /** The form shows the list the frame shows - except the text field the visitor is typing in */
    syncForm(state) {
        const form = this.form();

        if (!form) {
            return;
        }

        ['category', 'view', 'sort'].forEach((name) => this.hiddenField(form, name, state[name]));

        const presets = Array.from(form.querySelectorAll('input[name="pieces"]')).map((input) => input.value).filter((value) => value !== '');
        const values = formStateFromUrl(state.url, presets, window.location.href);
        const typing = document.activeElement;
        const set = (field, value) => {
            if (!field || (field === typing && TEXT_TYPES.includes(field.type))) {
                return;
            }

            if (field.localName === 'select' && !Array.from(field.options).some((option) => option.value === value)) {
                value = '';
            }

            if (field.value !== value) {
                field.value = value;
            }
        };
        const field = (name) => form.querySelector('[name="' + name + '"]');

        set(field('q'), values.q);
        set(field('brand'), values.brand);
        set(field('team'), values.team);
        form.querySelectorAll('input[name="pieces"]').forEach((radio) => {
            radio.checked = values.pieces !== null && radio.value === values.pieces;
        });
        set(field('pieces_min'), values.piecesMin);
        set(field('pieces_max'), values.piecesMax);
        set(field('pieces_was'), values.piecesWas);
        ['first', 'unboxed', 'relax'].forEach((name) => {
            const checkbox = field(name);

            if (checkbox) {
                checkbox.checked = values[name];
            }
        });
        ['from', 'to'].forEach((name) => {
            const input = field(name);

            if (!input) {
                return;
            }

            if (input._flatpickr) {
                if (values[name] === '') {
                    input._flatpickr.clear(false);
                } else if (input.value !== values[name]) {
                    input._flatpickr.setDate(values[name], false);
                }
            } else {
                set(input, values[name]);
            }
        });
        set(field('speed'), values.speed);
        set(field('speed_cmp'), values.speedCmp);
        set(field('speed_unit'), values.speedUnit);
        form.querySelectorAll('input[name="difficultyTiers[]"]').forEach((checkbox) => {
            checkbox.checked = values.difficultyTiers.includes(checkbox.value);
        });

        // The filters' count on the toggle (the search not counted) and the form's Clear all
        const toggle = form.querySelector('[data-testid="results-filters-toggle"]');
        let badge = form.querySelector('[data-testid="results-filters-count"]');

        if (toggle && state.filters > 0) {
            if (!badge) {
                badge = document.createElement('span');
                badge.className = 'badge rounded-pill bg-primary';
                badge.setAttribute('data-testid', 'results-filters-count');
                toggle.append(' ', badge);
            }

            badge.textContent = String(state.filters);
        } else if (badge) {
            badge.remove();
        }

        const clear = form.querySelector('a[data-results-form-clear]');

        if (clear) {
            clear.hidden = state.clear === '';

            if (state.clear !== '') {
                clear.setAttribute('href', state.clear);
            }
        }
    }

    hiddenField(form, name, value) {
        let input = form.querySelector(':scope > input[type="hidden"][name="' + name + '"]');

        if (value === '') {
            if (input) {
                input.remove();
            }

            return;
        }

        if (!input) {
            input = document.createElement('input');
            input.type = 'hidden';
            input.name = name;
            form.prepend(input);
        }

        input.value = value;
    }

    // ---- Helpers ------------------------------------------------------------------------------------------------------

    /**
     * The search's wait ended by another change (review F4): null when nothing waited, else the typed text - the change
     * takes it along (or drops it)
     */
    takeTyping() {
        if (this.typingTimer === null) {
            return null;
        }

        clearTimeout(this.typingTimer);
        this.typingTimer = null;
        const field = document.getElementById(SEARCH_ID);

        return field ? field.value : null;
    }

    /** A plain left click this page handles - anything else (a new tab, a download) is the browser's */
    plainClick(event) {
        return !(event.defaultPrevented || event.button > 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey);
    }

    frame() {
        return document.getElementById(FRAME_ID);
    }

    form() {
        return document.getElementById(FORM_ID);
    }

    busy(link, footer) {
        this.loading = true;
        link.classList.add('disabled');
        link.setAttribute('aria-disabled', 'true');

        if (footer) {
            footer.setAttribute('aria-busy', 'true');
        }
    }

    rows() {
        return Array.from(document.querySelectorAll(ROWS));
    }

    rowsCount() {
        return this.rows().length;
    }

    /** Resolves once the stream replaced the footer (its last action), or after a timeout */
    replaced(footer) {
        return new Promise((resolve) => {
            const isDone = () => !footer || !footer.isConnected;

            if (isDone()) {
                resolve();

                return;
            }

            const observer = new MutationObserver(() => {
                if (isDone()) {
                    observer.disconnect();
                    clearTimeout(timer);
                    resolve();
                }
            });
            const timer = setTimeout(() => {
                observer.disconnect();
                resolve();
            }, RENDER_TIMEOUT_MS);

            observer.observe(this.frame() || document.body, { childList: true, subtree: true });
        });
    }

    /** The first new row's puzzle link takes the focus, without scrolling the page */
    focusRow(index) {
        const row = this.rows()[index];
        const target = row ? row.querySelector('a.ps-puzzle-name') : null;

        if (target) {
            target.focus({ preventScroll: true });
        }
    }

    /** After a list loaded from a control inside it: the focus back on that control (or the nearest one left) */
    restoreFocus(selectors) {
        const active = document.activeElement;

        // The visitor moved on (or the control survived): nothing to put back
        if (active && active !== document.body && active.isConnected && !active.closest('[hidden]')) {
            return;
        }

        for (const selector of selectors) {
            const target = document.querySelector(selector);

            if (target && !target.closest('[hidden]')) {
                target.focus({ preventScroll: true });

                return;
            }
        }
    }
}

function inViewport(element) {
    const rect = element.getBoundingClientRect();

    return rect.bottom > 0 && rect.top < window.innerHeight;
}

/** Where the focus goes after the list behind a control loaded (the control itself is rendered anew) */
function focusTargets(link) {
    const attribute = (name) => link.getAttribute(name);

    if (link.hasAttribute('data-results-category')) {
        return ['#player-results [data-results-category="' + attribute('data-results-category') + '"]'];
    }

    if (link.hasAttribute('data-results-view')) {
        return ['#player-results [data-results-view="' + attribute('data-results-view') + '"]'];
    }

    if (link.hasAttribute('data-results-sort')) {
        return ['#player-results-sort'];
    }

    if (link.hasAttribute('data-results-start')) {
        return ['#player-results-rows > tr[id] a.ps-puzzle-name'];
    }

    if (link.closest('#player-results')) {
        // A chip or Clear all: the next chip, else the shown tab
        return ['#player-results [data-results-chip]', '#player-results [data-results-category][aria-current]'];
    }

    // The form's Clear all hides itself: the filters' toggle
    return ['[data-testid="results-filters-toggle"]'];
}
