import { Controller } from '@hotwired/stimulus';
import { haptic } from '../haptics.js';
import layers from '../layer_history.js';
import { isNativeApp, isStandalone, os } from '../platform.js';
import { indicatorClass, indicatorMarkup, INDICATOR_SIZE, lookFor, pullFrame } from '../pull_indicator.js';
import { canStartPull, overlayOpen, pastThreshold, pullEnabled, PullGesture } from '../pull_to_refresh.js';

const STALE_THRESHOLD_MS = 15 * 60 * 1000; // 15 minutes

// The installed web app (GEST-06, README §4.13 G6): the 15-minute reload on return and our pull-to-refresh with its
// indicator. A browser tab is left to the browser; the Hotwire Native app has neither (assets/platform.js: the installed
// app is never the native one, and pullEnabled() steps aside there - NAV-14).
export default class extends Controller {
    connect() {
        this._isPwa = isStandalone();
        this._pullEnabled = pullEnabled({ standalone: this._isPwa, nativeApp: isNativeApp() });

        this._lastHiddenAt = null;
        this._onVisibilityChange = this._handleVisibilityChange.bind(this);
        this._onPageShow = this._handlePageShow.bind(this);

        document.addEventListener('visibilitychange', this._onVisibilityChange);
        window.addEventListener('pageshow', this._onPageShow);

        if (this._pullEnabled) {
            this._initPullToRefresh();
        }
    }

    disconnect() {
        document.removeEventListener('visibilitychange', this._onVisibilityChange);
        window.removeEventListener('pageshow', this._onPageShow);

        if (this._pullEnabled) {
            this._destroyPullToRefresh();
        }
    }

    _handleVisibilityChange() {
        if (document.visibilityState === 'hidden') {
            this._lastHiddenAt = Date.now();
            return;
        }

        if (this._isPwa && this._lastHiddenAt !== null && (Date.now() - this._lastHiddenAt) >= STALE_THRESHOLD_MS) {
            if (!this._hasActiveFormInput()) {
                window.location.reload();
            }
        }
    }

    _hasActiveFormInput() {
        const active = document.activeElement;
        if (active && (active.tagName === 'INPUT' || active.tagName === 'TEXTAREA' || active.tagName === 'SELECT')) {
            return true;
        }

        // Only what the form would send: a control without a name is never submitted (flatpickr's visible date field,
        // whose text the script sets on an untouched form; search boxes) - a reload loses nothing of it
        const forms = document.querySelectorAll('form');
        for (const form of forms) {
            for (const el of form.elements) {
                if (!el.name) {
                    continue;
                }

                if (el.tagName === 'INPUT' && (el.type === 'text' || el.type === 'number' || el.type === 'search' || el.type === 'email' || el.type === 'url' || el.type === 'tel')) {
                    if (el.value !== el.defaultValue) return true;
                } else if (el.tagName === 'TEXTAREA') {
                    if (el.value !== el.defaultValue) return true;
                } else if (el.tagName === 'SELECT') {
                    const defaultSelected = [...el.options].find(o => o.defaultSelected);
                    if (defaultSelected && el.value !== defaultSelected.value) return true;
                }
            }
        }

        return false;
    }

    _handlePageShow(event) {
        if (event.persisted) {
            window.location.reload();
        }
    }

    // Which touches may become a pull and when one is: assets/pull_to_refresh.js (tested under node). This controller
    // only reads the page for it and draws the indicator.
    _initPullToRefresh() {
        this._gesture = new PullGesture();
        this._thresholdReached = false;

        this._createIndicator();

        this._onTouchStart = this._handleTouchStart.bind(this);
        this._onTouchMove = this._handleTouchMove.bind(this);
        this._onTouchEnd = this._handleTouchEnd.bind(this);
        this._onTouchCancel = this._handleTouchCancel.bind(this);

        document.addEventListener('touchstart', this._onTouchStart, { passive: true });
        document.addEventListener('touchmove', this._onTouchMove, { passive: false });
        document.addEventListener('touchend', this._onTouchEnd, { passive: true });
        document.addEventListener('touchcancel', this._onTouchCancel, { passive: true });
    }

    // The indicator per platform (GEST-19, assets/pull_indicator.js): Material's 40 px disc with its arrow, on iOS the
    // bare spinner (no arrow element). Its styles are assets/styles/_pull-to-refresh.scss.
    _createIndicator() {
        this._look = lookFor(os());
        this._indicator = document.createElement('div');
        this._indicator.className = indicatorClass(this._look);
        this._indicator.innerHTML = indicatorMarkup(this._look);
        document.body.appendChild(this._indicator);
    }

