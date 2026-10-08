/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus';
import { dateLocale, entryTitle, fillArchiveLine, formatDate, readEventsIndex } from '../events_index.js';
import {
    buildMonth,
    calendarEntries,
    emptyMonthDirection,
    formatIso,
    idsOnDay,
    monthKey,
    parseMonth,
    runTextKind,
    shiftMonth,
    strongestKind,
    weekdayNames,
} from '../events_calendar.js';
import { chooseTranslation } from '../translation_choice.js';

// Events page (docs/features/events-page/implementation-plan.md, 2.B) - owner: workstream B - calendar view.
//
// Two modes on one controller:
// - `full` (templates/events/_calendar.html.twig, `?view=calendar`): month grid (phone: dots, desktop: names), Today
//   and ‹ ›, long-running occurrences as bars, a picked day highlights its rows, and the month's rows below - upcoming
//   and live rows cloned from the list, past ones as archive lines. The month lives in the URL (`month=2026-11`).
// - `rail` (templates/events/_rail_calendar.html.twig, desktop list view): a small grid with one dot per day; a day
//   dispatches `events-calendar:day` ({day, ids}) for the list to scroll to and flash its rows.
//
// The list page (events_page_controller.js) owns scope, search and view and announces them with `events-page:state`
// ({scope, query, view}) on its element; this controller reads the same state from that element's values on connect,
// so it never depends on having heard the first announcement.
export default class extends Controller {
    static targets = ['title', 'grid', 'runs', 'selection', 'selectionText', 'list', 'todayButton'];

    static values = {
        mode: { type: String, default: 'full' },
        today: String,
        month: String,
        messages: Object,
    };

    connect() {
        this.page = this.element.closest('.ev-page');
        this.index = readEventsIndex(this.page ?? document);
        // The server's date locale (EventsPageDates): English pages read "12 Nov 2027", not the US "Nov 12, 2027"
        this.locale = dateLocale(document.documentElement.lang);

        const data = this.page?.dataset ?? {};

        this.state = {
            scope: data.eventsPageScopeValue || 'all',
            query: data.eventsPageQueryValue || '',
            view: data.eventsPageViewValue || 'list',
        };
        this.todayIso = /^\d{4}-\d{2}-\d{2}$/.test(this.todayValue) ? this.todayValue : localToday();
        this.month = (this.isFull() ? parseMonth(this.monthValue) : null) ?? parseMonth(this.todayIso.slice(0, 7));
        this.selectedDay = null;
        this.stale = true;

        // A listener of our own, not a `data-action`: this controller is lazy, and an action bound while its chunk is
        // still loading invokes the placeholder controller, which has no `update` (a console error on every load)
        this.onState = (event) => this.update(event);
        document.addEventListener('events-page:state', this.onState);

        this.applyView();
    }

    disconnect() {
        document.removeEventListener('events-page:state', this.onState);
    }

    // `events-page:state` from the list page
    update(event) {
        const detail = event?.detail ?? {};
        const next = {
            scope: typeof detail.scope === 'string' ? detail.scope : this.state.scope,
            query: typeof detail.query === 'string' ? detail.query : this.state.query,
            view: typeof detail.view === 'string' ? detail.view : this.state.view,
        };

        if (next.scope !== this.state.scope || next.query !== this.state.query) {
            this.selectedDay = null;
            this.stale = true;
        }

        const viewChanged = next.view !== this.state.view;

        this.state = next;

        if (viewChanged) {
            // A fresh look at the calendar starts without a picked day; rows may have changed meanwhile (stars)
            this.selectedDay = null;
            this.stale = true;
        }

        this.applyView();
    }

    previous() {
        this.goTo(shiftMonth(this.month, -1), 'prev');
    }

    next() {
        this.goTo(shiftMonth(this.month, 1), 'next');
    }

    today() {
        this.goTo(parseMonth(this.todayIso.slice(0, 7)), 'today');
    }

