/**
 * When a page of the import may load itself again (data_import_setup_controller.js, `reload_page`) - and when it
 * must not (docs/features/data-import/adding-screens.md "Check and add follows by itself").
 *
 * A reload is the page's own idea, so it never wins against the player's:
 *
 * - **the player is leaving** - a link was clicked, Turbo starts a visit or sends a form, the window unloads. From
 *   that moment nothing is loaded again, whatever answer of a poll still arrives (it would throw the player back
 *   onto this page), and whoever asked is told at once so a request on its way can be aborted;
 * - **the player is in the middle of something here** - they typed, ticked or chose something in a form of the
 *   page, or stand in a field: a small "Refresh" link is shown instead.
 *
 * No Stimulus, no DOM beyond addEventListener - so it runs as it is in tests/import-reload-guard-harness.mjs.
 */
const LEAVING = ['turbo:click', 'turbo:before-visit', 'turbo:visit', 'turbo:submit-start'];
const UNLOADING = ['beforeunload', 'pagehide'];
const TOUCHING = ['input', 'change'];

export function createReloadGuard({ doc = document, win = window } = {}) {
    let leaving = false;
    let touched = false;
    let listeners = [];

    const leave = () => {
        if (leaving) {
            return;
        }

        leaving = true;
        listeners.forEach((listener) => listener());
    };

    // A fetch Turbo makes for a visit (not for a frame of this page) is the page being left too
    const fetching = (event) => {
        if (event && event.target === doc.documentElement) {
            leave();
        }
    };

    const touch = (event) => {
        const target = event ? event.target : null;

        if (target && typeof target.closest === 'function' && target.closest('form')) {
            touched = true;
        }
    };

    LEAVING.forEach((name) => doc.addEventListener(name, leave, true));
    doc.addEventListener('turbo:before-fetch-request', fetching, true);
    UNLOADING.forEach((name) => win.addEventListener(name, leave));
    TOUCHING.forEach((name) => doc.addEventListener(name, touch, true));

    return {
        get leaving() {
            return leaving;
        },

        get touched() {
            return touched;
        },

        /** Told once, the moment the player starts to leave: abort what is on its way */
        whenLeaving(listener) {
            listeners.push(listener);
        },

        /**
         * What to do with a reload the page wants: `no` (the player is leaving, or the page is gone), `refresh` (show
         * the link - the player is busy here), `reload`.
         */
        verdict({ connected = true, stopped = false, activeElement = null } = {}) {
            if (leaving || stopped || !connected) {
                return 'no';
            }

            const inAField = Boolean(activeElement && typeof activeElement.matches === 'function'
                && activeElement.matches('input:not([type="hidden"]), textarea, select'));

            return touched || inAField ? 'refresh' : 'reload';
        },

        dispose() {
            LEAVING.forEach((name) => doc.removeEventListener(name, leave, true));
            doc.removeEventListener('turbo:before-fetch-request', fetching, true);
            UNLOADING.forEach((name) => win.removeEventListener(name, leave));
            TOUCHING.forEach((name) => doc.removeEventListener(name, touch, true));
            listeners = [];
        },
    };
}
