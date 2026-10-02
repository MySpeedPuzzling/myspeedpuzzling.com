/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus';
import { getComponent } from '@symfony/ux-live-component';
import { subscribe, wake } from '../page_ticker.js';
import { chooseTranslation } from '../translation_choice.js';

const JITTER_MS = 5000;
const WATCHDOG_MS = 30000;
const BACKOFF_SECONDS = [60, 120, 300];
const INTERACTION_EVENTS = ['pointerdown', 'pointermove', 'keydown', 'wheel', 'touchstart', 'scroll'];

// A pause is the visitor's choice for the whole page, not for one of the Hub's two tabs
let pausedByVisitor = false;

/**
 * Sits on a live component's root (RecentActivity with `autoRefresh`) and re-renders it every interval while
 * somebody can see it (docs/features/live-activity-feed.md). The library's own `data-poll` is a bare setInterval:
 * production showed 91 % of the feed's refreshes coming from desktop tabs left open for hours.
 *
 * - only a component that is shown (not in a hidden tab pane) refreshes; one that comes back overdue refreshes at once
 * - nothing runs while the page is hidden (the page ticker sleeps) or after `idleAfter` without any interaction -
 *   the next touch, key, scroll or return to the tab resumes it
 * - the status line ("Auto-update in 42 seconds" + a ring filling smoothly) counts down and is the WCAG 2.2.2
 *   pause / resume button; it is `data-live-ignore`, so no re-render ever morphs what this controller drew
 * - a failed refresh is retried later (60 → 120 → 300 s) instead of showing the error page
 */
export default class extends Controller {
    static targets = ['status', 'text', 'fill'];

    static values = {
        interval: { type: Number, default: 60000 },
        idleAfter: { type: Number, default: 1800000 },
    };

    initialize() {
        this.component = null;
        this.lastRenderAt = Date.now();
        this.dueAt = this.nextDue(this.lastRenderAt);
        this.inFlight = false;
        this.failures = 0;
        this.watchdog = null;
        this.lastInteractionAt = Date.now();
        this.resumedFromIdleAt = 0;
        this.look = null;
        this.ringCycle = null;
        this.ringAnimations = [];
        this.onInteraction = this.onInteraction.bind(this);
        this.onOnline = () => wake();
    }

    connect() {
        INTERACTION_EVENTS.forEach((type) => document.addEventListener(type, this.onInteraction, { capture: true, passive: true }));
        document.addEventListener('visibilitychange', this.onInteraction);
        window.addEventListener('online', this.onOnline);

        getComponent(this.element).then((component) => {
            this.component = component;

            component.on('render:finished', () => {
                clearTimeout(this.watchdog);
                this.inFlight = false;
                this.failures = 0;
                this.lastRenderAt = Date.now();
                this.dueAt = this.nextDue(this.lastRenderAt);
                wake();
            });

            component.on('response:error', (backendResponse, controls) => {
                if (this.inFlight) {
                    // Our own background refresh: retried later, never the error page in a modal
                    controls.displayError = false;
                    this.failed();
                }
            });
        });

        this.unsubscribe = subscribe((now) => this.tick(now));
    }

    disconnect() {
        INTERACTION_EVENTS.forEach((type) => document.removeEventListener(type, this.onInteraction, { capture: true }));
        document.removeEventListener('visibilitychange', this.onInteraction);
        window.removeEventListener('online', this.onOnline);
        this.unsubscribe();
        clearTimeout(this.watchdog);
        this.stopRing();
    }

    statusTargetConnected(element) {
        // Texts with the locale of their catalogue, so a count is worded like PHP words it (templates/_live_refresh_status.html.twig)
        this.texts = JSON.parse(element.dataset.texts ?? '{}');
    }

    toggle() {
        const now = Date.now();

        // A click that ends a pause - the visitor's own, after being away, or after failures - refreshes now
        if (pausedByVisitor || now - this.resumedFromIdleAt < 1000 || this.failures > 0 || this.state(now) !== 'running') {
            pausedByVisitor = false;
            this.lastInteractionAt = now;
            this.failures = 0;
            this.dueAt = now;
        } else {
            pausedByVisitor = true;
        }

        wake();
    }