    clearDay() {
        this.selectedDay = null;
        this.renderGrids();
        this.renderSelection();
        this.highlightList();
    }

    gridClick(event) {
        const button = event.target.closest('button[data-day]');

        if (!button || !this.gridTarget.contains(button)) {
            return;
        }

        const day = button.dataset.day;

        if (!this.isFull()) {
            const ids = idsOnDay(this.entries(), day);

            this.dispatch('day', { detail: { day, ids } });
            track('events_calendar_day', { value: 'rail' });

            return;
        }

        this.selectedDay = this.selectedDay === day ? null : day;
        track('events_calendar_day', { value: this.selectedDay === null ? 'clear' : 'calendar' });

        this.renderGrids();
        this.renderSelection();
        this.highlightList();
        this.focusDay(day);

        if (this.selectedDay !== null) {
            const hit = this.listTarget.querySelector('.ev-cal-hit');

            hit?.scrollIntoView({ block: 'nearest', behavior: reducedMotion() ? 'auto' : 'smooth' });
        }
    }

    // Arrows move between the days that have something (left/right: the previous/next one, up/down: a week back or
    // forward, or the nearest one beyond), Home/End to the first/last
    gridKeydown(event) {
        const button = event.target.closest('button[data-day]');

        if (!button || !['ArrowLeft', 'ArrowRight', 'ArrowUp', 'ArrowDown', 'Home', 'End'].includes(event.key)) {
            return;
        }

        const buttons = [...button.parentElement.querySelectorAll('button[data-day]')];
        const at = buttons.indexOf(button);
        const day = Number(button.dataset.day.slice(8));
        let target = null;

        switch (event.key) {
            case 'ArrowLeft':
                target = buttons[at - 1];
                break;
            case 'ArrowRight':
                target = buttons[at + 1];
                break;
            case 'ArrowUp':
                target = [...buttons].reverse().find((candidate) => Number(candidate.dataset.day.slice(8)) <= day - 7);
                break;
            case 'ArrowDown':
                target = buttons.find((candidate) => Number(candidate.dataset.day.slice(8)) >= day + 7);
                break;
            case 'Home':
                target = buttons[0];
                break;
            case 'End':
                target = buttons[buttons.length - 1];
                break;
        }

        event.preventDefault();
        target?.focus();
    }

    // --- internals ---

    isFull() {
        return this.modeValue !== 'rail';
    }

    applyView() {
        const visible = this.isFull() ? this.state.view === 'calendar' : this.state.view !== 'calendar';

        this.element.hidden = !visible;

        // A hidden calendar is redrawn when it is shown, not on every keystroke in the search
        if (visible && this.stale) {
            this.render();
        }
    }

    goTo(month, direction) {
        if (!month) {
            return;
        }

        this.month = month;
        this.selectedDay = null;
        this.render();
        track('events_calendar_month', { value: direction, calendar: this.isFull() ? 'calendar' : 'rail' });

        if (this.isFull()) {
            this.writeMonth(direction === 'today' ? '' : monthKey(month));
        }
    }

    // The month in the URL (`?view=calendar&month=2026-11`) and in the list page's state, which writes the URL too
    writeMonth(key) {
        if (this.page) {
            this.page.dataset.eventsPageMonthValue = key;
        }

        try {
            const url = new URL(window.location.href);

            if (key === '') {
                url.searchParams.delete('month');
            } else {
                url.searchParams.set('month', key);
            }

            window.history.replaceState(window.history.state, '', url.toString());
        } catch {
            // an unusual URL or a sandbox without history - the calendar works without it
        }
    }

    entries() {
        return calendarEntries(this.index, this.isFull()
            ? { scope: this.state.scope, query: this.state.query }
            : { scope: this.state.scope });
    }

