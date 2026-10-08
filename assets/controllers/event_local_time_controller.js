/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus';
import { dateLocale, visitorTime } from '../events_index.js';

/**
 * The visitor's own time next to an online event's start times (docs/features/events-page/detail-pages.md, "Times and
 * time zones"). On the page root of online events only: fills every empty `[data-local-time]` that follows a
 * `<time data-event-time datetime="…Z" data-event-zone="America/New_York">` with "00:45 next day, Central European Time
 * (yours)" and shows it - only when the visitor's zone gives another wall time. The zone is always named: the browser's
 * zone can be wrong (travel, VPN). Nothing else - no timers; the server HTML is the same for everyone.
 */
export default class extends Controller {
    static values = { messages: Object };

    connect() {
        let visitorZone;

        try {
            visitorZone = Intl.DateTimeFormat().resolvedOptions().timeZone;
        } catch {
            return;
        }

        const locale = dateLocale(document.documentElement.lang);
        const messages = this.messagesValue || {};

        this.element.querySelectorAll('[data-local-time]').forEach((slot) => {
            const time = slot.closest('.ev-time')?.querySelector('time[data-event-time]');

            if (!time) {
                return;
            }

            const local = visitorTime(time.getAttribute('datetime'), time.dataset.eventZone, locale, visitorZone);

            if (local === null) {
                slot.hidden = true;
                slot.textContent = '';

                return;
            }

            const key = local.dayShift > 0 ? 'yours_next_day' : (local.dayShift < 0 ? 'yours_previous_day' : 'yours');
            const template = messages[key] || '%time%, %zone%';

            slot.textContent = template.replace('%time%', local.time).replace('%zone%', local.zone);
            slot.hidden = false;
        });
    }
}
