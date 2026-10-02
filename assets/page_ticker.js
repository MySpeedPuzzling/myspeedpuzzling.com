// One timer for the whole page instead of one per label or ring (docs/features/live-activity-feed.md):
// every subscriber returns when it next needs to run (epoch ms, Infinity = only when woken), the
// ticker sleeps until the earliest of those, and runs nothing while the page is hidden. Phones
// freeze hidden pages anyway and desktop browsers keep firing timers for hours, so a hidden tab
// costs nothing here and is caught up the moment it is shown again.

const subscribers = new Set();
let timer = null;

function run() {
    timer = null;

    if (document.visibilityState === 'hidden') {
        return;
    }

    let next = Infinity;

    for (const subscriber of [...subscribers]) {
        // One failing subscriber must not stop the clock for the others
        try {
            next = Math.min(next, subscriber(Date.now()));
        } catch (error) {
            console.error(error);
        }
    }

    if (next !== Infinity) {
        // A few ms late, so the moment a label changes has really passed when it runs
        schedule(Math.max(next - Date.now(), 0) + 5);
    }
}

function schedule(delay) {
    clearTimeout(timer);
    timer = setTimeout(run, delay);
}

/**
 * @param {function(number): number} subscriber called with Date.now(), returns its next wake-up
 * @returns {function(): void} unsubscribe
 */
export function subscribe(subscriber) {
    subscribers.add(subscriber);
    wake();

    return () => {
        subscribers.delete(subscriber);

        if (subscribers.size === 0) {
            clearTimeout(timer);
            timer = null;
        }
    };
}

/** Run every subscriber now, e.g. after a re-render brought new labels. */
export function wake() {
    if (subscribers.size > 0) {
        schedule(0);
    }
}

document.addEventListener('visibilitychange', () => {
    if (document.visibilityState === 'hidden') {
        clearTimeout(timer);
        timer = null;
    } else {
        wake();
    }
});

// Restored from the back/forward cache, or thawed after Chromium froze the page
window.addEventListener('pageshow', wake);
document.addEventListener('resume', wake);
