/**
 * The listings a buyer ticked on sell/swap lists and the marketplace (docs/features/marketplace/12-listing-requests.md),
 * kept across pages, filters and "Show more" in sessionStorage - one key per signed-in player, so the picks of a
 * seller's list and of the marketplace end up in one sheet. Plain module, no DOM: tests/ListingSelectionStoreScriptTest.php
 * runs it under node with a fake storage.
 *
 * Entries are {id, sellerId, at}: the seller only counts sellers for the bar, the server decides everything again. A
 * pick older than a day is dropped (a tab left open overnight), at most MAX_ENTRIES are kept. Storage that throws
 * (private mode, quota) leaves a selection that lives on this page only - never an error.
 */

export const MAX_AGE_MS = 24 * 60 * 60 * 1000;
export const MAX_ENTRIES = 30;

export function createListingSelectionStore(storage, key, now = () => Date.now()) {
    let entries = load();

    function load() {
        let parsed = [];

        try {
            const raw = storage?.getItem(key);
            parsed = raw ? JSON.parse(raw) : [];
        } catch {
            parsed = [];
        }

        if (!Array.isArray(parsed)) {
            return [];
        }

        const fresh = now() - MAX_AGE_MS;

        return parsed
            .filter((entry) => entry !== null && typeof entry === 'object' && typeof entry.id === 'string' && typeof entry.sellerId === 'string' && typeof entry.at === 'number' && entry.at >= fresh)
            .slice(-MAX_ENTRIES);
    }

    function save() {
        try {
            if (entries.length === 0) {
                storage?.removeItem(key);
            } else {
                storage?.setItem(key, JSON.stringify(entries));
            }
        } catch {
            // The selection still works on this page
        }
    }

    return {
        ids() {
            return entries.map((entry) => entry.id);
        },

        has(id) {
            return entries.some((entry) => entry.id === id);
        },

        size() {
            return entries.length;
        },

        sellerCount() {
            return new Set(entries.map((entry) => entry.sellerId)).size;
        },

        // False = the selection is full (MAX_ENTRIES) and nothing was added
        add(id, sellerId) {
            if (entries.some((entry) => entry.id === id)) {
                return true;
            }

            if (entries.length >= MAX_ENTRIES) {
                return false;
            }

            entries.push({ id, sellerId, at: now() });
            save();

            return true;
        },

        remove(id) {
            entries = entries.filter((entry) => entry.id !== id);
            save();
        },

        clear() {
            entries = [];
            save();
        },

    };
}
