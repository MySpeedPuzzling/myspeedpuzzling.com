/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus';
import { getComponent } from '@symfony/ux-live-component';
import { subscribe, wake } from '../page_ticker.js';

const JITTER_MS = 5000;
const WATCHDOG_MS = 30000;
const BACKOFF_SECONDS = [60, 120, 300];
const INTERACTION_EVENTS = ['pointerdown', 'pointermove', 'keydown', 'wheel', 'touchstart', 'scroll'];

/**
 * Re-renders the live components inside the element every interval while somebody can see them
 * (docs/features/live-activity-feed.md). The library's own `data-poll` is a bare setInterval:
 * production showed 91 % of the feed's refreshes coming from desktop tabs left open for hours.
 *
 * - only a component that is shown (not in a hidden tab pane) refreshes; one that comes back
 *   overdue refreshes right away
 * - nothing runs while the page is hidden (the page ticker sleeps) or after `idleAfter` without any
 *   interaction - the next touch, key, scroll or return to the tab resumes it
 * - the ring shows the time to the next refresh and is the WCAG 2.2.2 pause / resume button
 * - a failed refresh is retried later (60 → 120 → 300 s) instead of showing the error page
 */
export default class extends Controller {
    static targets = ['ring', 'progress'];

    static values = {
        interval: { type: Number, default: 60000 },
        idleAfter: { type: Number, default: 1800000 },
    };

    initialize() {
        this.feeds = new Map();
        this.paused = false;
        this.lastInteractionAt = Date.now();
        this.resumedFromIdleAt = 0;
        this.look = null;
        this.onInteraction = this.onInteraction.bind(this);
        this.onOnline = () => wake();
    }

    connect() {
        INTERACTION_EVENTS.forEach((type) => document.addEventListener(type, this.onInteraction, { capture: true, passive: true }));
        document.addEventListener('visibilitychange', this.onInteraction);
        window.addEventListener('online', this.onOnline);
        this.unsubscribe = subscribe((now) => this.tick(now));
    }

    disconnect() {
        INTERACTION_EVENTS.forEach((type) => document.removeEventListener(type, this.onInteraction, { capture: true }));
        document.removeEventListener('visibilitychange', this.onInteraction);
        window.removeEventListener('online', this.onOnline);
        this.unsubscribe();
        this.feeds.forEach((feed) => clearTimeout(feed.watchdog));
        this.feeds.clear();
    }

    toggle() {
        const now = Date.now();
        const stalled = [...this.feeds.values()].some((feed) => feed.failures > 0);

        // A click that ends a pause - manual, after being away, or after failures - refreshes now
        if (this.paused || now - this.resumedFromIdleAt < 1000 || stalled || this.state(now) !== 'running') {
            this.paused = false;
            this.lastInteractionAt = now;
            this.feeds.forEach((feed) => {
                feed.failures = 0;
                feed.dueAt = now;
            });
        } else {
            this.paused = true;
        }

        wake();
    }

    tick(now) {
        this.discover(now);

        const state = this.state(now);
        let shown = null;

        for (const feed of this.feeds.values()) {
            if (!feed.element.isConnected) {
                clearTimeout(feed.watchdog);
                this.feeds.delete(feed.element);
                continue;
            }

            if (!isShown(feed.element)) {
                continue;
            }

            shown ??= feed;

            if (
                state === 'running'
                && feed.component !== null
                && !feed.inFlight
                && now >= feed.dueAt
                && !isPlaceholder(feed.element)
                // A refresh would close the row's open actions menu under the visitor's finger
                && feed.element.querySelector('.dropdown-menu.show') === null
            ) {
                this.refresh(feed);
            }
        }

        this.paint(shown, state, now);

        if (state !== 'running') {
            // Woken again by an interaction, the toggle or the browser coming back online
            return Infinity;
        }

        if (shown === null || shown.inFlight) {
            return now + 1000;
        }

        // Whole seconds counted from the last refresh, so the ring moves in even steps
        return Math.min(shown.dueAt, shown.lastRenderAt + (Math.floor((now - shown.lastRenderAt) / 1000) + 1) * 1000);
    }

