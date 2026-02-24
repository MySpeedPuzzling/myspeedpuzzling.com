import { Controller } from '@hotwired/stimulus';

const STALE_THRESHOLD_MS = 15 * 60 * 1000; // 15 minutes
const PULL_THRESHOLD = 70; // px to trigger refresh
const PULL_RESISTANCE = 2.8; // damping factor — increases resistance as you pull further
const INDICATOR_SIZE = 36; // px — diameter of the circular indicator

export default class extends Controller {
    connect() {
        this._isPwa = window.matchMedia('(display-mode: standalone)').matches
            || window.navigator.standalone === true;

        this._lastHiddenAt = null;
        this._onVisibilityChange = this._handleVisibilityChange.bind(this);
        this._onPageShow = this._handlePageShow.bind(this);

        document.addEventListener('visibilitychange', this._onVisibilityChange);
        window.addEventListener('pageshow', this._onPageShow);

        if (this._isPwa) {
            this._initPullToRefresh();
        }
    }

    disconnect() {
        document.removeEventListener('visibilitychange', this._onVisibilityChange);
        window.removeEventListener('pageshow', this._onPageShow);

        if (this._isPwa) {
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

        const forms = document.querySelectorAll('form');
        for (const form of forms) {
            for (const el of form.elements) {
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

    _initPullToRefresh() {
        this._pullStartY = 0;
        this._pullDistance = 0;
        this._pulling = false;
        this._thresholdReached = false;

        this._injectStyles();
        this._createIndicator();

        this._onTouchStart = this._handleTouchStart.bind(this);
        this._onTouchMove = this._handleTouchMove.bind(this);
        this._onTouchEnd = this._handleTouchEnd.bind(this);

        document.addEventListener('touchstart', this._onTouchStart, { passive: true });
        document.addEventListener('touchmove', this._onTouchMove, { passive: false });
        document.addEventListener('touchend', this._onTouchEnd, { passive: true });
    }

    _injectStyles() {
        if (document.getElementById('pwa-ptr-styles')) return;

        const style = document.createElement('style');
        style.id = 'pwa-ptr-styles';
        style.textContent = `
            @keyframes pwa-ptr-spin {
                to { transform: rotate(360deg); }
            }
            .pwa-ptr-indicator {
                position: fixed;
                top: 0;
                left: 50%;
                z-index: 9999;
                width: ${INDICATOR_SIZE}px;
                height: ${INDICATOR_SIZE}px;
                margin-left: -${INDICATOR_SIZE / 2}px;
                border-radius: 50%;
                background: #fff;
                box-shadow: 0 1px 6px rgba(0,0,0,.16);
                display: flex;
                align-items: center;
                justify-content: center;
                pointer-events: none;
                transform: translateY(-${INDICATOR_SIZE + 10}px) scale(0.3);
                opacity: 0;
                will-change: transform, opacity;
            }
            .pwa-ptr-indicator.is-pulling {
                transition: none;
            }
            .pwa-ptr-indicator.is-settling {
                transition: transform .3s cubic-bezier(.4,.0,.2,1), opacity .3s ease;
            }
            .pwa-ptr-indicator.is-refreshing {
                transition: transform .2s cubic-bezier(.4,.0,.2,1);
            }
            .pwa-ptr-arrow {
                width: 20px;
                height: 20px;
                color: #555;
                transition: transform .15s ease;
            }
            .pwa-ptr-spinner {
                width: 20px;
                height: 20px;
                display: none;
            }
            .pwa-ptr-spinner circle {
                fill: none;
                stroke: #555;
                stroke-width: 2.5;
                stroke-linecap: round;
                stroke-dasharray: 40, 60;
                animation: pwa-ptr-spin .7s linear infinite;
                transform-origin: center;
            }
            .pwa-ptr-indicator.is-refreshing .pwa-ptr-arrow { display: none; }
            .pwa-ptr-indicator.is-refreshing .pwa-ptr-spinner { display: block; }
        `;
        document.head.appendChild(style);
    }

    _createIndicator() {
        this._indicator = document.createElement('div');
        this._indicator.className = 'pwa-ptr-indicator';
        this._indicator.innerHTML = `
            <svg class="pwa-ptr-arrow" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                <line x1="12" y1="5" x2="12" y2="19"/>
                <polyline points="19 12 12 19 5 12"/>
            </svg>
            <svg class="pwa-ptr-spinner" viewBox="0 0 24 24">
                <circle cx="12" cy="12" r="9"/>
            </svg>
        `;
        document.body.appendChild(this._indicator);
    }

    _destroyPullToRefresh() {
        document.removeEventListener('touchstart', this._onTouchStart);
        document.removeEventListener('touchmove', this._onTouchMove);
        document.removeEventListener('touchend', this._onTouchEnd);

        if (this._indicator && this._indicator.parentNode) {
            this._indicator.parentNode.removeChild(this._indicator);
        }
    }

    _handleTouchStart(event) {
        if (window.scrollY === 0) {
            this._pullStartY = event.touches[0].clientY;
            this._pulling = true;
            this._pullDistance = 0;
            this._thresholdReached = false;
            this._indicator.classList.remove('is-settling', 'is-refreshing');
            this._indicator.classList.add('is-pulling');
        }
    }

    _handleTouchMove(event) {
        if (!this._pulling) return;

        const y = event.touches[0].clientY;
        const delta = y - this._pullStartY;

        if (delta < 0 || window.scrollY > 0) {
            this._pulling = false;
            this._resetIndicator();
            return;
        }

        event.preventDefault();

        // Progressive resistance — gets harder the further you pull
        this._pullDistance = delta / (PULL_RESISTANCE + (delta / 300));

        const progress = Math.min(this._pullDistance / PULL_THRESHOLD, 1);

        // Position: slides down from top, capped slightly past threshold
        const translateY = Math.min(this._pullDistance, PULL_THRESHOLD + 20) - INDICATOR_SIZE / 2;
        const scale = 0.3 + (progress * 0.7);
        this._indicator.style.transform = `translateY(${translateY}px) scale(${scale})`;
        this._indicator.style.opacity = Math.min(progress * 1.5, 1);

        // Arrow rotates: points down initially, flips up past threshold
        const arrow = this._indicator.querySelector('.pwa-ptr-arrow');
        const rotation = progress >= 1 ? 180 : 0;
        arrow.style.transform = `rotate(${rotation}deg)`;

        // Haptic feedback when crossing threshold
        if (progress >= 1 && !this._thresholdReached) {
            this._thresholdReached = true;
            this._haptic();
        } else if (progress < 1 && this._thresholdReached) {
            this._thresholdReached = false;
        }
    }

    _handleTouchEnd() {
        if (!this._pulling) return;
        this._pulling = false;

        if (this._pullDistance >= PULL_THRESHOLD) {
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

    _haptic() {
        if (navigator.vibrate) {
            navigator.vibrate(10);
        }
    }
}
