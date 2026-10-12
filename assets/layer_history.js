import { isNativeApp } from './platform.js';

/**
 * Back closes the top layer (docs/features/navigation README §4.13 G1, requirement GEST-01, implementation plan §3.4).
 *
 * The one owner of the browser history next to Turbo: every layer (Menu panel, desktop overlay, player card, phone
 * search view, the dynamic modal with data-modal-history - Help and achievement sheets - and the live results views)
 * and every page that keeps history entries of its own (Results) goes through this module. Nothing else calls
 * history.pushState or touches Turbo.session.history (tests/TurboHistoryOwnershipTest.php).
 *
 * (a) pauseTurboHistory(owner) / resumeTurboHistory(owner): a set of owners. The first pause notes Turbo's restoration
 *     identifier and index and stops Turbo's popstate handling (Turbo answers every pop with a restore visit, which
 *     app.js turns into a full page load). Turbo restarts only when the set is empty, and then keeps the page's
 *     identity: Turbo's start() mints a new restoration identifier (its saved scroll position would be lost), so the
 *     entry is written back with the identifier and index it had.
 * (b) open(id): one same-URL entry {mspLayer: id}. Opening another layer while one holds the entry is a swap: the first
 *     closes (onClose {reason: 'swap'}) and the new one takes the entry over with replaceState - never back() + push.
 * (c) requestClose(id) for ×, Esc, scrim and swipe: history.back(); the layer closes in the popstate (onClose
 *     {reason: 'back'}). A popstate while a layer holds the entry closes it the same way. Nothing else sees that pop
 *     (stopImmediatePropagation in a capture listener: Turbo, the Results page and the Back logging of
 *     nav_instrumentation.js never count a layer close).
 * (d) Leaving from a layer replaces its entry: a Turbo visit started while a layer holds the entry is a replace visit
 *     (data-turbo-action="replace" set in turbo:click / turbo:submit-start, put back at turbo:visit / turbo:load / the
 *     next submit - never at turbo:submit-end, Turbo 8.0.23 reads a form's action after it); data-turbo="false" and
 *     other-origin links navigate with location.replace(). The layer is released at turbo:visit (onClose
 *     {reason: 'leave'}; its UI may stay until the new page renders). History reads [A, B], never [A, A', B].
 * (f) Inside the Hotwire Native app no entry is pushed and a layer closes without history.back().
 *
 * Page owners (Results, PROF-05c): pushPageState / replacePageState write Turbo-shaped entries {turbo, mspPage: owner}.
 * A pop onto an entry that is not the owner's (another page, decision 93) releases every owner, restarts Turbo with
 * its identity and starts one restore visit - the instant-Back rules decide between snapshot and reload.
 *
 * Turbo internals used (pinned to 8.0.23, tests/TurboContractScriptTest.php): session.history.{stop, start, replace,
 * currentIndex, restorationIdentifier}, session.navigator.currentVisit.action. Missing ones fall back to today's
 * behaviour with one console.warn each.
 *
 * Pure factory: createLayerHistory(env) takes {window, document, history?, location?, turbo?} so
 * tests/layer-history-harness.mjs drives it under node; the default export is the browser instance, created and
 * installed when this module is imported in a browser (assets/app.js).
 */

export const LAYER_STATE = 'mspLayer';
export const PAGE_STATE = 'mspPage';

/** The owner name of an open layer in the pause set (page owners use their own names) */
export const LAYER_OWNER = 'layer';

/** An open() waits this long at most for the popstate of a close still in flight */
export const OPEN_WAIT_MS = 300;

/** A history.back() whose popstate never came: the layer closes anyway */
export const CLOSE_FALLBACK_MS = 400;

/**
 * Dispatched on the document whenever a layer closes ({id, reason}): a popstate that closes a layer is stopped at the
 * window, so whoever reacts to Back must listen here too (the in-page confirm answers false, review F2)
 */
export const LAYER_CLOSED_EVENT = 'msp:layer-closed';

const MARKER = 'data-turbo-action';

