/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus';
import { formatRelativeTime, secondsUntilChange } from '../relative_time.js';
import { subscribe, wake } from '../page_ticker.js';

/**
 * Keeps the "… ago" labels (`<time datetime data-relative-time>`) inside the element counting, worded
 * exactly like Twig `|ago` (assets/relative_time.js). Only text is written: Live Component remembers
 * attributes changed by outside JavaScript and lays them back over every re-render, text it leaves
 * alone - so the next re-render simply brings the server's fresh text.
 */
export default class extends Controller {
    static values = {
        messages: Object,
        locale: String,
        // Unix seconds when the server rendered the element - the visitor's clock may be minutes off
        serverNow: Number,
    };

    initialize() {
        this.offset = 0;
    }

    connect() {
        this.unsubscribe = subscribe((now) => this.update(now));
    }

    disconnect() {
        this.unsubscribe();
    }

    serverNowValueChanged() {
        if (!this.hasServerNowValue) {
            return;
        }

        // Every re-render brings the server's clock along, so the offset never drifts
        this.offset = this.serverNowValue * 1000 - Date.now();
        wake();
    }

    update(now) {
        const serverNow = (now + this.offset) / 1000;
        let next = Infinity;

        for (const label of this.element.querySelectorAll('time[data-relative-time]')) {
            const at = Date.parse(label.getAttribute('datetime')) / 1000;

            if (Number.isNaN(at)) {
                continue;
            }

            const elapsed = serverNow - at;
            const text = formatRelativeTime(elapsed, this.messagesValue, this.localeValue);

            if (text === null) {
                continue;
            }

            if (label.textContent !== text) {
                label.textContent = text;
            }

            next = Math.min(next, serverNow + secondsUntilChange(elapsed));
        }

        return next === Infinity ? Infinity : next * 1000 - this.offset;
    }
}
