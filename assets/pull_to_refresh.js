// Pull-to-refresh of the installed PWA (controllers/pwa_lifecycle_controller.js) - when a touch may become a pull, and
// when it is one. No DOM globals here: the controller hands in what it reads, tests/PullToRefreshGestureTest.php runs
// the same code under node.
//
// A pull has to call preventDefault() on touchmove, and that cancels whatever the browser would have done with the
// finger. So it must never take a gesture that belongs to something else:
//  - nothing is armed while the page is scrolled, a modal / sheet / the site search is open, or the finger starts in
//    an element that is scrolled itself (moving down there scrolls it back up), that scrolls sideways (a table, a chip
//    strip) or that says data-ptr-ignore;
//  - the first few pixels decide the direction, and only a clearly vertical downward move becomes a pull - sideways
//    and diagonal swipes are left to the browser from their first pixel to their last.

// The movement that decides what a gesture is
export const DIRECTION_LOCK_DISTANCE = 8;

// Vertical enough to be a pull: no more sideways than half of the way down (about 27° off vertical)
const MAX_SIDEWAYS_RATIO = 0.5;

/**
 * May a touch starting on `target` become a pull?
 *
 * @param {Element|null} target where the finger landed
 * @param {{scrollY: number, overlayOpen: boolean, styleOf: (element: Element) => {overflowX: string}}} page
 */
export function canStartPull(target, page) {
    if (page.scrollY > 0 || page.overlayOpen) {
        return false;
    }

    for (let element = target; element; element = element.parentElement) {
        if (element.tagName === 'BODY' || element.tagName === 'HTML') {
            break;
        }

        if (element.hasAttribute('data-ptr-ignore') || element.scrollTop > 0) {
            return false;
        }

        if (element.scrollWidth > element.clientWidth && ['auto', 'scroll'].includes(page.styleOf(element).overflowX)) {
            return false;
        }
    }

    return true;
}

/**
 * One touch, from touchstart to touchend. `move()` says whether that touchmove must be prevented (it is a pull).
 */
export class PullGesture {
    constructor() {
        this.state = 'idle';
        this.startX = 0;
        this.startY = 0;
        this.distance = 0;
    }

    /** `allowed` = canStartPull() for where the finger landed; a second finger (pinch) never pulls */
    start(x, y, allowed, touches = 1) {
        this.state = allowed && touches === 1 ? 'deciding' : 'idle';
        this.startX = x;
        this.startY = y;
        this.distance = 0;

        return this.state === 'deciding';
    }

    /**
     * @param {{scrollY: number, cancelable: boolean}} page
     * @returns {boolean} true = a pull: preventDefault() and show `distance`
     */
    move(x, y, page) {
        if (this.state === 'idle') {
            return false;
        }

        const dx = x - this.startX;
        const dy = y - this.startY;

        if (this.state === 'deciding') {
            if (Math.max(Math.abs(dx), Math.abs(dy)) < DIRECTION_LOCK_DISTANCE) {
                return false;
            }

            // A browser that already scrolls does not let go of the gesture (touchmove not cancelable)
            if (dy <= 0 || Math.abs(dx) > dy * MAX_SIDEWAYS_RATIO || page.scrollY > 0 || !page.cancelable) {
                this.state = 'idle';

                return false;
            }

            this.state = 'pulling';
        }

        // Back up past where it started, or the page scrolled after all: no longer a pull
        if (dy < 0 || page.scrollY > 0) {
            this.cancel();

            return false;
        }

        this.distance = dy;

        return true;
    }

    /** @returns {number} how far it was pulled (0 when it was not a pull) */
    end() {
        const distance = this.state === 'pulling' ? this.distance : 0;
        this.cancel();

        return distance;
    }

    cancel() {
        this.state = 'idle';
        this.distance = 0;
    }

    get pulling() {
        return this.state === 'pulling';
    }
}
