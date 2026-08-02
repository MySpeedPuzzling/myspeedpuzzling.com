import { Controller } from '@hotwired/stimulus';

/**
 * Stimulus controller for delayed Google Analytics loading with bot detection.
 *
 * Loads GA ONLY after real user interaction (scroll, pointer, touch, key).
 * Skips loading entirely for detected bots.
 *
 * There is deliberately NO idle/timeout fallback: during the 2026-07/08 bot
 * wave, requestIdleCallback fired for idle headless browsers too, which is
 * how thousands of proxy-bot page loads a day became GA "active users". A
 * real human produces some input within moments on any device; a headless
 * page-loader does not. The cost is losing zero-interaction bounces from
 * the numbers - accepted in exchange for GA reflecting humans.
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

        // Interaction-only trigger (no idle fallback — see class comment)
        this.setupTriggers();
    }

    disconnect() {
        if (this.loadGA) {
            this.events?.forEach(e => document.removeEventListener(e, this.loadGA));
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
        this.events = ['scroll', 'mousemove', 'touchstart', 'keydown', 'click'];

        this.loadGA = () => {
            if (window.gaLoaded) return;
            window.gaLoaded = true;

            this.events.forEach(e => document.removeEventListener(e, this.loadGA));

            this.injectGA();
        };

        // User interaction triggers — the ONLY way GA loads
        this.events.forEach(e => document.addEventListener(e, this.loadGA, { once: true, passive: true }));
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
