/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus';

const STORAGE_KEY = 'msp-recap-celebrated';
const REMEMBER = 40;                                   // time ids kept - a reload or Back never celebrates again
const TICK_MS = 950;                                   // after the bar has filled (CSS: .15 s delay + .9 s)
const BURST_MS = 1800;

/**
 * The level line of the recap after logging a time (templates/added_time_recap/_level_row.html.twig), on a recap the
 * server marked fresh (RecapProgress::isFresh). Once per time id, remembered in localStorage - zero server cost:
 *   - the bar fills from where it was before this time (CSS, .is-celebrating),
 *   - a level-up: the number ticks over from the old level, the "Level up!" pill pops with a short burst,
 *   - a milestone level (10, 20 … 50): the bottom sheet opens; Continue or Esc closes it, in the browser.
 * Anything already celebrated - or private browsing without storage, after the first view - stays as rendered.
 * Reduced motion: no fill, no burst, no tick; the sheet still opens (it is content, not motion). The level-up is
 * read out by the card's aria-live line, not here. This element is not re-rendered after the XP arrives (the Live
 * component stops polling), so the classes set here stay.
 */
export default class extends Controller {
    static targets = ['number', 'pill', 'sheet', 'close'];

    static values = {
        timeId: String,
        from: Number,
        to: Number,
    };

    connect() {
        this.timers = [];
        this.onKeydown = this.onKeydown.bind(this);

        if (!this.timeIdValue || alreadyCelebrated(this.timeIdValue)) {
            return;
        }

        remember(this.timeIdValue);

        const leveledUp = this.toValue > this.fromValue;

        if (prefersReducedMotion()) {
            this.openSheet();
            return;
        }

        this.element.classList.add('is-celebrating');

        if (leveledUp && this.hasNumberTarget) {
            this.numberTarget.textContent = String(this.fromValue);
        }

        this.later(() => {
            if (leveledUp) {
                if (this.hasNumberTarget) {
                    this.numberTarget.textContent = String(this.toValue);
                }

                this.element.classList.add('is-leveled');
                this.burst();
            }

            this.openSheet();
        }, TICK_MS);
    }

    disconnect() {
        for (const timer of this.timers) {
            window.clearTimeout(timer);
        }

        this.timers = [];
        document.removeEventListener('keydown', this.onKeydown);
    }

    /** action: Continue */
    close() {
        if (!this.hasSheetTarget || this.sheetTarget.hidden) {
            return;
        }

        this.sheetTarget.hidden = true;
        document.removeEventListener('keydown', this.onKeydown);

        if (this.returnFocus && document.contains(this.returnFocus)) {
            this.returnFocus.focus({ preventScroll: true });
        }
    }

    openSheet() {
        if (!this.hasSheetTarget) {
            return;
        }

        this.returnFocus = document.activeElement instanceof HTMLElement ? document.activeElement : null;
        this.sheetTarget.hidden = false;
        document.addEventListener('keydown', this.onKeydown);

        if (this.hasCloseTarget) {
            this.closeTarget.focus({ preventScroll: true });
        }
    }

    onKeydown(event) {
        if (event.key === 'Escape') {
            this.close();
        }
    }

    /** A short burst of the site's CSS confetti around the pill (.xp-confetti-burst), removed afterwards */
    burst() {
        const anchor = this.hasPillTarget ? this.pillTarget : null;

        if (!anchor) {
            return;
        }

        const confetti = document.createElement('span');
        confetti.className = 'xp-confetti xp-confetti-burst';
        confetti.setAttribute('aria-hidden', 'true');

        for (let i = 0; i < 10; i++) {
            confetti.appendChild(document.createElement('i'));
        }

        anchor.appendChild(confetti);
        this.later(() => confetti.remove(), BURST_MS);
    }

    later(fn, ms) {
        this.timers.push(window.setTimeout(fn, ms));
    }
}

function prefersReducedMotion() {
    return window.matchMedia !== undefined && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
}

function readCelebrated() {
    try {
        const list = JSON.parse(window.localStorage.getItem(STORAGE_KEY) || '[]');

        return Array.isArray(list) ? list : [];
    } catch (e) {
        return [];
    }
}

function alreadyCelebrated(timeId) {
    return readCelebrated().includes(timeId);
}

function remember(timeId) {
    try {
        const list = readCelebrated().filter((id) => id !== timeId);
        list.push(timeId);
        window.localStorage.setItem(STORAGE_KEY, JSON.stringify(list.slice(-REMEMBER)));
    } catch (e) {
        // storage unavailable (private mode): this page view only
    }
}
