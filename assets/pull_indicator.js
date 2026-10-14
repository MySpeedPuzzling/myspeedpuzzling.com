import { pastThreshold, PULL_TARGET, pullOffset, pullProgress } from './pull_to_refresh.js';

// The installed app's pull indicator per platform (docs/features/navigation README §4.13 G17; requirement GEST-19):
// what controllers/pwa_lifecycle_controller.js draws while a pull follows the finger. GEST-06's thresholds stay
// assets/pull_to_refresh.js's (≈ 128 px of finger travel); only the look differs:
// - Android and other: Material's disc, 40 px - the arrow points down, flips up past the threshold, turns into the
//   spinner after the release (the look it always had, resized from 36 px); it grows from .3 and fades in with the pull;
// - iOS: a bare spinner (no disc, no shadow, no arrow) that fades and turns in with the pull - opacity = the pull's
//   progress, rotate(progress × 270°) - and spins after the release, as the system's own.
// The styles are assets/styles/_pull-to-refresh.scss (the controller injected them as a <style> before GEST-19).
//
// Pure: no DOM access, the device's OS is passed in (assets/platform.js os(), read by the controller). Node-tested in
// tests/pull-indicator-harness.mjs.

/** The disc's diameter (px) - $pwa-ptr-size of _pull-to-refresh.scss */
export const INDICATOR_SIZE = 40;
/** The indicator stops this far past the refresh point (the finger may go on) */
export const INDICATOR_OVERSHOOT = 20;
/** How far the iOS spinner turns over a whole pull */
export const SPINNER_TURN_DEGREES = 270;

export const DISC = 'disc';
export const SPINNER = 'spinner';
/** On the indicator for the iOS look */
export const IOS_CLASS = 'is-ios';

/** The look for the device's OS: the bare spinner on iOS, the Material disc everywhere else */
export function lookFor(device) {
    return device === 'ios' ? SPINNER : DISC;
}

/** The indicator's class list for a look */
export function indicatorClass(look) {
    return look === SPINNER ? `pwa-ptr-indicator ${IOS_CLASS}` : 'pwa-ptr-indicator';
}

// The iOS activity indicator: eight spokes, the brightest leading, the others trailing dimmer (it spins clockwise in
// eight steps after the release)
function spokes() {
    let lines = '';

    for (let k = 0; k < 8; k++) {
        const opacity = Math.round((1 - ((8 - k) % 8) * 0.11) * 100) / 100;
        lines += `<line x1="12" y1="2.5" x2="12" y2="7" transform="rotate(${k * 45} 12 12)" opacity="${opacity}"/>`;
    }

    return lines;
}

/** The indicator's inner markup: the arrow + the spinner of the disc, or the iOS spinner alone (no arrow element) */
export function indicatorMarkup(look) {
    if (look === SPINNER) {
        return `<svg class="pwa-ptr-ios-spinner" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.25" stroke-linecap="round" aria-hidden="true">${spokes()}</svg>`;
    }

    return '<svg class="pwa-ptr-arrow" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
        + '<line x1="12" y1="5" x2="12" y2="19"/><polyline points="19 12 12 19 5 12"/></svg>'
        + '<svg class="pwa-ptr-spinner" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9"/></svg>';
}

function round(value) {
    return Math.round(value * 1000) / 1000;
}

/**
 * One frame of a pull `distance` px long: the indicator's transform and opacity, the arrow's or the spinner's turn
 * (null where the look has none) and whether a release here refreshes.
 */
export function pullFrame(look, distance) {
    const progress = pullProgress(distance);
    const past = pastThreshold(distance);
    // Slides down from under the top bar, capped slightly past the refresh point
    const translateY = round(Math.min(pullOffset(distance), PULL_TARGET + INDICATOR_OVERSHOOT) - INDICATOR_SIZE / 2);

    if (look === SPINNER) {
        return {
            transform: `translateY(${translateY}px)`,
            opacity: round(progress),
            arrow: null,
            spinner: `rotate(${round(progress * SPINNER_TURN_DEGREES)}deg)`,
            past,
        };
    }

    return {
        transform: `translateY(${translateY}px) scale(${round(0.3 + progress * 0.7)})`,
        opacity: round(Math.min(progress * 1.5, 1)),
        // Points down, flips up past the threshold
        arrow: `rotate(${past ? 180 : 0}deg)`,
        spinner: null,
        past,
    };
}
