import { Controller } from '@hotwired/stimulus';

const STALE_THRESHOLD_MS = 15 * 60 * 1000; // 15 minutes
const PULL_THRESHOLD = 80; // px to trigger refresh
const PULL_RESISTANCE = 2.5; // damping factor

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

        if (!document.getElementById('pwa-ptr-keyframes')) {
            const style = document.createElement('style');
            style.id = 'pwa-ptr-keyframes';
            style.textContent = '@keyframes pwa-ptr-spin{from{transform:rotate(0deg)}to{transform:rotate(360deg)}}';
            document.head.appendChild(style);
        }

        this._indicator = document.createElement('div');
        this._indicator.style.cssText = 'position:fixed;top:0;left:0;right:0;z-index:9999;display:flex;align-items:center;justify-content:center;height:0;overflow:hidden;background:rgba(0,0,0,0.04);transition:none;pointer-events:none;';
        this._indicator.innerHTML = '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="color:#666;transition:transform .2s"><polyline points="23 4 23 10 17 10"/><path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"/></svg>';
        document.body.appendChild(this._indicator);

        this._onTouchStart = this._handleTouchStart.bind(this);
        this._onTouchMove = this._handleTouchMove.bind(this);
        this._onTouchEnd = this._handleTouchEnd.bind(this);

        document.addEventListener('touchstart', this._onTouchStart, { passive: true });
        document.addEventListener('touchmove', this._onTouchMove, { passive: false });
        document.addEventListener('touchend', this._onTouchEnd, { passive: true });
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
        this._pullDistance = delta / PULL_RESISTANCE;

        const height = Math.min(this._pullDistance, PULL_THRESHOLD + 20);
        this._indicator.style.height = height + 'px';

        const icon = this._indicator.querySelector('svg');
        const progress = Math.min(this._pullDistance / PULL_THRESHOLD, 1);
        const rotation = progress * 360;
        icon.style.transform = 'rotate(' + rotation + 'deg)';
        icon.style.opacity = progress;
    }

    _handleTouchEnd() {
        if (!this._pulling) return;
        this._pulling = false;

        if (this._pullDistance >= PULL_THRESHOLD) {
            this._indicator.style.transition = 'height 0.2s ease';
            this._indicator.style.height = '50px';
            const icon = this._indicator.querySelector('svg');
            icon.style.animation = 'pwa-ptr-spin 0.6s linear infinite';
            window.location.reload();
        } else {
            this._resetIndicator();
        }
    }

    _resetIndicator() {
        this._indicator.style.transition = 'height 0.2s ease';
        this._indicator.style.height = '0';
        setTimeout(() => {
            this._indicator.style.transition = 'none';
        }, 200);
    }
}
