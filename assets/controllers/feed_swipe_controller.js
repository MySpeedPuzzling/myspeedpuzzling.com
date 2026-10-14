/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus';
import { Tab } from 'bootstrap';
import {
    AWAKE_CLASS,
    indicatorAt,
    paneStates,
    PHONE_QUERY,
    RESTING_CLASS,
    SCROLL_IDLE_MS,
    scrollBehavior,
    settledIndex,
    SHOWN_EVENT,
    SWIPEABLE_CLASS,
    tabsScrollTop,
    VISIBLE_RATIO,
} from '../feed_swipe.js';

/**
 * Swipe between Home's two feeds (docs/features/navigation README §4.13 G14; GEST-16; decision 67) - on the Hub's feed
 * switcher (templates/hub.html.twig), the maths in assets/feed_swipe.js. Below 768 px both panes are one horizontal
 * scroll-snap scroller (assets/styles/_hub-feeds.scss); the browser does the swipe, this keeps the rest in step:
 * - a tab shown (a tap, the keyboard's arrows, the address's #pane) scrolls its pane into view - smooth, at once with
 *   reduced motion - from Bootstrap's show.bs.tab, before the tab's classes switch: Bootstrap's own click handler of
 *   data-bs-toggle="tab" runs in the capture phase on the document, before any action on the link could;
 * - when the scroller settles (scrollend; without it: the scroll at rest with no finger on the scroller) its tab is
 *   selected through Bootstrap (aria-selected, .active), the other pane rests (.is-resting: height 0) and is inert,
 *   `feed-swipe:shown` is dispatched on the shown pane (INB-04a's mark-seen listens), and when the switch happened below
 *   the tabs the page goes back up to them. Without scrollend a pane ≥ 60 % in view selects its tab at once - nothing
 *   more: the pane the finger still holds keeps its content, its height and the page's scroll until the scroller settles;
 * - the first touch or scroll wakes the resting pane (it renders while the finger brings it in);
 * - the indicator under the tabs follows scrollLeft (one transform per frame); the scroller is as high as the shown
 *   pane (ResizeObserver - also after a Live re-render of its feed).
 * From 768 px Bootstrap's tabs as before; the hidden pane is inert there too (live_refresh never refreshes inside it).
 * Identical on iOS and Android - no platform question, nothing native-specific (the app's WebView scrolls the same).
 */
export default class extends Controller {
    static targets = ['tabs', 'scroller', 'pane'];

    connect() {
        this.phone = window.matchMedia(PHONE_QUERY);
        this.reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)');
        this.selected = this.activeIndex();
        this.scrollingTo = null;
        this.showingItself = false;
        this.frame = null;
        this.idleTimer = null;
        // A finger on the scroller (touchstart → touchend): without scrollend a scroll at rest settles only once it lifts
        this.touching = false;
        this.hasScrollEnd = 'onscrollend' in window;

        this.onScroll = () => this.scrolled();
        this.onScrollEnd = () => this.settle();
        this.onTouchStart = () => {
            this.touching = true;
            this.wake();
        };
        this.onTouchEnd = (event) => this.touchEnded(event);
        this.onMediaChange = () => this.layout();
        this.onTabShow = (event) => this.tabShowing(event);
        this.onTabShown = () => this.tabShown();

        this.scrollerTarget.addEventListener('scroll', this.onScroll, { passive: true });
        this.scrollerTarget.addEventListener('touchstart', this.onTouchStart, { passive: true });
        this.scrollerTarget.addEventListener('touchend', this.onTouchEnd, { passive: true });
        this.scrollerTarget.addEventListener('touchcancel', this.onTouchEnd, { passive: true });
        this.tabsTarget.addEventListener('show.bs.tab', this.onTabShow);
        this.tabsTarget.addEventListener('shown.bs.tab', this.onTabShown);
        this.phone.addEventListener('change', this.onMediaChange);

        if (this.hasScrollEnd) {
            this.scrollerTarget.addEventListener('scrollend', this.onScrollEnd);
        } else if (typeof IntersectionObserver === 'function') {
            // Without scrollend: the tab follows a pane ≥ 60 % in view at once, the rest waits for the scroll to rest (rested())
            this.visibility = new IntersectionObserver((entries) => this.paneInView(entries), { root: this.scrollerTarget, threshold: VISIBLE_RATIO });
            this.paneTargets.forEach((pane) => this.visibility.observe(pane));
        }

        if (typeof ResizeObserver === 'function') {
            this.resize = new ResizeObserver(() => this.fitHeight());
            this.paneTargets.forEach((pane) => this.resize.observe(pane));
        }