    render() {
        this.stale = false;
        this.monthData = buildMonth(this.entries(), this.month, this.todayIso);

        if (this.hasTitleTarget) {
            this.titleTarget.textContent = this.monthTitle();
        }

        if (this.hasTodayButtonTarget) {
            this.todayButtonTarget.disabled = monthKey(this.month) === this.todayIso.slice(0, 7);
        }

        this.renderGrids();

        if (this.isFull()) {
            this.renderRuns();
            this.renderSelection();
            this.renderList();
            this.highlightList();
        }
    }

    monthTitle() {
        return formatDate(`${monthKey(this.month)}-01`, this.locale, 'yMMMM');
    }

    renderGrids() {
        if (!this.hasGridTarget || !this.monthData) {
            return;
        }

        if (this.isFull()) {
            this.gridTarget.replaceChildren(this.buildGrid('small'), this.buildGrid('big'));
        } else {
            this.gridTarget.replaceChildren(this.buildGrid('rail'));
        }
    }

    // `small`: the phone grid with dots; `big`: the desktop grid with up to 2 names a day; `rail`: one dot a day
    buildGrid(size) {
        const { lead, days } = this.monthData;
        const grid = element('div', `ev-cal-grid ev-cal-grid-${size}`);

        for (const name of weekdayNames(this.locale, 'short')) {
            const head = element('span', 'ev-cal-dow', name);

            head.setAttribute('aria-hidden', 'true');
            grid.append(head);
        }

        for (let i = 0; i < lead; i++) {
            grid.append(element('span', 'ev-cal-pad'));
        }

        for (const day of days) {
            grid.append(this.buildDay(day, size));
        }

        return grid;
    }

    buildDay(day, size) {
        const count = day.entries.length;
        const selected = this.isFull() && this.selectedDay === day.iso;
        const cell = element(count > 0 ? 'button' : 'span', 'ev-cal-day');

        if (day.isToday) {
            cell.classList.add('ev-cal-day-today');
            cell.setAttribute('aria-current', 'date');
        }

        cell.append(element('span', 'ev-cal-num', String(day.day)));

        if (count === 0) {
            return cell;
        }

        cell.type = 'button';
        cell.dataset.day = day.iso;
        cell.classList.add('ev-cal-day-has');
        cell.setAttribute('aria-label', this.dayLabel(day));

        if (this.isFull()) {
            cell.setAttribute('aria-pressed', selected ? 'true' : 'false');
        }

        if (selected) {
            cell.classList.add('ev-cal-day-selected');
        }

        if (size === 'rail') {
            cell.classList.add(`ev-cal-day-${strongestKind(day.kinds)}`);
            cell.title = day.entries.map((entry) => entry.n).join(', ');

            return cell;
        }

        if (size === 'small') {
            const dots = element('span', 'ev-cal-dots');

            dots.setAttribute('aria-hidden', 'true');

            for (const kind of day.kinds) {
                dots.append(element('i', `ev-cal-dot ev-cal-dot-${kind}`));
            }

            cell.append(dots);

            return cell;
        }

        for (const entry of day.entries.slice(0, 2)) {
            const name = element('span', `ev-cal-name ev-cal-name-${entryKindClass(entry)}`, String(entry.n ?? ''));

            name.setAttribute('aria-hidden', 'true');
            cell.append(name);
        }

        if (count > 2) {
            const more = element('span', 'ev-cal-more', String(this.messagesValue.more ?? '+%count%').replace('%count%', String(count - 2)));

            more.setAttribute('aria-hidden', 'true');
            cell.append(more);
        }

        return cell;
    }

    dayLabel(day) {
        const label = this.messagesValue.dayLabel;
        const count = day.entries.length;
        const text = label ? chooseTranslation(label.message, count, label.locale) : null;
        const date = formatIso(day.iso, this.locale, { weekday: 'long', day: 'numeric', month: 'long' });
        const names = day.entries.map((entry) => entry.n).join(', ');

        return `${(text ?? `%day%: ${count}`).replace('%day%', date)} (${names})`;
    }

