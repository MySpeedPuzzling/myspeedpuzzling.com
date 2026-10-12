// The bottom bar's label steps (docs/features/navigation README D14; implementation plan NAV-02, decision 95), numbered
// as the requirement: 1 = 11 px, 2 = 10 px, 3 = only the current tab's label. Labels never truncate.
//
// CSS decides first, before first paint: assets/styles/_nav-label-steps.scss holds the measured label widths per
// locale and turns them into container queries on the bar. This module is the browser's second look - a font wider
// than the table assumed (another Japanese font, a fallback when Rubik is not there yet, a bigger root font size): it
// may only raise the step the CSS chose, never lower it. Pure: numbers in, a step out; the DOM side is
// assets/controllers/bottom_nav_controller.js.

export const STEP_11PX = 1;
export const STEP_10PX = 2;
export const STEP_CURRENT_ONLY = 3;

// The step-2 font over the step-1 font (.625rem / .6875rem): both in rem, so the ratio holds at any root font size
const SCALE_TO_STEP_2 = 10 / 11;

// A label fits its tab when its width plus this is at most the room (scrollWidth/clientWidth are whole pixels) - the
// same margin the SCSS table uses ($nav-label-safety)
export const SAFETY_PX = 0.5;

/** The step from an attribute or custom property value: 1, 2 or 3; anything else is 1 */
export function readStep(value) {
    const step = parseInt(String(value === undefined || value === null ? '' : value).trim(), 10);

    return step === STEP_10PX || step === STEP_CURRENT_ONLY ? step : STEP_11PX;
}

/**
 * The step the bar needs: the lowest step whose font lets every label fit the room of a tab, but never below the step
 * the container queries chose.
 *
 * @param {{cssStep: number, room: number, widths: number[]}} measured
 *   cssStep: the step CSS chose (its labels were measured in that step's font);
 *   room: the width a label may use in a tab (the tab's content box), in px;
 *   widths: every label's natural width in px, measured in the cssStep's font (steps 1 and 2 only: in step 3 the other
 *   labels are hidden and nothing is left to raise).
 * @returns {number} 1, 2 or 3
 */
export function chooseStep({ cssStep, room, widths } = {}) {
    const css = readStep(cssStep);

    if (css === STEP_CURRENT_ONLY || !Array.isArray(widths) || widths.length === 0 || !(room > 0)) {
        return css;
    }

    const valid = widths.filter((width) => typeof width === 'number' && Number.isFinite(width) && width >= 0);

    if (valid.length === 0) {
        return css;
    }

    // Widths at 11 px (only known when CSS chose step 1) and at 10 px
    const at11 = css === STEP_11PX ? valid : null;
    const at10 = css === STEP_11PX ? valid.map((width) => width * SCALE_TO_STEP_2) : valid;
    const fits = (list) => list.every((width) => width + SAFETY_PX <= room);

    let needed = STEP_CURRENT_ONLY;

    if (at11 !== null && fits(at11)) {
        needed = STEP_11PX;
    } else if (fits(at10)) {
        needed = STEP_10PX;
    }

    return Math.max(css, needed);
}