    // One frame of the pull (assets/pull_indicator.js): where the indicator is, how far it shows, the arrow's flip or
    // the iOS spinner's turn. Returns whether a release here refreshes.
    _drawPull(delta) {
        const frame = pullFrame(this._look, delta);
        this._indicator.style.transform = frame.transform;
        this._indicator.style.opacity = frame.opacity;

        const arrow = frame.arrow === null ? null : this._indicator.querySelector('.pwa-ptr-arrow');
        const spinner = frame.spinner === null ? null : this._indicator.querySelector('.pwa-ptr-ios-spinner');

        if (arrow) {
            arrow.style.transform = frame.arrow;
        }

        if (spinner) {
            spinner.style.transform = frame.spinner;
        }

        return frame.past;
    }

    _destroyPullToRefresh() {
        document.removeEventListener('touchstart', this._onTouchStart);
        document.removeEventListener('touchmove', this._onTouchMove);
        document.removeEventListener('touchend', this._onTouchEnd);
        document.removeEventListener('touchcancel', this._onTouchCancel);

        if (this._indicator && this._indicator.parentNode) {
            this._indicator.parentNode.removeChild(this._indicator);
        }
    }

    _handleTouchStart(event) {
        const touch = event.touches[0];

        if (!touch) {
            return;
        }

        // A second finger landing on a pull (it turns into a pinch-zoom): the gesture starts again as no pull, so the
        // indicator goes back now - its touchmoves and touchend no longer see a pull (GEST review F8)
        if (this._gesture.pulling) {
            this._resetIndicator();
        }

        this._gesture.start(touch.clientX, touch.clientY, canStartPull(event.target, {
            scrollY: window.scrollY,
            overlayOpen: this._overlayOpen(event.target),
            styleOf: (element) => window.getComputedStyle(element),
            hasFormInput: () => this._hasActiveFormInput(),
        }), event.touches.length);
    }

    // A modal, sheet, the Menu panel, the player card or the site search over the page - or any other layer holding a
    // history entry (pull_to_refresh.js overlayOpen(), assets/layer_history.js)
    _overlayOpen(target) {
        return overlayOpen(target, {
            hasBodyClass: (name) => document.body.classList.contains(name),
            matches: (selector) => document.querySelector(selector) !== null,
            layer: layers.current(),
        });
    }

    _handleTouchMove(event) {
        if (this._gesture.state === 'idle') return;

        const touch = event.touches[0];
        const wasPulling = this._gesture.pulling;

        if (!touch || !this._gesture.move(touch.clientX, touch.clientY, { scrollY: window.scrollY, cancelable: event.cancelable })) {
            if (wasPulling) {
                this._resetIndicator();
            }

            return;
        }

        event.preventDefault();

        if (!wasPulling) {
            this._thresholdReached = false;
            this._indicator.classList.remove('is-settling', 'is-refreshing');
            this._indicator.classList.add('is-pulling');
        }

        // The finger's way down (pull_to_refresh.js): the indicator follows half of it and refreshes past PULL_TARGET -
        // about 128 px of finger travel
        const delta = this._gesture.distance;
        const past = this._drawPull(delta);

        // Haptic feedback when crossing threshold (once per crossing; haptic('tick') in GEST-10)
        if (past && !this._thresholdReached) {
            this._thresholdReached = true;
            this._haptic();
        } else if (!past && this._thresholdReached) {
            this._thresholdReached = false;
        }
    }

    _handleTouchCancel() {
        if (this._gesture.pulling) {
            this._resetIndicator();
        }

        this._gesture.cancel();
    }

    _handleTouchEnd() {
        if (!this._gesture.pulling) {
            this._gesture.cancel();
            return;
        }

        if (pastThreshold(this._gesture.end())) {
            // Settle into refreshing position
            this._indicator.classList.remove('is-pulling');
            this._indicator.classList.add('is-refreshing');
            this._indicator.style.transform = `translateY(${INDICATOR_SIZE * 0.6}px) scale(1)`;
            this._indicator.style.opacity = '1';
            window.location.reload();
        } else {
            this._resetIndicator();
        }
    }

    _resetIndicator() {
        this._indicator.classList.remove('is-pulling');
        this._indicator.classList.add('is-settling');
        this._indicator.style.transform = `translateY(-${INDICATOR_SIZE + 10}px) scale(0.3)`;
        this._indicator.style.opacity = '0';

        setTimeout(() => {
            this._indicator.classList.remove('is-settling');
        }, 300);
    }

    // The threshold crossed (once per crossing): a tick through assets/haptics.js - Android only (GEST-10)
    _haptic() {
        haptic('tick');
    }
}
