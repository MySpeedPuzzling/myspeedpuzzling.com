// The order of the Menu's groups per surface (docs/features/navigation README §4.12, MENU-02b, decision 29).
//
// The page's one #site-menu is rendered in docked-column order (the column is visible at first paint on desktop).
// The phone panel shows the groups in phone order: they are moved as nodes when the panel opens and moved back when it
// closes - never CSS `order`, so the focus order always follows what is seen (WCAG 2.4.3). Every group carries both
// places: <section class="menu-group" data-order-phone="…" data-order-dock="…">.
//
// Pure: works on whatever element it is given (tests/menu-order-harness.mjs runs it in jsdom). Only the groups that are
// out of place move - each move disconnects and reconnects the Stimulus controllers inside that group.

export const PHONE = 'phone';
export const DOCK = 'dock';

const KEYS = { [PHONE]: 'orderPhone', [DOCK]: 'orderDock' };

function isGroup(element) {
    return Boolean(element && element.classList && element.classList.contains('menu-group'));
}

/** The nav's group sections, in DOM order */
export function groupsOf(nav) {
    return Array.from(nav ? nav.children : []).filter(isGroup);
}

/** A group's place on a surface; a missing or broken value sorts last */
export function orderOf(group, surface) {
    const key = KEYS[surface];
    const raw = key && group && group.dataset ? group.dataset[key] : undefined;
    const value = raw === undefined || raw === null || String(raw).trim() === '' ? NaN : Number(raw);

    return Number.isFinite(value) ? value : Infinity;
}

/** The groups sorted for the surface: by its order value, equal values keep their DOM order (stable) */
export function sortedGroups(groups, surface) {
    return groups
        .map((group, index) => ({ group, index, order: orderOf(group, surface) }))
        .sort((a, b) => (a.order === b.order ? a.index - b.index : (a.order < b.order ? -1 : 1)))
        .map((entry) => entry.group);
}

/**
 * Puts the nav's groups into the surface's order; returns how many groups were moved (0 when already in order).
 * Marks the nav with data-menu-order="phone|dock".
 */
export function applyMenuOrder(nav, surface) {
    if (!nav || !KEYS[surface]) {
        return 0;
    }

    const sorted = sortedGroups(groupsOf(nav), surface);
    let moved = 0;

    sorted.forEach((group, index) => {
        const current = groupsOf(nav);

        if (current[index] !== group) {
            nav.insertBefore(group, current[index]);
            moved += 1;
        }
    });

    nav.setAttribute('data-menu-order', surface);

    return moved;
}
