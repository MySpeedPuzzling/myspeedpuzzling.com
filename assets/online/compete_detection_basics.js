/**
 * The little of the participant page's finish detection that assets/controllers/compete_controller.js needs before
 * the detection's own chunk is there (docs/features/online-competitions/README.md §5.7, implementation-plan.md WP 2.1 -
 * 2.4): the round's detection mode (live from the state) and when the chunk - with the detector worker, MediaPipe and
 * its models behind it - is loaded at all. Everything else lives in compete_detection.js / compete_detection_page.js,
 * in a chunk of their own, so the page of a round without detection carries none of it. Pure - pinned by
 * tests/online-detection-harness.mjs.
 */

export const MODE_OFF = 'off';
export const MODE_SHADOW = 'shadow';
export const MODE_ON = 'on';

// A chunk that did not load (offline, a deploy in between) is asked for again after these waits
export const LOAD_RETRY_MS = [2000, 5000, 10000, 20000, 30000];

/**
 * The round's detection mode (the online timing page's "Finish", the state's settings) - `off` for anything else: the
 * Finished button always works.
 */
export function detectionMode(state) {
    const mode = state?.round?.settings?.detection;

    return mode === MODE_ON || mode === MODE_SHADOW ? mode : MODE_OFF;
}

/**
 * Load the detection chunk (and with it the detector): a page with the detection parts (the round's detection was on
 * when it loaded - `hasParts`), the mode not `off`, the participant agreed to the photos, this device competes (no
 * organiser's preview, no second screen, a link that still opens), and the set-up checklist or anything after it is
 * reached - the models (~18 MB) are fetched during the checklist (README §5.7 "Downloads"), never on the welcome screen.
 *
 * @param {object} input
 * @param {boolean} input.hasParts
 * @param {object|null} input.state
 * @param {boolean} input.consent
 * @param {boolean} [input.preview]
 * @param {boolean} [input.secondScreen]
 * @param {boolean} [input.linkDead]
 * @param {string} input.screen      compete_flow.js screenFor()
 */
export function detectionLoadWanted({ hasParts, state, consent, preview = false, secondScreen = false, linkDead = false, screen }) {
    if (!hasParts || detectionMode(state) === MODE_OFF || !consent || preview || secondScreen || linkDead) {
        return false;
    }

    return ['checklist', 'waiting', 'countdown', 'running', 'finished'].includes(screen);
}
