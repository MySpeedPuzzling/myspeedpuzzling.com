/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus';
import { StandingLive } from '../standing_live.js';

const FETCH_TIMEOUT_MS = 10000;

/**
 * The public standing page follows its event's standings signal (templates/standing.html.twig, competition toolkit v2
 * S4, docs/features/competitions-management/standings.md "Live push") - the DOM adapter of assets/standing_live.js: the
 * `public_standing_table` fragment fetch, the swap of the `standing-table` frame's content, visibility and the
 * "Updated at …" line (aria-live polite). The frame's content is replaced only when it differs - the page keeps its
 * scroll position and nothing flickers.
 */
export default class extends Controller {
    static targets = ['frame', 'status'];

    static values = {
        url: String,
        topic: String,
        hub: String,
    };

    connect() {
        this.onVisibility = () => {
            if (document.visibilityState === 'visible') {
                this.live?.onVisible();
            }
        };
        document.addEventListener('visibilitychange', this.onVisibility);

        this.live = new StandingLive({
            hubUrl: this.hubValue,
            topic: this.topicValue,
            fetchTable: () => this.fetchTable(),
            apply: (html) => this.apply(html),
            updated: () => this.showUpdated(),
            isVisible: () => document.visibilityState !== 'hidden',
        });
        this.live.start();
    }

    disconnect() {
        document.removeEventListener('visibilitychange', this.onVisibility);
        this.live?.close();
        this.live = null;
    }

    async fetchTable() {
        const controller = typeof AbortController === 'undefined' ? null : new AbortController();
        const timer = setTimeout(() => controller?.abort(), FETCH_TIMEOUT_MS);

        try {
            const response = await fetch(this.urlValue, {
                headers: { Accept: 'text/html' },
                credentials: 'same-origin',
                cache: 'no-store',
                signal: controller?.signal,
            });

            if (response.status === 404) {
                return { status: 'gone' };
            }

            if (!response.ok) {
                return { status: 'error' };
            }

            return { status: 'ok', html: await response.text() };
        } catch (e) {
            return { status: 'error' };
        } finally {
            clearTimeout(timer);
        }
    }

    apply(html) {
        if (!this.hasFrameTarget) {
            return false;
        }

        const fragment = new DOMParser().parseFromString(html, 'text/html').querySelector('turbo-frame#standing-table');

        if (fragment === null || fragment.innerHTML.trim() === this.frameTarget.innerHTML.trim()) {
            return false;
        }

        this.frameTarget.innerHTML = fragment.innerHTML;

        return true;
    }

    showUpdated() {
        if (!this.hasStatusTarget) {
            return;
        }

        const label = this.statusTarget.dataset.standingLiveUpdated ?? '';
        const time = new Date().toLocaleTimeString(document.documentElement.lang || undefined, { hour: '2-digit', minute: '2-digit' });
        this.statusTarget.textContent = label.replace('%time%', time);
    }
}