        this.layout();
    }

    disconnect() {
        this.scrollerTarget.removeEventListener('scroll', this.onScroll);
        this.scrollerTarget.removeEventListener('scrollend', this.onScrollEnd);
        this.scrollerTarget.removeEventListener('touchstart', this.onTouchStart);
        this.scrollerTarget.removeEventListener('touchend', this.onTouchEnd);
        this.scrollerTarget.removeEventListener('touchcancel', this.onTouchEnd);
        this.tabsTarget.removeEventListener('show.bs.tab', this.onTabShow);
        this.tabsTarget.removeEventListener('shown.bs.tab', this.onTabShown);
        this.phone.removeEventListener('change', this.onMediaChange);
        this.visibility?.disconnect();
        this.resize?.disconnect();
        cancelAnimationFrame(this.frame);
        clearTimeout(this.idleTimer);
    }

    /**
     * Bootstrap is about to show a tab (a tap, the keyboard, the address's #pane): its pane scrolls into view; the scroll's
     * end settles. A tab whose pane is in view already settles when Bootstrap has shown it (tabShown).
     */
    tabShowing(event) {
        // This controller selecting the tab of where the scroller is (settle, a pane ≥ 60 % in view) - never a scroll
        if (!this.phone.matches || this.showingItself) {
            return;
        }

        const index = this.tabLinks().indexOf(event.target);
        const pane = this.paneTargets[index];

        if (!pane) {
            return;
        }

        this.wake();

        if (Math.abs(this.scrollerTarget.scrollLeft - this.paneLeft(pane)) < 1) {
            return;
        }

        this.scrollingTo = index;
        this.scrollerTarget.scrollTo({ left: this.paneLeft(pane), behavior: scrollBehavior(this.reducedMotion.matches) });
    }

    /** Phones: the scroller awake at the selected pane; from 768 px Bootstrap's tabs (only the hidden pane kept inert) */
    layout() {
        const phone = this.phone.matches;
        this.selected = this.activeIndex();
        this.scrollerTarget.classList.toggle(AWAKE_CLASS, phone);
        this.tabsTarget.classList.toggle(SWIPEABLE_CLASS, phone);

        if (phone) {
            // In the same task as the class: the shown pane stays where it was on the screen (no shift)
            this.scrollerTarget.scrollLeft = this.paneLeft(this.paneTargets[this.selected]);
        } else {
            this.scrollerTarget.style.height = '';
        }

        this.applyStates();
        this.fitHeight();
        this.paintIndicator();
    }

    /** The first touch or scroll: the resting pane renders, so the finger brings in its content */
    wake() {
        if (!this.phone.matches) {
            return;
        }

        const shown = this.paneTargets[this.selected];
        const height = shown ? shown.getBoundingClientRect().height : 0;
        const left = this.scrollerTarget.scrollLeft;
        this.paneTargets.forEach((pane) => pane.classList.remove(RESTING_CLASS));

        // The scroller keeps the shown pane's height while the other one renders beside it
        if (height > 0) {
            this.scrollerTarget.style.height = `${height}px`;
        }

        // ...and its place, should the browser re-snap when the other pane gets its height back - put back in the same
        // task, before anything is painted
        if (Math.abs(this.scrollerTarget.scrollLeft - left) >= 1) {
            this.scrollerTarget.scrollLeft = left;
        }
    }

    scrolled() {
        if (!this.phone.matches) {
            return;
        }

        // Moved off the shown pane (a finger, a wheel, a tap's scroll) - not this controller putting it in place
        if (Math.abs(this.scrollerTarget.scrollLeft - this.paneLeft(this.paneTargets[this.selected])) >= 1) {
            this.wake();
        }

        if (this.frame === null) {
            this.frame = requestAnimationFrame(() => {
                this.frame = null;
                this.paintIndicator();
            });
        }

        if (!this.hasScrollEnd) {
            this.restLater();
        }
    }

    /** Without scrollend: the last finger lifted - the snap's own scroll (if any) re-arms the wait, rested() settles */
    touchEnded(event) {
        if (event && event.touches && event.touches.length > 0) {
            return;
        }

        this.touching = false;

        if (!this.hasScrollEnd) {
            this.restLater();
        }
    }

    /** Without scrollend: the scroll is at rest after SCROLL_IDLE_MS without a scroll event */
    restLater() {
        clearTimeout(this.idleTimer);
        this.idleTimer = setTimeout(() => this.rested(), SCROLL_IDLE_MS);
    }

    /**
     * Without scrollend: the scroll came to rest - unless a finger still holds the scroller somewhere between the panes
     * (no scroll events while it pauses): its touchend asks again
     */
    rested() {
        if (this.touching) {
            return;
        }

        this.settle();
    }

    /**
     * Without scrollend: the tab of a pane ≥ 60 % in view is selected at once - only the tab (showTab(): aria-selected,
     * .active). The finger may still hold the scroller, or bring the other pane back: resting, inert, the height,
     * `feed-swipe:shown` and the way back up to the tabs wait for settle()
     */
    paneInView(entries) {
        if (!this.phone.matches) {
            return;
        }

        for (const entry of entries) {
            if (entry.isIntersecting && entry.intersectionRatio >= VISIBLE_RATIO) {
                this.showTab(this.paneTargets.indexOf(entry.target));
            }
        }
    }

    /** The scroller came to rest on a pane */
    settle() {
        if (!this.phone.matches) {
            return;
        }

        clearTimeout(this.idleTimer);
        this.scrollingTo = null;

        const index = settledIndex(this.scrollerTarget.scrollLeft, this.pitch(), this.paneTargets.length);
        const changed = index !== this.selected;
        this.selected = index;
        this.showTab(index);
        this.applyStates();
        this.fitHeight();
        this.paintIndicator();

        if (!changed) {
            return;
        }

        const pane = this.paneTargets[index];
        pane.dispatchEvent(new CustomEvent(SHOWN_EVENT, { bubbles: true, detail: { pane } }));

        // Switched below the tabs: back up to them, the new feed starts at its top
        const top = tabsScrollTop({
            tabsTop: this.tabsTarget.getBoundingClientRect().top,
            scrollY: window.scrollY,
            headerHeight: parseFloat(getComputedStyle(document.documentElement).getPropertyValue('--header-height')) || 0,
        });

        if (top !== null) {
            window.scrollTo({ top, behavior: scrollBehavior(this.reducedMotion.matches) });
        }
    }

    /** Bootstrap showed a tab: from 768 px only the hidden pane's inert; on phones the scroll's end settles - or now */
    tabShown() {
        // This controller selecting the tab itself (settle(), a pane ≥ 60 % in view): never a settle of its own - Bootstrap
        // runs a link's `shown` synchronously inside show() (no .fade on the tab links), while a finger may hold the scroller
        if (this.showingItself) {
            return;
        }

        if (!this.phone.matches) {
            this.selected = this.activeIndex();
            this.applyStates();

            return;
        }

        // The tab's scroll is under way - its scrollend settles
        if (this.scrollingTo !== null) {
            return;
        }

        this.settle();
    }

    showTab(index) {
        const tab = this.tabLinks()[index];

        if (tab && !tab.classList.contains('active')) {
            this.showingItself = true;

            try {
                Tab.getOrCreateInstance(tab).show();
            } finally {
                this.showingItself = false;
            }
        }
    }

    applyStates() {
        const phone = this.phone.matches;

        paneStates(this.paneTargets.length, this.selected).forEach((state, index) => {
            const pane = this.paneTargets[index];
            pane.inert = state.inert;

            if (phone) {
                pane.classList.toggle(RESTING_CLASS, state.resting);
            }
        });
    }

    /**
     * The scroller is as high as the shown pane. While the other pane rests (no height) the grid row is exactly that
     * already: no height of our own - the page as the server laid it out, nothing moves when this controller connects.
     * Only while the other pane is awake (a swipe; it may be longer) the scroller holds the shown pane's exact height.
     */
    fitHeight() {
        const shown = this.paneTargets[this.selected];
        const othersAwake = this.paneTargets.some((pane) => pane !== shown && !pane.classList.contains(RESTING_CLASS));

        if (!this.phone.matches || !shown || !othersAwake) {
            this.scrollerTarget.style.height = '';

            return;
        }

        const height = shown.getBoundingClientRect().height;

        if (height > 0) {
            this.scrollerTarget.style.height = `${height}px`;
        }
    }

    paintIndicator() {
        if (!this.phone.matches) {
            return;
        }

        const tabs = this.tabLinks();
        const at = indicatorAt(
            this.scrollerTarget.scrollLeft,
            this.pitch(),
            tabs.map((tab) => ({ left: tab.offsetLeft, width: tab.offsetWidth })),
        );

        if (at) {
            this.tabsTarget.style.setProperty('--hub-feed-x', `${at.x}px`);
            this.tabsTarget.style.setProperty('--hub-feed-w', String(at.width));
            this.tabsTarget.style.setProperty('--hub-feed-y', `${tabs[0].offsetTop + tabs[0].offsetHeight}px`);
        }
    }

    /**
     * Where a pane starts in the scroller (its scrollLeft): offsetLeft counts from a positioned ancestor, not from the
     * scroller - measured from the first pane, which starts the scroller
     */
    paneLeft(pane) {
        const first = this.paneTargets[0];

        return pane && first ? pane.offsetLeft - first.offsetLeft : 0;
    }

    /** From one pane's start to the next one's: the pane's width + the gap between them */
    pitch() {
        const [first, second] = this.paneTargets;

        return first && second ? second.offsetLeft - first.offsetLeft : this.scrollerTarget.clientWidth;
    }

    activeIndex() {
        const index = this.paneTargets.findIndex((pane) => pane.classList.contains('active'));

        return index < 0 ? 0 : index;
    }

    tabLinks() {
        return Array.from(this.tabsTarget.querySelectorAll('[data-bs-toggle="tab"]'));
    }
}
