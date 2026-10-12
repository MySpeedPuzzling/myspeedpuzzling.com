// The Menu's numbers that are not on the page (docs/features/navigation README §4.12 "Menu data" 3, MENU-01): the
// count of "Events you organize" and of Review your results, from the signed-in route menu_counts. Loaded when the
// Menu is shown - the `menu` page at once; the phone panel and the desktop overlay when they open (MENU-02b, NAV-13:
// fresh, maxAge 0); the docked column at most once per 5 minutes per tab (maxAge TTL_MS) - never with each page view.
// The answer is kept in sessionStorage `msp:menu-counts` = {at, player, data}; another player's entry is never used.
// The count slots are reserved in the markup ([data-menu-count]), so filling them shifts nothing.

export const STORAGE_KEY = 'msp:menu-counts';
export const TTL_MS = 5 * 60 * 1000;

/**
 * The stored answer, if it is this player's and younger than maxAge (pure).
 */
export function cachedCounts(stored, player, now, maxAge) {
    if (!stored || typeof stored !== 'object' || stored.player !== player || typeof stored.at !== 'number') {
        return null;
    }

    if (maxAge <= 0 || now < stored.at || now - stored.at > maxAge) {
        return null;
    }

    return stored.data && typeof stored.data === 'object' ? stored.data : null;
}

/**
 * The slot's text: the number when there is something, nothing otherwise (pure)
 */
export function countText(value) {
    if (typeof value !== 'number' || !Number.isFinite(value) || value <= 0) {
        return '';
    }

    return value > 99 ? '99+' : String(Math.floor(value));
}

export function applyCounts(root, data) {
    root.querySelectorAll('[data-menu-count]').forEach((slot) => {
        slot.textContent = countText(data ? data[slot.dataset.menuCount] : null);
    });
}

function readStored(storage) {
    try {
        return JSON.parse(storage.getItem(STORAGE_KEY) || 'null');
    } catch (error) {
        return null;
    }
}

/**
 * Fills the root's count slots; resolves with the counts, or null when they could not be loaded (the slots stay empty).
 */
export async function loadMenuCounts(root, { url, player, maxAge = 0, storage = window.sessionStorage, fetchImpl = window.fetch.bind(window), now = Date.now } = {}) {
    const cached = cachedCounts(readStored(storage), player, now(), maxAge);

    if (cached) {
        applyCounts(root, cached);

        return cached;
    }

    const response = await fetchImpl(url, { credentials: 'same-origin', headers: { Accept: 'application/json' } });

    if (!response.ok) {
        return null;
    }

    const data = await response.json();

    try {
        storage.setItem(STORAGE_KEY, JSON.stringify({ at: now(), player, data }));
    } catch (error) {
        // Private mode or a full storage: the numbers still show, they are just asked again next time
    }

    applyCounts(root, data);

    return data;
}
