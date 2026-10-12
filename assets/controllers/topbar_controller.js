import { Controller } from '@hotwired/stimulus';
import { isNativeApp } from '../platform.js';
import {
    nextTopbarState,
    STICKY_FOLLOWERS,
    stuckAt,
    TOPBAR_REVEAL_EVENT,
    topbarMayHide,
    transformY,
    translateY,
} from '../topbar_scroll.js';

/** Phones in Media Queries Level 3 syntax (requirements, Conventions; $nav-phone-media in _nav-tokens.scss) */
const PHONE_QUERY = '(max-width: 767.98px), (max-height: 500.98px) and (pointer: coarse)';

const STUCK = 'data-topbar-stuck';

/**
 * The top bar (templates/navigation/_topbar.html.twig; docs/features/navigation README §4.1, NAV-03, decision 20).
 *
 * Hide on scroll, phones only: a passive scroll listener reads window.scrollY once per frame and sets
 * <html data-topbar="hidden|shown"> (+ data-topbar-scrolled: the shadow) - the rules are assets/topbar_scroll.js's.
 * CSS does the rest: the sticky bar slides up by its own height (200 ms away, 280 ms back, a fade under reduced
 * motion), critical CSS sets --topbar-h 0 and --topbar-shift the bar's height, and the sub-bars that follow it
 * translate by --topbar-shift. A sticky sub-bar follows only while it is stuck - marked data-topbar-stuck here when the
 * bar hides and on every frame while it is hidden, never moved while it sits further down the page.
 * Never hidden on desktop, under 480 px of height, inside the app, while the search, the Menu or a menu of the bar is
 * open, or while the focus is inside the bar. `msp:topbar-reveal` on window brings it back (the search, GEST-03).
 * Not data-turbo-permanent: every page's bar starts shown.
 *
 * The installed app's back button (topbar#back) goes back in the history when there is one, else follows its link -
 * the place's start page.
 */
export default class extends Controller {
    connect() {
        this.root = document.documentElement;
        this.state = null;
        this.frame = null;
        this.phone = typeof window.matchMedia === 'function' ? window.matchMedia(PHONE_QUERY) : null;

        // Every page starts with its bar shown (NAV-07: <html> keeps its attributes across Turbo visits)
        this.root.setAttribute('data-topbar', 'shown');

        window.addEventListener('scroll', this.schedule, { passive: true });
        window.addEventListener('resize', this.schedule, { passive: true });
        window.addEventListener(TOPBAR_REVEAL_EVENT, this.reveal);
        this.element.addEventListener('focusin', this.schedule);

        this.update();
    }

    disconnect() {
        window.removeEventListener('scroll', this.schedule);
        window.removeEventListener('resize', this.schedule);
        window.removeEventListener(TOPBAR_REVEAL_EVENT, this.reveal);
        this.element.removeEventListener('focusin', this.schedule);

        if (this.frame !== null) {
            window.cancelAnimationFrame(this.frame);
            this.frame = null;
        }
    }

    schedule = () => {
        if (this.frame !== null) {
            return;
        }

        this.frame = window.requestAnimationFrame(() => {
            this.frame = null;
            this.update();
        });
    };

    update() {
        this.state = nextTopbarState(this.state, window.scrollY, this.mayHide());
        this.apply(this.state);
    }

    mayHide() {
        const active = document.activeElement;

        return topbarMayHide(isNativeApp(this.root), {
            phone: this.phone !== null && this.phone.matches,
            viewportHeight: window.innerHeight,
            searchOpen: this.root.hasAttribute('data-search-open'),
            menuOpen: document.querySelector('#menu-layer[open]') !== null,
            popupOpen: this.element.querySelector('.dropdown-menu.show') !== null,
            focusInside: active !== null && active !== document.body && this.element.contains(active),
        });
    }

    /** The search opens, GEST-03 asks: shown at once, counting starts again from here */
    reveal = () => {
        this.state = { hidden: false, anchor: window.scrollY, lastY: window.scrollY, dir: null, scrolled: window.scrollY > 0 };
        this.apply(this.state);
    };

    apply({ hidden, scrolled }) {
        if (hidden) {
            // Measured before the attribute flips: the sub-bars stuck now follow the bar up, the others stay
            this.markStuckFollowers();
        }

        const value = hidden ? 'hidden' : 'shown';

        if (this.root.getAttribute('data-topbar') !== value) {
            this.root.setAttribute('data-topbar', value);
        }

        if (scrolled !== this.root.hasAttribute('data-topbar-scrolled')) {
            this.root.toggleAttribute('data-topbar-scrolled', scrolled);
        }
    }

    markStuckFollowers() {
        const followers = document.querySelectorAll(STICKY_FOLLOWERS.join(', '));
        const decisions = [];

        // Read everything first, then write (one layout)
        followers.forEach((element) => {
            if (element.getClientRects().length === 0) {
                return;
            }

            const style = window.getComputedStyle(element);

            if (style.position !== 'sticky') {
                return;
            }

            const shift = translateY(style.translate) + transformY(style.transform);
            decisions.push([element, stuckAt(element.getBoundingClientRect().top, parseFloat(style.top), shift)]);
        });

        decisions.forEach(([element, stuck]) => {
            if (stuck !== element.hasAttribute(STUCK)) {
                element.toggleAttribute(STUCK, stuck);
            }
        });
    }

    /** The installed app's back button: the history when there is one, else its link (the place's start page) */
    back(event) {
        const navigation = window.navigation;

        if (navigation && navigation.canGoBack) {
            event.preventDefault();
            window.history.back();
        }
    }
}