// Turbo 8.0.23's config.drive.unvisitableExtensions (read from window.Turbo when present): a same-origin link to one of
// these is no Turbo visit - the browser navigates (or downloads) by itself
const UNVISITABLE_EXTENSIONS = ['.7z', '.aac', '.apk', '.avi', '.bmp', '.bz2', '.css', '.csv', '.deb', '.dmg', '.doc', '.docx', '.exe',
    '.gif', '.gz', '.heic', '.heif', '.ico', '.iso', '.jpeg', '.jpg', '.js', '.json', '.m4a', '.mkv', '.mov', '.mp3', '.mp4', '.mpeg',
    '.mpg', '.msi', '.ogg', '.ogv', '.pdf', '.pkg', '.png', '.ppt', '.pptx', '.rar', '.rtf', '.svg', '.tar', '.tif', '.tiff', '.txt',
    '.wav', '.webm', '.webp', '.wma', '.wmv', '.xls', '.xlsx', '.xml', '.zip'];

/** Turbo's locationIsVisitable() extension rule: the extension of the URL's last path segment */
export function unvisitableExtension(url, extensions = UNVISITABLE_EXTENSIONS) {
    const last = String(url.pathname || '').split('/').pop() || '';
    const match = last.match(/\.[^.]*$/);

    return Boolean(match) && (typeof extensions.has === 'function' ? extensions.has(match[0]) : extensions.includes(match[0]));
}

/**
 * What open() does with the history (native-aware decision, tests/native-step-aside-harness.mjs):
 * 'none' inside the app, 'swap' when a layer already holds the entry, else 'push'.
 */
export function entryAction(nativeApp, holderId) {
    if (nativeApp) {
        return 'none';
    }

    return holderId ? 'swap' : 'push';
}

/** How requestClose() closes: 'back' when the current entry is the layer's own, 'direct' otherwise (app, entry gone) */
export function closeAction(nativeApp, currentStateLayer, id) {
    return !nativeApp && currentStateLayer === id ? 'back' : 'direct';
}

function isFrame(element) {
    return Boolean(element) && String(element.localName || element.tagName || '').toLowerCase() === 'turbo-frame';
}

/**
 * Does following this link (or submitting this form) start a page visit rather than a frame navigation?
 * Mirrors Turbo 8.0.23's frame resolution (FrameRedirector + FrameController#shouldInterceptNavigation):
 * data-turbo-frame="_top" → page; the id of an existing, enabled frame → that frame; otherwise the closest enabled
 * <turbo-frame>, unless that frame has target="_top".
 */
export function startsPageVisit(element, document, submitter = null) {
    if (!element || typeof element.getAttribute !== 'function') {
        return false;
    }

    const id = (submitter && submitter.getAttribute && submitter.getAttribute('data-turbo-frame')) || element.getAttribute('data-turbo-frame');

    if (id === '_top') {
        return true;
    }

    if (id && document && typeof document.getElementById === 'function') {
        const named = document.getElementById(id);

        if (isFrame(named) && !named.hasAttribute('disabled')) {
            return false;
        }
    }

    const frame = typeof element.closest === 'function' ? element.closest('turbo-frame') : null;

    if (!frame || frame.hasAttribute('disabled')) {
        return true;
    }

    return frame.getAttribute('target') === '_top';
}

