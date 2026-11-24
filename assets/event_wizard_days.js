/**
 * The event creation wizard's round starts (Structure step, spws-demo-ux-leftovers.md §7): every round's day is the
 * organiser's - nothing is made up (creation-wizard.md) - but a day picked in one row carries down to the rows after it
 * that are still empty or hold a day carried there before (never one the organiser picked), up to the first row the
 * organiser picked themselves. So a several-day event takes one pick per day: the first row fills them all, the first
 * round of day 2 refills the rest, and so on. The server checks every row as before.
 *
 * Pure function - tests/event-wizard-days-harness.mjs runs it under node; event_wizard_days_controller.js applies it.
 *
 * @param {string[]} days every row's day as shown ("" = empty), in the rows' order
 * @param {number} changed the row the organiser just picked a day in
 * @param {boolean[]} [carried] which rows hold a carried day (not the organiser's own pick)
 * @returns {{days: string[], carried: boolean[]}}
 */
export function carryDay(days, changed, carried = []) {
    const list = Array.isArray(days) ? days.map((day) => String(day ?? '')) : [];
    const marks = list.map((_, index) => carried[index] === true);
    const day = list[changed] ?? '';

    if (!Number.isInteger(changed) || changed < 0 || changed >= list.length) {
        return { days: list, carried: marks };
    }

    // The organiser's own pick (also a cleared row - it is theirs now)
    marks[changed] = false;

    if (!/^\d{4}-\d{2}-\d{2}$/.test(day)) {
        return { days: list, carried: marks };
    }

    for (let index = changed + 1; index < list.length; index++) {
        if (list[index].trim() !== '' && marks[index] === false) {
            break;
        }

        list[index] = day;
        marks[index] = true;
    }

    return { days: list, carried: marks };
}
