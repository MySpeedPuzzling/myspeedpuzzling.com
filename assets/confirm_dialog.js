import { isNativeApp, os } from './platform.js';
import layers, { LAYER_CLOSED_EVENT } from './layer_history.js';

/**
 * In-page confirmations (docs/features/navigation README §4.13 G13, requirement GEST-13, implementation plan decision 60).
 *
 * iOS Safari silently refuses native dialogs after a Back to a pushState entry (hotwired/turbo#637): the answer is an
 * instant `false`, nothing is shown. GEST-01's layer entries put iPhones into exactly that state, so no player- or
 * organiser-facing page asks through the native window.confirm any more:
 *
 * - confirmDialog(message, {danger, actionLabel, returnFocusTo}) → Promise<boolean>: MSP's own modal <dialog>, cloned
 *   from <template id="msp-confirm-template"> (base.html.twig, labels confirm_dialog.cancel / .confirm in the page's
 *   language) and opened with showModal(). Cancel on the left, the action on the right (btn-danger with `danger`); Esc
 *   and a tap on the scrim answer false; focus goes into the dialog and back to the trigger explicitly (iOS does not
 *   focus tapped buttons, so the dialog's own focus restore would land on <body>). While a Bootstrap modal is open the
 *   dialog is appended inside it (its focus trap would pull focus out of a dialog placed elsewhere). It is **not** a
 *   history layer (a pushed entry would recreate #637): a popstate, a layer closing (msp:layer-closed - a layer's
 *   Back never reaches the popstate listener), turbo:before-visit, turbo:before-cache or the Bootstrap modal around it
 *   hiding answers an open one false and removes it. Esc is taken in a window capture
 *   listener, so neither the layer helper's Escape (assets/layer_history.js), a Bootstrap modal nor a page's own
 *   Escape handling sees it.
 * - install({Turbo, report}) sets Turbo.config.forms.confirm: every `data-turbo-confirm` form (or submitter) asks here,
 *   `data-turbo-confirm-danger` on the submitter or the form = danger style, `data-turbo-confirm-action` on the
 *   submitter = the action button's label.
 * - guardLeave(event, message) for the synchronous turbo:before-visit guards of the tool pages: prevents the visit,
 *   asks, and on yes visits the URL again with a one-shot bypass (a replace visit while a layer holds the entry -
 *   layers.visitFromLayer()). `beforeunload` stays browser UI: a real unload cannot be answered in the page.
 * - the watchdog wraps window.confirm: a `false` within 50 ms on an iPhone (os() === 'ios') sends one
 *   `dialog_suppressed` P0-03 event per page view {afterLayerBack, afterRestore} - a native dialog that is still
 *   reachable and was refused.
 *   afterRestore = the page was loaded by a history traversal (decision 102; GEST-15 hands in lastRestoreKind()).
 *
 * Pure factory: createConfirmDialog(env) takes {window, document, layers?, now?, restoreKind?} so
 * tests/confirm-dialog-harness.mjs runs it under node; the named exports below are the browser instance.
 */

export const TEMPLATE_ID = 'msp-confirm-template';

/** A native confirm dialog answering `false` this fast was never shown to anybody */
export const SUPPRESSED_WITHIN_MS = 50;

const FALLBACK_LABELS = { cancel: 'Cancel', confirm: 'OK' };
const WATCHED = '__mspConfirmWatchdog';

/**
 * The watchdog's decision (native-aware, tests/native-step-aside-harness.mjs): report this native confirm answer as
 * suppressed? Only on an iPhone in a browser, only a `false` that came at once, once per page view. Inside the app the
 * P0-03 channel is off (the app's navigation is native) - never.
 */
export function reportsSuppressed(nativeApp, deviceOs, answer, elapsedMs, alreadyReported) {
    return !nativeApp
        && deviceOs === 'ios'
        && answer === false
        && typeof elapsedMs === 'number'
        && elapsedMs <= SUPPRESSED_WITHIN_MS
        && !alreadyReported;
}