    discover(now) {
        this.element.querySelectorAll('[data-controller~="live"]').forEach((element) => {
            if (this.feeds.has(element)) {
                return;
            }

            const feed = { element, component: null, lastRenderAt: now, dueAt: this.nextDue(now), inFlight: false, failures: 0, watchdog: null };
            this.feeds.set(element, feed);

            getComponent(element).then((component) => {
                feed.component = component;

                component.on('render:finished', () => {
                    clearTimeout(feed.watchdog);
                    feed.inFlight = false;
                    feed.failures = 0;
                    feed.lastRenderAt = Date.now();
                    feed.dueAt = this.nextDue(feed.lastRenderAt);
                    wake();
                });

                component.on('response:error', (backendResponse, controls) => {
                    if (feed.inFlight) {
                        // Our own background refresh: retried later, never the error page in a modal
                        controls.displayError = false;
                        this.failed(feed);
                    }
                });
            });
        });
    }

    refresh(feed) {
        const { component } = feed;
        const previous = component.backendRequest;

        feed.inFlight = true;
        component.render();

        const request = component.backendRequest;

        if (request && request !== previous) {
            // Live Component never clears a request whose fetch rejected (offline, a dropped
            // connection): the component would ignore every later render on this page
            request.promise.catch(() => {
                if (component.backendRequest === request) {
                    component.backendRequest = null;
                }

                this.failed(feed);
            });
        }

        clearTimeout(feed.watchdog);
        feed.watchdog = setTimeout(() => this.failed(feed), WATCHDOG_MS);
    }

    failed(feed) {
        if (!feed.inFlight) {
            return;
        }

        clearTimeout(feed.watchdog);
        feed.inFlight = false;
        feed.failures++;
        feed.lastRenderAt = Date.now();
        feed.dueAt = feed.lastRenderAt + BACKOFF_SECONDS[Math.min(feed.failures, BACKOFF_SECONDS.length) - 1] * 1000;
        wake();
    }

    nextDue(from) {
        // Tabs restored together (browser restart, a deploy reload) drift apart instead of refreshing in step
        return from + this.intervalValue + (Math.random() * 2 - 1) * JITTER_MS;
    }

    state(now) {
        if (this.paused) {
            return 'paused';
        }

        if (navigator.onLine === false || now - this.lastInteractionAt >= this.idleAfterValue) {
            return 'idle';
        }

        return 'running';
    }

    onInteraction() {
        if (document.visibilityState === 'hidden') {
            return;
        }

        const now = Date.now();

        if (now - this.lastInteractionAt >= this.idleAfterValue) {
            this.resumedFromIdleAt = now;
            this.lastInteractionAt = now;
            wake();

            return;
        }

        this.lastInteractionAt = now;
    }

    paint(feed, state, now) {
        if (!this.hasRingTarget) {
            return;
        }

        let look = state;

        if (state === 'running' && feed !== null && feed.failures > 0) {
            look = 'failed';
        }

        if (this.look !== look) {
            this.look = look;
            this.ringTarget.dataset.state = look;
            this.ringTarget.setAttribute('aria-pressed', String(look === 'paused'));
            // data-running-title … data-failed-title, translated in templates/_live_refresh_ring.html.twig
            this.ringTarget.title = this.ringTarget.dataset[`${look}Title`] ?? '';
        }

        let progress = 0;

        // Full while the refresh is on its way, empty again once it has arrived
        if (look === 'running' && feed !== null) {
            progress = feed.inFlight ? 1 : Math.min(1, Math.max(0, (now - feed.lastRenderAt) / (feed.dueAt - feed.lastRenderAt)));
        }

        const offset = (100 - progress * 100).toFixed(1);

        if (this.hasProgressTarget && this.progressTarget.getAttribute('stroke-dashoffset') !== offset) {
            this.progressTarget.setAttribute('stroke-dashoffset', offset);
        }
    }
}

function isShown(element) {
    return typeof element.checkVisibility === 'function' ? element.checkVisibility() : element.offsetParent !== null;
}

// A `loading="lazy"` component renders itself once it scrolls into view
function isPlaceholder(element) {
    return (element.getAttribute('data-action') ?? '').includes('live:appear');
}