    renderRuns() {
        if (!this.hasRunsTarget) {
            return;
        }

        const runs = this.monthData.runs.map((run) => {
            const line = element('div', `ev-cal-run ev-cal-run-${run.kind}`);
            const bar = element('span', 'ev-cal-run-bar');
            const text = element('span', 'ev-cal-run-text');
            const title = titleOf(run.entry);
            const name = run.entry.u ? element('a', 'ev-cal-run-name', title) : element('b', 'ev-cal-run-name', title);

            if (run.entry.u) {
                name.href = run.entry.u;
            }

            bar.setAttribute('aria-hidden', 'true');
            text.append(name, ` ${this.runText(run)}`);
            line.append(bar, text);

            return line;
        });

        this.runsTarget.replaceChildren(...runs);
        this.runsTarget.hidden = runs.length === 0;
    }

    runText(run) {
        const until = formatDate(run.entry.t || run.entry.f, this.locale, 'yMMMd');
        const from = formatDate(run.entry.f, this.locale, 'MMMd');
        const message = {
            all_month: this.messagesValue.runAllMonth,
            until: this.messagesValue.runUntil,
            from: this.messagesValue.runFrom,
        }[runTextKind(run)] ?? '%until%';

        return String(message).replace('%from%', from).replace('%until%', until);
    }

    renderSelection() {
        if (!this.hasSelectionTarget) {
            return;
        }

        this.selectionTarget.hidden = this.selectedDay === null;

        if (this.selectedDay !== null && this.hasSelectionTextTarget) {
            const date = formatIso(this.selectedDay, this.locale, { weekday: 'long', day: 'numeric', month: 'long' });

            this.selectionTextTarget.textContent = String(this.messagesValue.highlighted ?? '%date%').replace('%date%', date);
        }
    }

    // The month's occurrences below the grid: past ones as archive lines (with the year), live and upcoming ones as the
    // list's own rows, cloned
    renderList() {
        if (!this.hasListTarget) {
            return;
        }

        const { items } = this.monthData;

        if (items.length === 0) {
            this.listTarget.replaceChildren(this.buildEmpty());

            return;
        }

        const heading = element('h3', 'ev-cal-list-title', this.monthTitle());
        const countMessage = this.messagesValue.monthCount;
        const countText = countMessage ? chooseTranslation(countMessage.message, items.length, countMessage.locale) : null;

        if (countText) {
            heading.append(' ', element('span', 'ev-cal-list-count', countText));
        }

        const children = [heading];
        const past = items.filter((entry) => entry.st === 'past');
        const coming = items.filter((entry) => entry.st !== 'past');

        if (past.length > 0) {
            const lines = element('ul', 'ev-lines ev-cal-lines');

            lines.append(...past.map((entry) => this.archiveLine(entry)));
            children.push(lines);
        }

        if (coming.length > 0) {
            const rows = element('ul', 'ev-rows ev-cal-rows');
            const used = new Set();

            for (const entry of coming) {
                const source = this.findRow(entry.id);

                if (source === null) {
                    rows.append(this.archiveLine(entry));
                    continue;
                }

                if (used.has(source)) {
                    continue;
                }

                used.add(source);
                rows.append(cloneRow(source));
            }

            children.push(rows);
        }

        this.listTarget.replaceChildren(...children);
    }

    buildEmpty() {
        const box = element('div', 'ev-cal-empty');
        const title = String(this.state.query.trim() !== '' ? this.messagesValue.emptyMatching : this.messagesValue.empty)
            .replace('%month%', this.monthTitle());
        // Towards the side that has matches; "Everywhere" while a country or Online is picked
        const direction = emptyMonthDirection(this.entries(), this.month);
        const scoped = Boolean(this.state.scope && this.state.scope !== 'all');
        const hint = {
            next: scoped ? this.messagesValue.emptyHintScope : this.messagesValue.emptyHint,
            previous: scoped ? this.messagesValue.emptyHintScopePrevious : this.messagesValue.emptyHintPrevious,
        }[direction] ?? (scoped ? this.messagesValue.emptyHintScopeNone : this.messagesValue.emptyHintNone);

        box.append(element('p', 'ev-cal-empty-title', title));

        if (hint) {
            box.append(element('p', 'ev-cal-empty-hint', String(hint)));
        }

        return box;
    }