/** Until GEST-15 (decision 102): the page was loaded by a Back/Forward (app.js turns a Turbo restore into a reload) */
export function loadedByTraverse(window) {
    try {
        const activation = window && window.navigation && window.navigation.activation;

        return Boolean(activation && activation.navigationType === 'traverse');
    } catch (e) {
        return false;
    }
}

function hasAttribute(element, name) {
    return Boolean(element) && typeof element.hasAttribute === 'function' && element.hasAttribute(name);
}

export function createConfirmDialog(env) {
    const window = env.window;
    const document = env.document;
    const layerHistory = env.layers || null;
    const now = env.now || (() => (window.performance && typeof window.performance.now === 'function' ? window.performance.now() : Date.now()));
    const restoreKind = env.restoreKind || (() => loadedByTraverse(window));

    // The question on screen: {dialog, host, trigger, resolve, cleanup}
    let open = null;
    let sequence = 0;
    // A visit started by guardLeave() after a yes: the guards let it through
    let leaving = false;
    let installed = false;
    let report = null;
    // One dialog_suppressed line per page view
    let reported = false;

    function root() {
        return document ? document.documentElement : null;
    }

    function lastLayerBackAt() {
        try {
            return layerHistory && typeof layerHistory.lastLayerBackAt === 'function' ? layerHistory.lastLayerBackAt() : null;
        } catch (e) {
            return null;
        }
    }

    // ---- the dialog --------------------------------------------------------------------------------------------------

    function fallbackDialog() {
        const dialog = document.createElement('dialog');
        dialog.className = 'msp-confirm';
        dialog.setAttribute('role', 'alertdialog');
        dialog.innerHTML = '<div class="msp-confirm-box"><p class="msp-confirm-message" data-msp-confirm-message></p>'
            + '<div class="msp-confirm-actions">'
            + '<button type="button" class="btn btn-outline-secondary" data-msp-confirm-cancel></button>'
            + '<button type="button" class="btn btn-primary" data-msp-confirm-action></button>'
            + '</div></div>';
        dialog.querySelector('[data-msp-confirm-cancel]').textContent = FALLBACK_LABELS.cancel;
        dialog.querySelector('[data-msp-confirm-action]').textContent = FALLBACK_LABELS.confirm;

        return dialog;
    }

    function build(message, danger, actionLabel) {
        const template = document.getElementById(TEMPLATE_ID);
        const source = template && template.content ? template.content.querySelector('dialog') : null;
        const dialog = source ? source.cloneNode(true) : fallbackDialog();
        const text = dialog.querySelector('[data-msp-confirm-message]');
        const cancel = dialog.querySelector('[data-msp-confirm-cancel]');
        const action = dialog.querySelector('[data-msp-confirm-action]');
        const id = 'msp-confirm-message-' + (++sequence);

        // Plain text: a message can name a player
        text.textContent = String(message ?? '');
        text.id = id;
        dialog.setAttribute('aria-labelledby', id);

        if (typeof actionLabel === 'string' && actionLabel.trim() !== '') {
            action.textContent = actionLabel.trim();
        }

        if (danger) {
            action.classList.remove('btn-primary');
            action.classList.add('btn-danger');
        }

        return { dialog, cancel, action };
    }

    /** The open Bootstrap modal around the trigger, else the last one open, else <body> */
    function hostFor(trigger) {
        const own = trigger && typeof trigger.closest === 'function' ? trigger.closest('.modal.show') : null;

        if (own) {
            return own;
        }

        const shown = document.querySelectorAll('.modal.show');

        return shown.length > 0 ? shown[shown.length - 1] : document.body;
    }

    function focus(element) {
        if (!element || typeof element.focus !== 'function') {
            return false;
        }

        try {
            element.focus({ preventScroll: true });
        } catch (e) {
            return false;
        }

        return document.activeElement === element;
    }

    function restoreFocus(trigger) {
        if (!trigger || trigger.isConnected === false || focus(trigger)) {
            return;
        }

        // A dropdown item: its menu closed meanwhile - the toggle that opened it
        const dropdown = typeof trigger.closest === 'function' ? trigger.closest('.dropdown, .dropup, .btn-group') : null;
        const toggle = dropdown ? dropdown.querySelector('[data-bs-toggle="dropdown"]') : null;

        focus(toggle);
    }

    function finish(answer) {
        const current = open;

        if (!current) {
            return;
        }

        open = null;
        current.cleanup();

        try {
            if (current.dialog.open && typeof current.dialog.close === 'function') {
                current.dialog.close();
            }
        } catch (e) {
            // closed already
        }

        current.dialog.remove();
        restoreFocus(current.trigger);
        current.resolve(answer === true);
    }

    function confirmDialog(message, options = {}) {
        // A newer question replaces one still open
        finish(false);

        const danger = options.danger === true;
        const trigger = options.returnFocusTo || document.activeElement || null;
        const { dialog, cancel, action } = build(message, danger, options.actionLabel);
        const host = hostFor(trigger);

        return new Promise((resolve) => {
            let pointerDownOnScrim = false;

            const onCancelClick = () => finish(false);
            const onActionClick = () => finish(true);
            // Esc (or the Android Back gesture's close request) on the dialog itself
            const onCancelEvent = (event) => {
                event.preventDefault();
                finish(false);
            };
            // Closed by something else than this module
            const onClose = () => finish(false);
            // The scrim: a click whose press also started on the dialog element itself (outside the box)
            const onPointerDown = (event) => {
                pointerDownOnScrim = event.target === dialog;
            };
            const onClick = (event) => {
                if (event.target === dialog && pointerDownOnScrim) {
                    finish(false);
                }

                pointerDownOnScrim = false;
            };
            // Esc before anybody else: layer_history's Escape, the Bootstrap modal around and the page's own keys
            const onKeydown = (event) => {
                if (event.key !== 'Escape' && event.key !== 'Esc') {
                    return;
                }

                event.preventDefault();
                event.stopImmediatePropagation();
                finish(false);
            };

            cancel.addEventListener('click', onCancelClick);
            action.addEventListener('click', onActionClick);
            dialog.addEventListener('cancel', onCancelEvent);
            dialog.addEventListener('close', onClose);
            dialog.addEventListener('pointerdown', onPointerDown);
            dialog.addEventListener('click', onClick);
            window.addEventListener('keydown', onKeydown, true);

            open = {
                dialog,
                host,
                trigger,
                resolve,
                cleanup() {
                    cancel.removeEventListener('click', onCancelClick);
                    action.removeEventListener('click', onActionClick);
                    dialog.removeEventListener('cancel', onCancelEvent);
                    dialog.removeEventListener('close', onClose);
                    dialog.removeEventListener('pointerdown', onPointerDown);
                    dialog.removeEventListener('click', onClick);
                    window.removeEventListener('keydown', onKeydown, true);
                },
            };

            host.appendChild(dialog);

            try {
                dialog.showModal();
            } catch (e) {
                dialog.setAttribute('open', '');
            }

            // Destructive: the safe answer has the focus; otherwise the action (Enter confirms, as a native confirm)
            focus(danger ? cancel : action);
        });
    }

    // ---- Turbo -------------------------------------------------------------------------------------------------------

    /** Turbo.config.forms.confirm(message, form, submitter) */
    function turboConfirm(message, form, submitter) {
        const actionLabel = submitter && submitter.dataset ? submitter.dataset.turboConfirmAction : undefined;
        const returnFocusTo = submitter || (form && typeof form.querySelector === 'function' ? form.querySelector('[type="submit"], button:not([type])') : null) || null;

        return confirmDialog(message, {
            danger: hasAttribute(submitter, 'data-turbo-confirm-danger') || hasAttribute(form, 'data-turbo-confirm-danger'),
            actionLabel,
            returnFocusTo,
        });
    }

    /** A visit to `url` the leave guards let through (a replace while a layer holds the entry) */
    function leaveTo(url) {
        leaving = true;

        try {
            if (layerHistory && typeof layerHistory.visitFromLayer === 'function') {
                layerHistory.visitFromLayer(url);
            } else if (window.Turbo && typeof window.Turbo.visit === 'function') {
                window.Turbo.visit(url);
            } else {
                window.location.assign(String(url));
            }
        } finally {
            leaving = false;
        }
    }

    /**
     * For a synchronous turbo:before-visit guard: `if (unsaved) guardLeave(event, message)`. Stops the visit, asks, and
     * visits the URL again on yes. Returns true when the visit may go on (the guard's own second pass).
     */
    function guardLeave(event, message, options = {}) {
        if (leaving) {
            return true;
        }

        const url = event && event.detail ? event.detail.url : null;

        event.preventDefault();

        confirmDialog(message, { danger: options.danger !== false, actionLabel: options.actionLabel }).then((yes) => {
            if (yes && url) {
                leaveTo(url);
            }
        });

        return false;
    }

    // ---- the watchdog ------------------------------------------------------------------------------------------------

    function installWatchdog() {
        const original = window.confirm;

        if (typeof original !== 'function' || original[WATCHED] === true) {
            return;
        }

        const watched = function watchedConfirm(...args) {
            const started = now();
            const answer = original.apply(window, args);

            try {
                if (reportsSuppressed(isNativeApp(root()), os(root()), answer, now() - started, reported)) {
                    reported = true;

                    if (typeof report === 'function') {
                        report({ afterLayerBack: lastLayerBackAt() !== null, afterRestore: restoreKind() });
                    }
                }
            } catch (e) {
                // A lost report, never a broken confirm
            }

            return answer;
        };

        watched[WATCHED] = true;
        window.confirm = watched;
    }

    // ---- install -----------------------------------------------------------------------------------------------------

    const api = {
        confirmDialog,
        turboConfirm,
        guardLeave,
        leaveTo,

        isOpen() {
            return open !== null;
        },

        install(options = {}) {
            if (installed) {
                return api;
            }

            installed = true;
            report = typeof options.report === 'function' ? options.report : null;

            const turbo = options.Turbo || window.Turbo;

            if (turbo && turbo.config && turbo.config.forms) {
                turbo.config.forms.confirm = turboConfirm;
            }

            // Capture at the window: before any page guard opens a new question in the same dispatch
            const cancelOpen = () => finish(false);
            window.addEventListener('popstate', cancelOpen, true);
            window.addEventListener('turbo:before-visit', cancelOpen, true);
            window.addEventListener('turbo:before-cache', cancelOpen, true);
            // A Back that closes a layer never reaches the popstate listener above (layer_history stops it at the
            // window first): the layer's close is announced instead (review F2)
            document.addEventListener(LAYER_CLOSED_EVENT, cancelOpen);
            // The Bootstrap modal holding the dialog goes away (its own × / Back closing the layer)
            document.addEventListener('hide.bs.modal', (event) => {
                if (open && open.host === event.target) {
                    finish(false);
                }
            });
            // A new page view
            document.addEventListener('turbo:visit', () => {
                reported = false;
            });

            installWatchdog();

            return api;
        },
    };

    return api;
}

let browserInstance = null;

function instance() {
    if (browserInstance === null) {
        browserInstance = createConfirmDialog({ window, document, layers });
    }

    return browserInstance;
}

/** Ask in MSP's in-page dialog: resolves true for the action, false for Cancel / Esc / the scrim / leaving the page */
export function confirmDialog(message, options) {
    return instance().confirmDialog(message, options);
}

/** A synchronous turbo:before-visit guard: prevents the visit, asks, visits again on yes */
export function guardLeave(event, message, options) {
    return instance().guardLeave(event, message, options);
}

/** The visit after a yes, let through by every leave guard */
export function leaveTo(url) {
    return instance().leaveTo(url);
}

/** assets/app.js: Turbo's confirm method, the cancel listeners and the watchdog */
export function installConfirmDialog(options) {
    return instance().install(options);
}
