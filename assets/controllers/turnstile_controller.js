import { Controller } from '@hotwired/stimulus';

// Explicit rendering: the script loads only when a form that needs the check is on the page, never site-wide
const SCRIPT_URL = 'https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit';

let scriptPromise = null;

function loadTurnstile() {
    if (window.turnstile) {
        return Promise.resolve(window.turnstile);
    }

    if (scriptPromise === null) {
        scriptPromise = new Promise((resolve, reject) => {
            const script = document.createElement('script');
            script.src = SCRIPT_URL;
            script.async = true;
            script.onload = () => (window.turnstile ? resolve(window.turnstile) : reject(new Error('Turnstile missing')));
            script.onerror = () => {
                // A content blocker or a network error - the next form may try again
                scriptPromise = null;
                script.remove();
                reject(new Error('Turnstile did not load'));
            };
            document.head.appendChild(script);
        });
    }

    return scriptPromise;
}

/**
 * Cloudflare Turnstile ("are you a person?") on a form - the newsletter signup step. The widget writes its
 * single-use token into the form as `cf-turnstile-response`; the server checks it (TurnstileVerifier). The submit
 * button stays disabled until the check passed and again when the token expired. When the script cannot load
 * (content blocker), a line says so instead of a button that never wakes up.
 */
export default class extends Controller {
    static targets = ['widget', 'submit', 'unavailable'];

    static values = {
        siteKey: String,
        action: String,
        language: String,
    };

    widgetId = null;

    connect() {
        this.submitTarget.disabled = true;

        loadTurnstile()
            .then((turnstile) => {
                if (!this.element.isConnected || this.widgetId !== null) {
                    return;
                }

                this.widgetId = turnstile.render(this.widgetTarget, {
                    sitekey: this.siteKeyValue,
                    action: this.actionValue,
                    language: this.languageValue || 'auto',
                    theme: 'light',
                    size: 'flexible',
                    callback: () => {
                        this.submitTarget.disabled = false;
                    },
                    'expired-callback': () => {
                        this.submitTarget.disabled = true;
                    },
                    'error-callback': () => {
                        this.submitTarget.disabled = true;
                    },
                });
            })
            .catch(() => {
                this.unavailableTarget.hidden = false;
            });
    }

    disconnect() {
        if (this.widgetId !== null && window.turnstile) {
            window.turnstile.remove(this.widgetId);
        }

        this.widgetId = null;
    }
}