    // The agenda's row of an index entry (a month roll-up row carries every session's id), or its "Ongoing" line (a long
    // span while it runs). Not "Your events" cards (`.ev-your-event`) and never an earlier clone.
    findRow(id) {
        if (!this.page) {
            return null;
        }

        for (const row of this.page.querySelectorAll(`.ev-row[data-ev-ids~="${Number(id)}"], .ev-ongoing-line[data-ev-ids~="${Number(id)}"]`)) {
            if (!this.element.contains(row) && !row.closest('[data-ev-calendar]')) {
                return row;
            }
        }

        return null;
    }

    archiveLine(entry) {
        const template = this.page?.querySelector('template[data-events-archive-line-template]');

        if (!template) {
            const line = element('li', 'ev-line');
            const link = element(entry.u ? 'a' : 'span', 'ev-line-title', titleOf(entry));

            if (entry.u) {
                link.href = entry.u;
            }

            line.dataset.evIds = String(entry.id);
            line.append(link);

            return line;
        }

        return fillArchiveLine(template, entry, {
            locale: this.locale,
            withYear: true,
            resultsLabel: this.messagesValue.results ?? '',
            onlineLabel: this.messagesValue.online ?? '',
        });
    }

    // A picked day: its rows stand out, the others step back
    highlightList() {
        if (!this.hasListTarget) {
            return;
        }

        const ids = this.selectedDay === null ? null : new Set(idsOnDay(this.entries(), this.selectedDay));

        for (const item of this.listTarget.querySelectorAll('.ev-row, .ev-line')) {
            const hit = ids !== null && String(item.dataset.evIds ?? '').split(' ').some((id) => id !== '' && ids.has(Number(id)));

            item.classList.toggle('ev-cal-hit', hit);
            item.classList.toggle('ev-cal-dim', ids !== null && !hit);
        }
    }

    focusDay(day) {
        const buttons = [...this.gridTarget.querySelectorAll(`button[data-day="${day}"]`)];
        const visible = buttons.find((button) => button.offsetParent !== null) ?? buttons[0];

        visible?.focus({ preventScroll: true });
    }
}

function element(tag, className, text) {
    const node = document.createElement(tag);

    if (className) {
        node.className = className;
    }

    if (text !== undefined) {
        node.textContent = text;
    }

    return node;
}

function titleOf(entry) {
    return entryTitle(entry);
}

function entryKindClass(entry) {
    if (entry.st === 'past') {
        return 'past';
    }

    return entry.sc === 'online' ? 'online' : 'in_person';
}

// A list row shown again under the calendar: visible whatever the list's own filter says, without the list's
// transient marks
function cloneRow(source) {
    const row = source.cloneNode(true);

    row.hidden = false;
    row.removeAttribute('hidden');
    row.classList.remove('ev-flash', 'ev-cal-hit', 'ev-cal-dim');

    return row;
}

function localToday() {
    const now = new Date();

    return `${now.getUTCFullYear()}-${String(now.getUTCMonth() + 1).padStart(2, '0')}-${String(now.getUTCDate()).padStart(2, '0')}`;
}

function reducedMotion() {
    return typeof window.matchMedia === 'function' && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
}

// Google Analytics, when it is there - never an error when it is absent or blocked
function track(name, params) {
    try {
        if (typeof window.gtag === 'function') {
            window.gtag('event', name, params);
        }
    } catch {
        // analytics must never break the page
    }
}
