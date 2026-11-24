/**
 * The event creation wizard's round starts (Structure step, spws-demo-ux-leftovers.md §7): every round's day is the
 * organiser's - nothing is made up (creation-wizard.md) - but a day picked in one row carries down to the rows after it
 * that are still empty, so a several-day event takes one pick per day instead of one per round. A row typed already is
 * never changed; the server checks every row as before.
 *
 * Pure function - tests/event-wizard-days-harness.mjs runs it under node; event_wizard_days_controller.js applies it.
 *
 * @param {string[]} days every row's day as typed ("" = empty), in the rows' order
 * @param {number} changed the row the organiser just picked a day in
 * @returns {string[]} the days after the carry
 */
export function carryDay(days, changed) {
    const list = Array.isArray(days) ? days.map((day) => String(day ?? '')) : [];
    const day = list[changed] ?? '';

    if (!Number.isInteger(changed) || changed < 0 || !/^\d{4}-\d{2}-\d{2}$/.test(day)) {
        return list;
    }

    for (let index = changed + 1; index < list.length; index++) {
        if (list[index].trim() !== '') {
            break;
        }

        list[index] = day;
    }

    return list;
}