function uuid(window) {
    try {
        const crypto = window && window.crypto;

        if (crypto && typeof crypto.randomUUID === 'function') {
            return crypto.randomUUID();
        }

        if (crypto && typeof crypto.getRandomValues === 'function') {
            const bytes = crypto.getRandomValues(new Uint8Array(16));
            bytes[6] = (bytes[6] & 0x0f) | 0x40;
            bytes[8] = (bytes[8] & 0x3f) | 0x80;
            const hex = Array.from(bytes, (b) => b.toString(16).padStart(2, '0')).join('');

            return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`;
        }
    } catch (e) {
        // fall through
    }

    return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (c) => {
        const r = Math.floor(Math.random() * 16);

        return (c === 'x' ? r : (r & 0x3) | 0x8).toString(16);
    });
}

function withoutHash(href) {
    const at = String(href).indexOf('#');

    return at === -1 ? String(href) : String(href).slice(0, at);
}

export function createLayerHistory(env) {
    const window = env.window;
    const document = env.document;
    const history = () => env.history || window.history;
    const location = () => env.location || window.location;
    const now = () => (window.performance && typeof window.performance.now === 'function' ? window.performance.now() : Date.now());
    const setTimer = (fn, ms) => (env.setTimeout || window.setTimeout).call(window, fn, ms);
    const clearTimer = (timer) => (env.clearTimeout || window.clearTimeout).call(window, timer);
    const console = env.console || window.console || { warn() {} };

    function turbo() {
        try {
            if (typeof env.turbo === 'function') {
                return env.turbo() || null;
            }

            return (window.Turbo && window.Turbo.session) || null;
        } catch (e) {
            return null;
        }
    }

    function native() {
        return isNativeApp(document ? document.documentElement : null);
    }

    const warned = new Set();

    function warnOnce(key, message) {
        if (warned.has(key)) {
            return;
        }

        warned.add(key);

        try {
            console.warn('[layer_history] ' + message);
        } catch (e) {
            // nothing
        }
    }

    // The layer on top: {id, element, onClose, entry (holds a history entry), href (where it opened)}
    let top = null;
    // Owners pausing Turbo's history; `noted` = Turbo's identity at the first pause
    const owners = new Set();
    let noted = null;
    // Page owners (not the layer) => the pathname of their page
    const pages = new Map();
    // A history.back() in flight: {id, timer}
    let pendingClose = null;
    // Opens and callbacks waiting for that back() to land
    let queuedOpens = [];
    let thens = [];
    let openWaitTimer = null;
    // Elements carrying our replace marker: [{element, previous}]
    let markers = [];
    // Layers released by a visit whose UI may still show until the new page renders (closeForCache closes them)
    let leaving = [];
    let lastBackAt = null;
    let installed = false;
    // A pop the helper already accounted for, onto the page entry of this URL (review F4: the late back() of a close
    // settled without it; F5: the Back from a dead layer entry): {href, until}; Turbo must not read it as a page change
    let quietPop = null;
    // The layer a non-Turbo navigation leaves from: released when the page really goes (pagehide) - a download never
    // commits, so its layer stays open with its entry (review F6)
    let leavingByBrowser = null;
    // The Turbo identity of the entry the last layer entry was pushed on ({id, index}): the layer's entry is one above
    // it - a page owner may have pushed entries since Turbo was first paused (review F9)
    let layerBase = null;

    /** The late popstate after a fallback close may still come within this window (ms) */
    const LATE_POP_MS = 2000;

    function state() {
        try {
            return history().state;
        } catch (e) {
            return null;
        }
    }

    function href() {
        return String(location().href);
    }

    function pathnameOf(url) {
        try {
            return new URL(url, href()).pathname;
        } catch (e) {
            return null;
        }
    }

    function announce(layer, reason) {
        try {
            const view = document && document.defaultView ? document.defaultView : window;
            const Custom = view && typeof view.CustomEvent === 'function' ? view.CustomEvent : null;

            if (Custom && document && typeof document.dispatchEvent === 'function') {
                document.dispatchEvent(new Custom(LAYER_CLOSED_EVENT, { detail: { id: layer.id, reason } }));
            }
        } catch (e) {
            // a listener failing never keeps the layer open
        }
    }

    function callOnClose(layer, reason) {
        if (!layer) {
            return;
        }

        announce(layer, reason);

        if (typeof layer.onClose !== 'function') {
            return;
        }

        try {
            layer.onClose({ reason, id: layer.id });
        } catch (error) {
            try {
                console.warn('[layer_history] onClose of "' + layer.id + '" failed', error);
            } catch (e) {
                // nothing
            }
        }
    }

    // ---- (a) Turbo's history ------------------------------------------------------------------------------------

    function pause(owner) {
        if (owners.has(owner)) {
            return;
        }

        if (owners.size === 0) {
            const session = turbo();
            const turboHistory = session && session.history;
            const entry = state() && state().turbo;

            noted = {
                restorationIdentifier: entry && entry.restorationIdentifier ? entry.restorationIdentifier : (turboHistory ? turboHistory.restorationIdentifier : null),
                currentIndex: entry && typeof entry.restorationIndex === 'number' ? entry.restorationIndex : (turboHistory && typeof turboHistory.currentIndex === 'number' ? turboHistory.currentIndex : 0),
                href: href(),
            };

            if (turboHistory && typeof turboHistory.stop === 'function') {
                turboHistory.stop();
            }
        }

        owners.add(owner);

        if (owner !== LAYER_OWNER && !pages.has(owner)) {
            pages.set(owner, pathnameOf(href()));
        }
    }

    /** The identifier and index the current entry gets back when Turbo restarts */
    function identity() {
        const current = state();
        const entry = current && current.turbo;

        if (entry && entry.restorationIdentifier && typeof entry.restorationIndex === 'number') {
            return { id: entry.restorationIdentifier, index: entry.restorationIndex };
        }

        if (current && current[LAYER_STATE] && layerBase) {
            // On a layer's entry (a visit leaves from it, or its back() never landed): one above the entry it was opened on
            return { id: layerBase.id, index: layerBase.index + 1 };
        }

        if (noted && noted.restorationIdentifier && withoutHash(noted.href) === withoutHash(href())) {
            // Still on a layer's entry (a visit leaves from it, or its back() never landed): one above the page's
            const above = current && current[LAYER_STATE] ? 1 : 0;

            return { id: noted.restorationIdentifier, index: noted.currentIndex + above };
        }

        return null;
    }

    function restart() {
        const session = turbo();
        const turboHistory = session && session.history;
        const keep = identity();
        noted = null;
        layerBase = null;

        if (!turboHistory || typeof turboHistory.start !== 'function') {
            return;
        }

        if (typeof turboHistory.currentIndex !== 'number' || typeof turboHistory.replace !== 'function') {
            warnOnce('identity', 'Turbo history internals missing (currentIndex/replace): restarted without keeping the restoration identifier');
            turboHistory.start();

            return;
        }

        turboHistory.start();

        if (keep) {
            turboHistory.currentIndex = keep.index;
            turboHistory.replace(new URL(href()), keep.id);
        }
    }

    function resume(owner) {
        if (!owners.has(owner)) {
            return;
        }

        owners.delete(owner);
        pages.delete(owner);

        if (owners.size === 0) {
            restart();
        }
    }

    function ownsEntry(current) {
        const owner = current && current[PAGE_STATE];

        return Boolean(owner) && owners.has(owner) && pages.get(owner) === pathnameOf(href());
    }

    function releasePageOwners() {
        let released = false;

        for (const owner of Array.from(owners)) {
            if (owner !== LAYER_OWNER) {
                owners.delete(owner);
                pages.delete(owner);
                released = true;
            }
        }

        if (released && owners.size === 0) {
            restart();
        }

        return released;
    }

    function restoreVisit(current) {
        const session = turbo();
        const restorationIdentifier = current && current.turbo ? current.turbo.restorationIdentifier : undefined;
        const navigator = session && session.navigator;

        // The traversal already happened: start the restore the way Turbo's own popstate does (Navigator#startVisit), no
        // turbo:before-visit - a page's leave guard would keep its DOM under the new URL and its "Leave" would push a
        // duplicate entry (review F3)
        if (navigator && typeof navigator.startVisit === 'function') {
            navigator.startVisit(href(), restorationIdentifier, { action: 'restore', historyChanged: true });

            return;
        }

        if (session && typeof session.visit === 'function') {
            warnOnce('startVisit', 'Turbo navigator.startVisit missing: a restore after a layer pop is proposed like a new visit');
            session.visit(href(), { action: 'restore', historyChanged: true, restorationIdentifier });

            return;
        }

        location().reload();
    }

    // ---- Layers ---------------------------------------------------------------------------------------------------

    function flushQueued() {
        clearTimer(openWaitTimer);
        openWaitTimer = null;
        const opens = queuedOpens;
        queuedOpens = [];
        opens.forEach(([id, options]) => open(id, options));

        const callbacks = thens;
        thens = [];
        callbacks.forEach((fn) => {
            try {
                fn();
            } catch (error) {
                try {
                    console.warn('[layer_history] closeThen callback failed', error);
                } catch (e) {
                    // nothing
                }
            }
        });
    }

    function clearPending() {
        if (pendingClose) {
            clearTimer(pendingClose.timer);
            pendingClose = null;
        }
    }

    /** Closes the top layer's UI and gives its entry up (the entry itself is already gone or never existed) */
    function finish(reason) {
        const layer = top;
        top = null;
        clearPending();

        if (layer && layer.entry) {
            resume(LAYER_OWNER);
        }

        callOnClose(layer, reason);
    }

    /** The back() of the closing layer did not land in time: close it without its popstate */
    function settleClose(message) {
        if (!pendingClose) {
            return;
        }

        const closing = pendingClose.id;
        clearPending();
        warnOnce('fallback', 'history.back() of layer "' + closing + '" did not arrive ' + message);

        if (top && top.id === closing) {
            finish('back');
            // Its popstate may still come: onto the same page, it is no page change (review F4)
            quietPop = { href: withoutHash(href()), until: now() + LATE_POP_MS };
        }

        flushQueued();
    }

    function open(id, options = {}) {
        if (pendingClose) {
            // Never back() + pushState() racing each other: the open waits for the close's popstate
            queuedOpens.push([id, options]);

            if (openWaitTimer === null) {
                openWaitTimer = setTimer(() => {
                    openWaitTimer = null;
                    settleClose('before the next layer opened');
                }, OPEN_WAIT_MS);
            }

            return;
        }

        const layer = { id, element: options.element || null, onClose: options.onClose || null, entry: false, href: href() };

        if (top && top.id === id) {
            top.element = layer.element || top.element;
            top.onClose = layer.onClose || top.onClose;

            return;
        }

        const action = entryAction(native(), top && top.entry ? top.id : null);

        if (action === 'none') {
            const previous = top;
            top = layer;
            callOnClose(previous, 'swap');

            return;
        }

        if (action === 'swap') {
            const previous = top;
            layer.entry = true;
            layer.href = previous.href;
            top = layer;

            try {
                history().replaceState({ [LAYER_STATE]: id }, '', href());
            } catch (e) {
                // the entry keeps the old id; popstate still closes whatever is on top
            }

            callOnClose(previous, 'swap');

            return;
        }

        // An entry-less layer under it (its pushState failed) is replaced, never dropped silently (review F1)
        const previous = top;
        quietPop = null;
        const base = state() && state().turbo;
        layerBase = base && base.restorationIdentifier && typeof base.restorationIndex === 'number'
            ? { id: base.restorationIdentifier, index: base.restorationIndex }
            : null;
        pause(LAYER_OWNER);

        try {
            history().pushState({ [LAYER_STATE]: id }, '', href());
            layer.entry = true;
        } catch (e) {
            resume(LAYER_OWNER);
        }

        top = layer;
        callOnClose(previous, 'swap');
    }

    function requestClose(id) {
        if (!top || (id !== undefined && top.id !== id)) {
            return;
        }

        if (pendingClose && pendingClose.id === top.id) {
            return;
        }

        const current = state();

        if (!top.entry || closeAction(native(), current && current[LAYER_STATE], top.id) !== 'back') {
            finish('close');
            flushQueued();

            return;
        }

        const closing = top.id;
        pendingClose = {
            id: closing,
            timer: setTimer(() => {
                if (pendingClose && pendingClose.id === closing) {
                    settleClose('- closed without it');
                }
            }, CLOSE_FALLBACK_MS),
        };

        history().back();
    }

    function stop(event) {
        if (typeof event.stopImmediatePropagation === 'function') {
            event.stopImmediatePropagation();
        }
    }

    /** Turbo's index follows a pop it does not see (its own listener would have set it) */
    function syncTurboIndex(current) {
        const session = turbo();
        const turboHistory = session && session.history;
        const entry = current && current.turbo;

        if (!turboHistory || !entry || typeof entry.restorationIndex !== 'number' || typeof turboHistory.currentIndex !== 'number') {
            return;
        }

        turboHistory.currentIndex = entry.restorationIndex;

        if (entry.restorationIdentifier) {
            turboHistory.restorationIdentifier = entry.restorationIdentifier;
        }
    }

    function swallowQuietPop(event, current) {
        const quiet = quietPop;
        quietPop = null;

        if (!quiet || holdsEntry() || owners.size > 0 || now() > quiet.until) {
            return false;
        }

        if ((current && current[LAYER_STATE]) || withoutHash(href()) !== quiet.href) {
            return false;
        }

        stop(event);
        syncTurboIndex(current);

        return true;
    }

    function onPopState(event) {
        const current = event.state;

        if (swallowQuietPop(event, current)) {
            return;
        }

        if (top && top.entry) {
            if (current && current[LAYER_STATE] === top.id) {
                return;
            }

            const layer = top;
            // Back onto the entry the layer was opened on is a layer close and nothing else; a pop further back
            // (long-press Back menu, history.go(-2)) lands on another entry
            const otherEntry = withoutHash(href()) !== withoutHash(layer.href);
            const ownersEntry = otherEntry && owners.size > 1 && ownsEntry(current);

            // Another entry of the page owner: its own popstate handler renders it, the layer just closes
            if (!ownersEntry && typeof event.stopImmediatePropagation === 'function') {
                event.stopImmediatePropagation();
            }

            lastBackAt = now();
            finish('back');

            if (otherEntry && !ownersEntry) {
                releasePageOwners();
                restoreVisit(current);
            }

            flushQueued();

            return;
        }

        if (current && current[LAYER_STATE]) {
            // Forward onto a closed layer's entry: never reopened (decision 58 D-4), and nobody else may read it as a
            // page change - Turbo would turn it into a page entry of its own, and the next Back would restore (reload)
            // the page (review F5). The Back from it onto this page's entry is swallowed the same way.
            stop(event);

            if (owners.size === 0) {
                quietPop = { href: withoutHash(href()), until: Infinity };
            }

            return;
        }

        if (owners.size > 0 && !ownsEntry(current)) {
            if (typeof event.stopImmediatePropagation === 'function') {
                event.stopImmediatePropagation();
            }

            releasePageOwners();
            restoreVisit(current);
        }
    }

    // ---- (d) Leaving from a layer ---------------------------------------------------------------------------------

    function mark(element) {
        if (!element || typeof element.setAttribute !== 'function') {
            return;
        }

        markers.push({ element, previous: element.getAttribute(MARKER) });
        element.setAttribute(MARKER, 'replace');
    }

    function restoreMarkers() {
        const list = markers;
        markers = [];
        list.forEach(({ element, previous }) => {
            try {
                if (previous === null) {
                    element.removeAttribute(MARKER);
                } else {
                    element.setAttribute(MARKER, previous);
                }
            } catch (e) {
                // detached
            }
        });
    }

    function holdsEntry() {
        return Boolean(top && top.entry);
    }

    /**
     * The page is left (or re-rendered) under the top layer: it is released whether it holds an entry or not - inside
     * the native app no layer does, and a top kept across the visit would answer isOpen() and take the next ×/Esc/swipe
     * (review F1). Turbo resumes only for a layer that paused it.
     */
    function releaseTop(reason) {
        const layer = top;
        top = null;
        clearPending();

        if (!layer) {
            return null;
        }

        if (layer.entry) {
            resume(LAYER_OWNER);
        }

        callOnClose(layer, reason);

        return layer;
    }

    function onTurboClick(event) {
        restoreMarkers();

        if (!holdsEntry()) {
            return;
        }

        const link = event.target;

        if (!startsPageVisit(link, document)) {
            return;
        }

        let target = null;

        try {
            target = new URL((event.detail && event.detail.url) || link.getAttribute('href'), href()).href;
        } catch (e) {
            target = null;
        }

        // A link to the page the layer was opened on (the Menu row of the current page): a replace visit would leave
        // [A, A] - go back to A first, then visit it (Turbo would replace it the same way without a layer)
        if (target !== null && withoutHash(target) === withoutHash(top.href)) {
            event.preventDefault();

            const original = event.detail && event.detail.originalEvent;

            if (original && typeof original.preventDefault === 'function') {
                original.preventDefault();
            }

            api.closeThen(() => api.visitFromLayer(target, { action: 'replace' }));

            return;
        }

        mark(link);
    }

    function onSubmitStart(event) {
        restoreMarkers();

        if (!holdsEntry()) {
            return;
        }

        const form = event.target;
        const submission = event.detail && event.detail.formSubmission;
        const submitter = submission ? submission.submitter : null;
        let headers = null;

        try {
            headers = submission && submission.fetchRequest ? submission.fetchRequest.headers : null;
        } catch (e) {
            headers = null;
        }

        const toFrame = headers && typeof headers === 'object' ? Boolean(headers['Turbo-Frame']) : !startsPageVisit(form, document, submitter);

        if (toFrame) {
            return;
        }

        mark(form);

        if (submitter && typeof submitter.hasAttribute === 'function' && submitter.hasAttribute(MARKER)) {
            mark(submitter);
        }
    }

    function onTurboVisit(event) {
        restoreMarkers();
        quietPop = null;
        const detail = event.detail || {};

        if (holdsEntry()) {
            if (detail.action === 'advance') {
                // Safety net: a programmatic visit nobody marked (the marking above is the main path)
                const session = turbo();
                const visit = session && session.navigator ? session.navigator.currentVisit : null;

                if (visit && typeof visit === 'object' && 'action' in visit) {
                    visit.action = 'replace';
                    warnOnce('safety-net', 'an unmarked visit left layer "' + top.id + '" - turned into a replace visit (use visitFromLayer)');
                } else {
                    warnOnce('navigator', 'Turbo navigator internals missing: an unmarked visit from a layer keeps its entry');
                }
            }

        }

        if (top) {
            leaving.push(top);
        }

        releaseTop('leave');

        // A page owner whose page is being left (a refresh of the same page keeps it)
        const target = detail.url ? pathnameOf(detail.url) : null;

        for (const owner of Array.from(owners)) {
            if (owner !== LAYER_OWNER && target !== null && pages.get(owner) !== target) {
                resume(owner);
            }
        }
    }

    function onTurboLoad() {
        restoreMarkers();
        leaving = [];
    }

    /** A page render nobody announced with turbo:visit (a full-page form answered 422/500) took the layer's element */
    function onTurboRender() {
        if (top && top.element && top.element.isConnected === false) {
            releaseTop('leave');
        }
    }

    function onDocumentClick(event) {
        if (!top || event.defaultPrevented || event.button > 0 || event.altKey || event.ctrlKey || event.metaKey || event.shiftKey) {
            return;
        }

        const target = event.target;
        const link = target && typeof target.closest === 'function' ? target.closest('a[href]') : null;

        if (!link || link.hasAttribute('download')) {
            return;
        }

        const linkTarget = link.getAttribute('target');

        if (linkTarget && linkTarget !== '_self') {
            return;
        }

        let url;

        try {
            url = new URL(link.getAttribute('href'), href());
        } catch (e) {
            return;
        }

        if (url.protocol !== 'http:' && url.protocol !== 'https:') {
            return;
        }

        const container = link.closest('[data-turbo]');
        const turboOff = Boolean(container) && container.getAttribute('data-turbo') === 'false';
        const external = url.origin !== new URL(href()).origin;

        let unvisitable = false;

        if (!turboOff && !external) {
            const turboConfig = window.Turbo && window.Turbo.config && window.Turbo.config.drive;
            unvisitable = unvisitableExtension(url, turboConfig && turboConfig.unvisitableExtensions ? turboConfig.unvisitableExtensions : UNVISITABLE_EXTENSIONS);
        }

        if (!turboOff && !external && !unvisitable) {
            return;
        }

        // The layer goes with the page (pagehide), not before: a link answering a download leaves the page where it is
        leavingByBrowser = top;

        if (!top.entry) {
            // Nothing to replace (the native app): the browser (or the app) navigates
            return;
        }

        event.preventDefault();
        location().replace(url.href);
    }

    /**
     * A `data-turbo="false"` GET form submitted from a layer holding the entry: the browser would push [A, A', B] - the
     * search URL replaces the layer's entry instead (review F6). A POST form keeps the browser's own push (open: it
     * needs the entry given back first; none sits in a history layer).
     */
    function onSubmit(event) {
        if (!holdsEntry() || event.defaultPrevented) {
            return;
        }

        const form = event.target;

        if (!form || typeof form.closest !== 'function' || String(form.tagName).toUpperCase() !== 'FORM') {
            return;
        }

        const submitter = event.submitter || null;
        const attribute = (name) => (submitter && submitter.hasAttribute && submitter.hasAttribute('form' + name) ? submitter.getAttribute('form' + name) : form.getAttribute(name));
        const container = (submitter && typeof submitter.closest === 'function' ? submitter.closest('[data-turbo]') : null) || form.closest('[data-turbo]');
        const turboOff = Boolean(container) && container.getAttribute('data-turbo') === 'false';
        const method = String(attribute('method') || 'get').toLowerCase();
        const target = attribute('target');

        if (!turboOff || method !== 'get' || (target && target !== '_self')) {
            return;
        }

        let url;

        try {
            url = new URL(attribute('action') || href(), href());
            const view = document.defaultView || window;
            const data = new view.FormData(form);

            if (submitter && submitter.name) {
                data.append(submitter.name, submitter.value || '');
            }

            url.search = new view.URLSearchParams(data).toString();
        } catch (e) {
            return;
        }

        if (url.protocol !== 'http:' && url.protocol !== 'https:') {
            return;
        }

        event.preventDefault();
        leavingByBrowser = top;
        location().replace(url.href);
    }

    function onPageHide() {
        if (leavingByBrowser !== null && top === leavingByBrowser) {
            releaseTop('leave');
        }

        leavingByBrowser = null;
    }

    // ---- Esc ------------------------------------------------------------------------------------------------------

    function foreignModalDialog() {
        let dialog = null;

        try {
            dialog = document.querySelector('dialog:modal');
        } catch (e) {
            return false;
        }

        return Boolean(dialog) && !(top && top.element && (top.element === dialog || (typeof dialog.contains === 'function' && dialog.contains(top.element))));
    }

    function onKeydown(event) {
        if (event.key !== 'Escape' || event.defaultPrevented || !top || foreignModalDialog()) {
            return;
        }

        event.preventDefault();
        requestClose(top.id);
    }

    // ---- API ------------------------------------------------------------------------------------------------------

    const api = {
        pauseTurboHistory(owner) {
            pause(owner);
        },

        resumeTurboHistory(owner) {
            resume(owner);
        },

        open,

        requestClose,

        isOpen(id) {
            return id === undefined ? top !== null : top !== null && top.id === id;
        },

        current() {
            return top ? top.id : null;
        },

        lastLayerBackAt() {
            return lastBackAt;
        },

        pushPageState(owner, url) {
            pause(owner);
            const entry = state() && state().turbo;
            const index = entry && typeof entry.restorationIndex === 'number' ? entry.restorationIndex : (noted ? noted.currentIndex : 0);
            history().pushState({ turbo: { restorationIdentifier: uuid(window), restorationIndex: index + 1 }, [PAGE_STATE]: owner }, '', url);
        },

        replacePageState(owner, url) {
            pause(owner);
            const entry = state() && state().turbo;
            history().replaceState({
                turbo: {
                    restorationIdentifier: entry && entry.restorationIdentifier ? entry.restorationIdentifier : uuid(window),
                    restorationIndex: entry && typeof entry.restorationIndex === 'number' ? entry.restorationIndex : (noted ? noted.currentIndex : 0),
                },
                [PAGE_STATE]: owner,
            }, '', url === undefined ? href() : url);
        },

        visitFromLayer(url, options = {}) {
            const visitOptions = holdsEntry() ? { ...options, action: 'replace' } : { ...options };
            const session = turbo();

            if (session && typeof session.visit === 'function') {
                session.visit(url, visitOptions);

                return;
            }

            if (holdsEntry()) {
                location().replace(String(url));
            } else {
                location().assign(String(url));
            }
        },

        closeThen(fn) {
            if (!top) {
                fn();

                return;
            }

            thens.push(fn);
            requestClose(top.id);
        },

        closeForCache() {
            restoreMarkers();
            const layer = top;
            top = null;
            clearPending();

            if (layer && layer.entry) {
                resume(LAYER_OWNER);
            }

            callOnClose(layer, 'cache');
            const left = leaving;
            leaving = [];
            left.forEach((released) => callOnClose(released, 'cache'));
        },

        bindDialog(dialog, id) {
            const onCancel = (event) => {
                event.preventDefault();
                requestClose(id);
            };
            const onClose = () => {
                // Closed by something else than this module (form method=dialog, dialog.close() of a control)
                if (top && top.id === id) {
                    requestClose(id);
                }
            };

            dialog.addEventListener('cancel', onCancel);
            dialog.addEventListener('close', onClose);

            return () => {
                dialog.removeEventListener('cancel', onCancel);
                dialog.removeEventListener('close', onClose);
            };
        },

        install() {
            if (installed) {
                return api;
            }

            installed = true;
            // Capture at the window: runs before Turbo's popstate listener and nav_instrumentation's
            window.addEventListener('popstate', onPopState, { capture: true });
            document.addEventListener('turbo:click', onTurboClick);
            document.addEventListener('turbo:submit-start', onSubmitStart);
            document.addEventListener('turbo:visit', onTurboVisit);
            document.addEventListener('turbo:load', onTurboLoad);
            document.addEventListener('turbo:render', onTurboRender);
            document.addEventListener('click', onDocumentClick);
            document.addEventListener('keydown', onKeydown);
            window.addEventListener('pagehide', onPageHide);
            document.addEventListener('submit', onSubmit);

            return api;
        },
    };

    return api;
}

let browserInstance = null;

function instance() {
    if (browserInstance === null) {
        browserInstance = createLayerHistory({ window, document }).install();
    }

    return browserInstance;
}

/** The browser instance (assets/app.js imports this module so its listeners exist on every page) */
const layers = {
    pauseTurboHistory: (owner) => instance().pauseTurboHistory(owner),
    resumeTurboHistory: (owner) => instance().resumeTurboHistory(owner),
    open: (id, options) => instance().open(id, options),
    requestClose: (id) => instance().requestClose(id),
    isOpen: (id) => instance().isOpen(id),
    current: () => instance().current(),
    lastLayerBackAt: () => instance().lastLayerBackAt(),
    pushPageState: (owner, url) => instance().pushPageState(owner, url),
    replacePageState: (owner, url) => instance().replacePageState(owner, url),
    visitFromLayer: (url, options) => instance().visitFromLayer(url, options),
    closeThen: (fn) => instance().closeThen(fn),
    closeForCache: () => instance().closeForCache(),
    bindDialog: (dialog, id) => instance().bindDialog(dialog, id),
};

if (typeof window !== 'undefined' && typeof document !== 'undefined' && window.history && typeof window.addEventListener === 'function') {
    instance();
}

export default layers;
