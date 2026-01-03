import { Controller } from '@hotwired/stimulus';

/**
 * Stimulus controller for delayed Google Analytics loading with bot detection.
 *
 * Loads GA after user interaction (scroll, click, touch) OR a short timeout (300ms),
 * whichever comes first. Skips loading entirely for detected bots.
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
        // Cleanup timeout if controller disconnects before firing
        if (this.timeout) {
            clearTimeout(this.timeout);
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

            // Cleanup listeners
            events.forEach(e => document.removeEventListener(e, this.loadGA));
            clearTimeout(this.timeout);

            this.injectGA();
        };

        // User interaction triggers
        events.forEach(e => document.addEventListener(e, this.loadGA, { once: true, passive: true }));

        // Fallback timeout (300ms) - catches users who just read without interaction
        this.timeout = setTimeout(this.loadGA, 300);
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
