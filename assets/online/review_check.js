/**
 * The completeness check in the review of an online round (docs/features/online-competitions/README.md §5.8, §5.9 -
 * Phase 3, advisory: it orders the queue and marks the photos, a person decides) - pure, no DOM, pinned by
 * tests/online-review-check-harness.mjs. A photo's `check` comes from the server (OnlineReview: OnlineFinishPhotoCheck)
 * only while the check is on (`round.completenessCheck`); without it the review is what it was before.
 *
 * - checkBadge(): the badge of a photo's check - "Looks complete" / "Gap?" / "Not sure" / "Not checked" (pending, or a
 *   check that could not run - its own title says so);
 * - gapBoxes(): the suspected gaps (boxes in the photo's own pixels, OnlineFinishPhotoCheck imageWidth × imageHeight -
 *   else the image's natural size) as percentages of the photo, clipped to it - laid over the shown photo they scale
 *   with it, whatever size it is shown at;
 * - reviewOrder(): "needs a look" first while the check is on - the participants not confirmed yet by the check of the
 *   photo the review opens on: gaps, then not sure, then not checked (pending, failed, no photo), then looks complete,
 *   each group in the list's own order (by name); confirmed participants keep their place in the list.
 */

export const CHECK_STATES = ['complete', 'gaps', 'unsure', 'pending', 'failed'];

// "Needs a look" first: the lower, the earlier
export const CHECK_RANKS = { gaps: 0, unsure: 1, pending: 2, failed: 2, complete: 3 };
const NOT_CHECKED_RANK = 2;

/**
 * @param {{state?: string}|null|undefined} check
 * @returns {{state: string, text: string, title: string, tone: 'complete'|'gaps'|'unsure'|'pending'}|null}
 *          text / title: keys of the review's texts (review_texts.html.twig); null without a check
 */
export function checkBadge(check) {
    const state = check !== null && typeof check === 'object' ? check.state : null;

    if (!CHECK_STATES.includes(state)) {
        return null;
    }

    // A check that could not run reads like one not done yet - a person looks either way
    const shown = state === 'failed' ? 'pending' : state;
    let title = 'check_title';

    if (state === 'failed') {
        title = 'check_failed_title';
    } else if (state === 'pending') {
        title = 'check_pending_title';
    }

    return { state, text: `check_${shown}`, title, tone: shown };
}

/**
 * The suspected gaps of a `gaps` check as boxes in percent of the photo - [] for any other check or without a size.
 *
 * @param {{state?: string, gaps?: Array<{x: number, y: number, w: number, h: number}>, imageWidth?: number|null, imageHeight?: number|null}|null} check
 * @param {number|null} naturalWidth  the shown image's own width, when the check does not say
 * @param {number|null} naturalHeight
 * @returns {Array<{left: number, top: number, width: number, height: number, centerX: number, centerY: number}>} percentages, 3 decimals
 */
export function gapBoxes(check, naturalWidth = null, naturalHeight = null) {
    if (check === null || typeof check !== 'object' || check.state !== 'gaps' || !Array.isArray(check.gaps)) {
        return [];
    }

    const width = positive(check.imageWidth) ?? positive(naturalWidth);
    const height = positive(check.imageHeight) ?? positive(naturalHeight);

    if (width === null || height === null) {
        return [];
    }

    const percent = (value, of) => Math.round((value / of) * 100_000) / 1000;
    const boxes = [];

    for (const gap of check.gaps) {
        if (gap === null || typeof gap !== 'object' || ![gap.x, gap.y, gap.w, gap.h].every(Number.isFinite) || gap.w <= 0 || gap.h <= 0) {
            continue;
        }

        const left = Math.max(0, gap.x);
        const top = Math.max(0, gap.y);
        const right = Math.min(width, gap.x + gap.w);
        const bottom = Math.min(height, gap.y + gap.h);

        if (right <= left || bottom <= top) {
            continue;
        }

        boxes.push({
            left: percent(left, width),
            top: percent(top, height),
            width: percent(right - left, width),
            height: percent(bottom - top, height),
            // Its middle - the page draws a box at least a finger wide around it: a piece of a 1000-piece puzzle is a
            // few pixels in the review's photo
            centerX: percent((left + right) / 2, width),
            centerY: percent((top + bottom) / 2, height),
        });
    }

    return boxes;
}

/**
 * The list in the order the reviewer works through it. Off: as it came (by name). On: the participants not confirmed
 * yet move by their check (CHECK_RANKS; none = not checked) within the places they take; a confirmed one keeps its
 * place.
 *
 * @template {{status: string, check?: {state?: string}|null}} T
 * @param {T[]} entries
 * @param {boolean} checkOn
 * @returns {T[]}
 */
export function reviewOrder(entries, checkOn) {
    const list = Array.isArray(entries) ? entries.filter((entry) => entry !== null && typeof entry === 'object') : [];

    if (checkOn !== true) {
        return list;
    }

    const open = list
        .map((entry, index) => ({ entry, index }))
        .filter(({ entry }) => entry.status !== 'confirmed')
        .sort((a, b) => rankOf(a.entry.check) - rankOf(b.entry.check) || a.index - b.index)
        .map(({ entry }) => entry);
    let next = 0;

    return list.map((entry) => (entry.status === 'confirmed' ? entry : open[next++]));
}

function rankOf(check) {
    const state = check !== null && typeof check === 'object' ? check.state : null;

    return Object.hasOwn(CHECK_RANKS, state ?? '') ? CHECK_RANKS[state] : NOT_CHECKED_RANK;
}

function positive(value) {
    return Number.isFinite(value) && value > 0 ? value : null;
}