    tick(now) {
        const state = this.state(now);

        if (!isShown(this.element)) {
            // A tab pane that is not shown: checked again in a second, refreshed at once when shown overdue
            this.stopRing();

            return now + 1000;
        }

        if (
            state === 'running'
            && this.component !== null
            && !this.inFlight
            && now >= this.dueAt
            // A refresh would close the row's open actions menu under the visitor's finger
            && this.element.querySelector('.dropdown-menu.show') === null
        ) {
            this.refresh();
        }

        this.paint(state, now);

        if (state !== 'running') {
            // Woken again by an interaction, the toggle or the browser coming back online
            return Infinity;
        }

        // Also when a due refresh is held back (an open row menu, a component still connecting): check again in a second
        if (this.inFlight || now >= this.dueAt) {
            return now + 1000;
        }

        // The moment the whole seconds left change
        return this.dueAt - (Math.ceil((this.dueAt - now) / 1000) - 1) * 1000;
    }

    refresh() {
        const { component } = this;
        const previous = component.backendRequest;

        this.inFlight = true;
        component.render();

        const request = component.backendRequest;

        if (request && request !== previous) {
            // Live Component never clears a request whose fetch rejected (offline, a dropped connection): the
            // component would ignore every later render on this page
            request.promise.catch(() => {
                if (component.backendRequest === request) {
                    component.backendRequest = null;
                }

                this.failed();
            });
        }

        clearTimeout(this.watchdog);
        this.watchdog = setTimeout(() => this.failed(), WATCHDOG_MS);
    }

    failed() {
        if (!this.inFlight) {
            return;
        }

        clearTimeout(this.watchdog);
        this.inFlight = false;
        this.failures++;
        this.lastRenderAt = Date.now();
        this.dueAt = this.lastRenderAt + BACKOFF_SECONDS[Math.min(this.failures, BACKOFF_SECONDS.length) - 1] * 1000;
        wake();
    }

    nextDue(from) {
        // Tabs restored together (browser restart, a deploy reload) drift apart instead of refreshing in step
        return from + this.intervalValue + (Math.random() * 2 - 1) * JITTER_MS;
    }

    state(now) {
        if (pausedByVisitor) {
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

    paint(state, now) {
        if (!this.hasStatusTarget) {
            return;
        }

        const look = state === 'running' && this.failures > 0 ? 'failed' : state;

        if (this.look !== look) {
            this.look = look;
            this.statusTarget.dataset.state = look;
            this.statusTarget.setAttribute('aria-pressed', String(look === 'paused'));
            // data-running-title … data-failed-title, translated in templates/_live_refresh_status.html.twig
            this.statusTarget.title = this.statusTarget.dataset[`${look}Title`] ?? '';
        }

        const refreshing = look === 'running' && this.inFlight;
        const text = this.text(refreshing ? 'refreshing' : look, Math.max(0, Math.ceil((this.dueAt - now) / 1000)));

        if (this.hasTextTarget && text !== null && this.textTarget.textContent !== text) {
            this.textTarget.textContent = text;
        }

        this.statusTarget.classList.toggle('is-full', refreshing);

        if (look === 'running' && !this.inFlight) {
            this.runRing(this.lastRenderAt, this.dueAt, now);
        } else {
            this.stopRing();
        }
    }

    text(key, count) {
        const text = this.texts?.[key];

        return text === undefined ? null : chooseTranslation(text.message, count, text.locale);
    }

    // The ring fills smoothly from the last refresh to the next: two Web Animations of `transform` only, which the
    // browser runs off the main thread (assets/styles/live_refresh.scss). Restarted only when the cycle changes.
    runRing(start, due, now) {
        const cycle = `${start}:${due}`;

        if (this.ringCycle === cycle || this.fillTargets.length !== 2 || typeof Element.prototype.animate !== 'function') {
            return;
        }

        this.stopRing();
        this.ringCycle = cycle;

        const half = (due - start) / 2;
        const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        const options = {
            duration: half,
            fill: 'both',
            // Reduced motion: a step per second instead of a continuous sweep
            easing: reducedMotion ? `steps(${Math.max(1, Math.round(half / 1000))})` : 'linear',
        };
        const keyframes = [{ transform: 'rotate(-135deg)' }, { transform: 'rotate(45deg)' }];
        const [right, left] = this.fillTargets;

        this.ringAnimations = [right.animate(keyframes, options), left.animate(keyframes, { ...options, delay: half })];
        this.ringAnimations.forEach((animation) => {
            animation.currentTime = now - start;
        });
    }

    stopRing() {
        this.ringAnimations.forEach((animation) => animation.cancel());
        this.ringAnimations = [];
        this.ringCycle = null;
    }
}

function isShown(element) {
    return typeof element.checkVisibility === 'function' ? element.checkVisibility() : element.offsetParent !== null;
}
