import { Controller } from '@hotwired/stimulus';

/**
 * Stimulus controller for delayed Google Analytics loading with bot detection.
 *
 * Loads GA after user interaction (scroll, click, touch) OR when browser is idle
 * (via requestIdleCallback), whichever comes first. Skips loading entirely for detected bots.
 */
export default class extends Controller {
    static values = {
        trackingId: String
    }

    connect() {
        // Skip if no tracking ID or already loaded
        if (!this.trackingIdValue || window.gaLoaded) return;

        // Bot detection first
        if (this.isLikelyBot()) {
            return;
        }

        // Hybrid trigger: user interaction OR timeout (whichever first)
        this.setupTriggers();
    }

    disconnect() {
        if (this.timeout) {
            clearTimeout(this.timeout);
        }
        if (this.idleId) {
            cancelIdleCallback(this.idleId);
        }
    }

    isLikelyBot() {
        // 1. WebDriver detection (headless browsers like Puppeteer, Playwright, Selenium)
        if (navigator.webdriver) return true;

        // 2. Common bot user agents
        const ua = navigator.userAgent;
        const botPatterns = /bot|crawl|spider|slurp|facebookexternalhit|Twitterbot|WhatsApp|TelegramBot|preview|Lighthouse|PageSpeed|GTmetrix|Pingdom|Chrome-Lighthouse/i;
        if (botPatterns.test(ua)) return true;

        // 3. Missing browser features real users have
        if (!navigator.cookieEnabled) return true;

        try {
            if (!window.localStorage) return true;
        } catch (e) {
            // localStorage access can throw in private browsing
            return true;
        }

        return false;
    }

    setupTriggers() {
        const events = ['scroll', 'mousemove', 'touchstart', 'keydown', 'click'];

        this.loadGA = () => {
            if (window.gaLoaded) return;
            window.gaLoaded = true;

            // Cleanup listeners and pending callbacks
            events.forEach(e => document.removeEventListener(e, this.loadGA));
            if (this.timeout) clearTimeout(this.timeout);
            if (this.idleId) cancelIdleCallback(this.idleId);

            this.injectGA();
        };

        // User interaction triggers
        events.forEach(e => document.addEventListener(e, this.loadGA, { once: true, passive: true }));

        // Fallback: wait until browser is idle so GA doesn't compete with LCP
        if ('requestIdleCallback' in window) {
            this.idleId = requestIdleCallback(this.loadGA, { timeout: 5000 });
        } else {
            this.timeout = setTimeout(this.loadGA, 3000);
        }
    }

    injectGA() {
        // Create and inject gtag.js script
        const script = document.createElement('script');
        script.src = `https://www.googletagmanager.com/gtag/js?id=${this.trackingIdValue}`;
        script.async = true;
        document.head.appendChild(script);

        // Initialize gtag
        window.dataLayer = window.dataLayer || [];
        function gtag(){dataLayer.push(arguments);}
        window.gtag = gtag;
        gtag('js', new Date());
        gtag('config', this.trackingIdValue);
    }
}
