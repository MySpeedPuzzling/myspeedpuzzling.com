import { Controller } from '@hotwired/stimulus';
import { readEventsIndex, scopeMatches, createQueryMatcher, fillArchiveLine } from '../events_index.js';
import { guessCountry } from '../country_guess.js';
import { foldSearchText } from '../search_fold.js';
import { chooseTranslation } from '../translation_choice.js';

// Events page (docs/features/events-page/implementation-plan.md) - owner: workstream A - list page UI.
//
// Owns the page's state - scope (`all`, `online`, a country code), view (`list` / `calendar`) and the search - and
// changes the page in place over the rows the server rendered (every row of every scope is there, `hidden` when out
// of scope) and the embedded index (assets/events_index.js): rows, month headers and counts, the series directory,
// the archive and the past search results. Everything starts as a plain link or the GET form, so the page works
// without JavaScript; the server renders any URL in the same state (no flash).
//
// Contract for the calendar (workstream B):
// - after every change (and once on connect) the element dispatches `events-page:state`, detail {scope, query, view};
//   it bubbles to the document (the lazy calendar listens there with addEventListener - an action would hit its
//   placeholder while the chunk loads). The current state is also on the element's own
//   values (`data-events-page-scope-value`, `-query-value`, `-view-value`) for a controller that connects later.
// - `events-calendar:day` (detail {day: 'YYYY-MM-DD'|null, ids: number[]}) dispatched inside the page scrolls to the
//   first matching row and flashes them, opening the archive year when needed.
// - the URL keeps `month` while the calendar view is on; B changes it with history.replaceState.

const ARCHIVE_PREVIEW_LINES = 5; // EventsPageBuilder::ARCHIVE_PREVIEW_LINES
const SEARCH_PAST_LINES = 30;
const PHONE_QUERY = '(max-width: 991.98px)';
const SLIDE_AWAY_AFTER = 160;
const SCROLL_HYSTERESIS = 6;
const SLIDE_MS = 200;
const SEARCH_TRACK_DELAY = 1000;
const ANNOUNCE_DELAY = 700;

export default class extends Controller {
    static targets = [
        'listView', 'toolbar', 'search', 'searchHidden', 'searchClear', 'chips', 'guessChip', 'agenda', 'archive',
        'searchPast', 'searchNothing', 'sheet', 'sheetPanel', 'sheetSearch', 'sheetEmpty', 'announcer',
    ];

    static values = {
        scope: String,
        view: String,
        query: String,
        month: String,
        home: String,
        signedIn: Boolean,
        messages: Object,
    };

    connect() {
        this.index = readEventsIndex(this.element);
        this.byId = new Map(this.index.map((entry) => [entry.id, entry]));
        this.locale = document.documentElement.lang || 'en';
        this.lineTemplate = this.element.querySelector('template[data-events-archive-line-template]');
        this.archiveUrls = this.readArchiveUrls();
        this.newestYear = this.pastYears()[0] ?? null;

        this.state = {
            scope: this.scopeValue || 'all',
            view: this.viewValue === 'calendar' ? 'calendar' : 'list',
            query: this.queryValue || '',
        };
        // The archive opens the newest year in Everywhere; a year chip opens another one in place
        this.archiveOpen = { year: this.newestYear, all: false };
        // What the server rendered - nothing to re-render until it changes
        this.renderedArchive = this.archiveKey();
        this.renderedSearch = this.searchKey();

        this.offerGuess();
        this.startToolbar();

        if (window.location.hash === '#ev-sheet') {
            // Opened through :target (no JavaScript yet) - take it over
            history.replaceState(history.state, '', window.location.pathname + window.location.search);
            this.openSheet();
        }

        this.apply({ initial: true });
    }

    disconnect() {
        this.stopToolbar();
        this.unlockScroll();
        clearTimeout(this.searchTrackTimer);
        clearTimeout(this.announceTimer);
    }

    // ---- actions ---------------------------------------------------------------------------------------------------

    chooseScope(event) {
        const link = event.currentTarget;
        const scope = link.dataset.evScope;

        if (!scope || this.isModifiedClick(event)) {
            return;
        }

        event.preventDefault();
        const fromSheet = this.hasSheetTarget && this.sheetTarget.contains(link);

        if (fromSheet) {
            this.closeSheet(null, { restoreFocus: false });
        }

        const changed = scope !== this.state.scope;
        this.state.scope = scope;
        this.archiveOpen = { year: this.newestYear, all: false };
        this.apply();

        if (changed) {
            this.track('events_scope', scope);
        }

        this.scrollToList();

        if (fromSheet) {
            this.focusQuietly(this.element.querySelector(`.ev-chips [data-ev-scope="${CSS.escape(scope)}"]:not([hidden])`) ?? this.sheetOpener);
        }
    }

    chooseView(event) {
        const view = event.currentTarget.dataset.evView;

        if (!view || this.isModifiedClick(event)) {
            return;
        }

        event.preventDefault();

        if (view === this.state.view) {
            return;
        }

        this.state.view = view;
        this.apply();
        this.track('events_view', view);
        this.scrollToList();
    }

    search() {
        this.state.query = this.searchTarget.value;
        this.apply();

        clearTimeout(this.searchTrackTimer);
        const query = this.state.query.trim();

        if (query !== '') {
            this.searchTrackTimer = setTimeout(() => this.track('events_search', query), SEARCH_TRACK_DELAY);
        }
    }

    clearSearch(event) {
        if (event?.type === 'keydown' && this.searchTarget.value === '') {
            return;
        }

        event?.preventDefault();
        this.searchTarget.value = '';
        this.state.query = '';
        this.apply();
        this.searchTarget.focus();
    }

    submitSearch(event) {
        event.preventDefault();

        // The keyboard covers half a phone screen - the results are already there
        if (window.matchMedia(PHONE_QUERY).matches) {
            this.searchTarget.blur();
        }
    }

    toggleYear(event) {
        if (this.isModifiedClick(event)) {
            return;
        }

        event.preventDefault();
        const year = Number(event.currentTarget.dataset.evYear);
        this.archiveOpen = this.archiveOpen.year === year ? { year: null, all: false } : { year, all: false };
        this.apply();
        this.focusQuietly(this.archiveTarget.querySelector(`[data-ev-year="${year}"]`));
    }

    showAllYear(event) {
        if (this.isModifiedClick(event)) {
            return;
        }

        event.preventDefault();
        this.archiveOpen = { year: this.archiveOpen.year ?? this.newestYear, all: true };
        this.apply();
        // Keyboard users go on with the first line that was not there before
        const next = this.archiveTarget.querySelectorAll('.ev-archive-lines .ev-line')[ARCHIVE_PREVIEW_LINES];
        this.focusQuietly(next?.querySelector('a') ?? null);
    }

    jumpToDay(event) {
        const ids = Array.isArray(event.detail?.ids) ? event.detail.ids.map(Number) : [];

        if (ids.length === 0) {
            return;
        }

        let found = this.findForDay(ids);

        if (found.missing) {
            // Search or a closed archive year hides some of them
            if (this.state.query.trim() !== '') {
                this.state.query = '';
                this.searchTarget.value = '';
            }

            const day = String(event.detail?.day ?? '');

            if (this.state.scope === 'all' && /^\d{4}-/.test(day)) {
                this.archiveOpen = { year: Number(day.slice(0, 4)), all: true };
            }

            this.apply();
            found = this.findForDay(ids);
        }

        if (found.elements.length === 0) {
            return;
        }

        found.elements[0].scrollIntoView({ block: 'center', behavior: this.reducedMotion() ? 'auto' : 'smooth' });
        found.elements.forEach((element) => this.flash(element));
    }

    // ---- country sheet ---------------------------------------------------------------------------------------------

    openSheet(event) {
        if (event) {
            if (this.isModifiedClick(event)) {
                return;
            }

            event.preventDefault();
            this.sheetOpener = event.currentTarget;
        }

        if (!this.hasSheetTarget) {
            return;
        }

        this.sheetTarget.classList.add('is-open');
        this.lockScroll();
        this.toolbarAway(false);

        if (this.hasSheetSearchTarget) {
            this.sheetSearchTarget.value = '';
            this.filterSheet();
        }

        // On a phone the keyboard would cover the list - the search gets focus only with a mouse
        const fine = window.matchMedia('(pointer: fine)').matches;
        this.focusQuietly(fine && this.hasSheetSearchTarget ? this.sheetSearchTarget : this.sheetPanelTarget);
    }

    closeSheet(event, { restoreFocus = true } = {}) {
        event?.preventDefault?.();

        if (!this.hasSheetTarget || !this.sheetTarget.classList.contains('is-open')) {
            return;
        }

        this.sheetTarget.classList.remove('is-open');
        this.unlockScroll();

        if (restoreFocus) {
            this.focusQuietly(this.sheetOpener);
        }
    }

    sheetBackdrop(event) {
        if (event.target === this.sheetTarget) {
            this.closeSheet(event);
        }
    }

    sheetKeydown(event) {
        if (event.key === 'Escape') {
            this.closeSheet(event);

            return;
        }

        if (event.key !== 'Tab') {
            return;
        }

        const focusable = [...this.sheetPanelTarget.querySelectorAll('a[href], button:not([disabled]), input:not([disabled])')]
            .filter((element) => element.offsetParent !== null);

        if (focusable.length === 0) {
            return;
        }

        const first = focusable[0];
        const last = focusable[focusable.length - 1];

        if (event.shiftKey && (document.activeElement === first || document.activeElement === this.sheetPanelTarget)) {
            event.preventDefault();
            last.focus();
        } else if (!event.shiftKey && document.activeElement === last) {
            event.preventDefault();
            first.focus();
        }
    }

    filterSheet() {
        const query = foldSearchText(this.sheetSearchTarget.value);
        let any = false;

        this.sheetTarget.querySelectorAll('[data-ev-sheet-group]').forEach((group) => {
            let groupAny = false;

            group.querySelectorAll('li[data-ev-search]').forEach((item) => {
                const match = query === '' || query.split(' ').every((token) => item.dataset.evSearch.includes(token));
                item.hidden = !match;
                groupAny = groupAny || match;
            });

            group.hidden = !groupAny;
            any = any || groupAny;
        });

        if (this.hasSheetEmptyTarget) {
            this.sheetEmptyTarget.hidden = any;
        }
    }

    // ---- the one place the page follows the state ------------------------------------------------------------------

    apply({ initial = false } = {}) {
        const { scope, view } = this.state;
        const query = this.state.query.trim();
        const matcher = query === '' ? null : createQueryMatcher(query);
        const hits = matcher === null ? null : new Set(this.index.filter(matcher).map((entry) => entry.id));
        const searching = hits !== null;
        const inScope = (key) => scopeMatches(key ?? '', scope);
        const matches = (ids) => hits === null || ids.some((id) => hits.has(id));
        const nothing = searching && ![...hits].some((id) => inScope(this.byId.get(id)?.sc));
        const scopeName = this.scopeName(scope);
        const prefix = scopeName === '' ? '' : `${scopeName} · `;
        let resultCount = 0;

        this.element.classList.toggle('is-searching', searching);

        // Your events: Everywhere, no search
        this.section('your', (section) => {
            section.hidden = !(scope === 'all' && !searching);
        });

        // Agenda
        if (this.hasAgendaTarget) {
            const agenda = this.agendaTarget;
            agenda.hidden = nothing;
            let datedShown = 0;
            let dates = 0;
            let anyShown = false;
            let tbaCount = 0;

            agenda.querySelectorAll('[data-ev-group]').forEach((group) => {
                const kind = group.dataset.evGroup;
                let groupShown = 0;
                let groupCount = 0;

                group.querySelectorAll('.ev-row').forEach((row) => {
                    const shown = inScope(row.dataset.evScope) && matches(this.rowIds(row));
                    row.hidden = !shown;

                    if (!shown) {
                        return;
                    }

                    groupShown++;

                    if (!row.classList.contains('ev-row-pending')) {
                        groupCount += kind === 'tba' ? 1 : this.rowIds(row).length;
                    }
                });

                group.hidden = groupShown === 0;
                anyShown = anyShown || groupShown > 0;
                resultCount += groupShown;

                if (kind === 'tba') {
                    tbaCount = groupCount;
                } else {
                    dates += groupCount;
                }

                if (kind === 'month') {
                    datedShown += groupShown;
                }

                this.setText(group.querySelector('[data-ev-group-count]'), this.t(kind === 'tba' ? 'eventsCount' : 'datesCount', { count: groupCount }));
                this.setText(group.querySelector('[data-ev-scope-prefix]'), prefix);
            });

            this.setText(agenda.querySelector('[data-ev-agenda-title]'), searching || scope === 'all' ? this.t('upcoming') : this.t('upcomingIn', { scope: scopeName }));
            this.setText(
                agenda.querySelector('[data-ev-agenda-count]'),
                String(dates) + (tbaCount > 0 ? ` · ${this.t('withoutDate', { count: tbaCount })}` : ''),
            );

            const noUpcoming = agenda.querySelector('[data-ev-no-upcoming]');

            if (noUpcoming) {
                noUpcoming.hidden = searching || datedShown > 0 || !anyShown;

                if (!noUpcoming.hidden) {
                    noUpcoming.textContent = scope === 'online'
                        ? this.t('noUpcomingOnline')
                        : (scope === 'all' ? this.t('noDates', { count: 0 }) : this.t('noUpcomingIn', { scope: scopeName }));
                }
            }

            const noMatch = agenda.querySelector('[data-ev-no-match]');

            if (noMatch) {
                noMatch.hidden = !searching || anyShown;
            }

            const empty = agenda.querySelector('[data-ev-empty]');

            if (empty) {
                empty.hidden = searching || anyShown;

                if (!empty.hidden) {
                    this.fillEmptyState(empty, scope, scopeName);
                }
            }

            const callout = agenda.querySelector('[data-ev-home-callout]');

            if (callout) {
                callout.hidden = searching || !(scope === 'all' || scope === callout.dataset.evHomeCallout);
            }
        }

        // Series directory and ongoing online
        this.section('series', (section) => {
            let total = 0;

            section.querySelectorAll('[data-ev-series-group]').forEach((group) => {
                let shownCount = 0;

                group.querySelectorAll('.ev-series-line').forEach((line) => {
                    const shown = inScope(line.dataset.evScope) && matches(this.rowIds(line));
                    line.hidden = !shown;
                    shownCount += shown ? 1 : 0;
                });

                group.hidden = shownCount === 0;
                this.setText(group.querySelector('[data-ev-group-count]'), String(shownCount));
                total += shownCount;
            });

            section.hidden = total === 0;
            resultCount += total;
        });

        // Archive (not while searching - the search lists past events itself)
        if (this.hasArchiveTarget) {
            this.archiveTarget.hidden = searching;
            const key = this.archiveKey();

            if (!searching && key !== this.renderedArchive) {
                this.renderArchive(scope, scopeName);
                this.renderedArchive = key;
            }
        }

        // Past search results
        if (this.hasSearchPastTarget) {
            this.searchPastTarget.hidden = !searching || nothing;
            const key = this.searchKey();

            if (searching && !nothing) {
                if (key !== this.renderedSearch) {
                    this.renderSearchPast(hits, inScope);
                    this.renderedSearch = key;
                }

                resultCount += Number(this.searchPastTarget.querySelector('[data-ev-search-past-count]')?.textContent || 0);
            }
        }

        if (this.hasSearchNothingTarget) {
            this.searchNothingTarget.hidden = !nothing;

            if (nothing) {
                this.setText(this.searchNothingTarget.querySelector('[data-ev-nothing-title]'), this.t('nothing', { query }));
            }
        }

        this.section('foot', (section) => {
            section.hidden = searching;
        });

        if (this.hasListViewTarget) {
            this.listViewTarget.hidden = view === 'calendar';
        }

        this.updateControls();

        if (!initial) {
            this.updateUrl();

            if (searching) {
                this.announce(this.t('searchResults', { count: resultCount }));
            }
        }

        this.scopeValue = scope;
        this.viewValue = view;
        this.queryValue = query;

        // After the calendar (a lazy controller inside this element) had the chance to connect
        if (initial) {
            setTimeout(() => this.dispatchState(), 0);
        } else {
            this.dispatchState();
        }
    }

    dispatchState() {
        this.dispatch('state', { detail: { scope: this.state.scope, query: this.state.query.trim(), view: this.state.view } });
    }

    // ---- controls: chips, view switch, search form, links ----------------------------------------------------------

    updateControls() {
        const { scope, view } = this.state;
        const query = this.state.query.trim();

        this.ensureScopeChip(scope);

        this.element.querySelectorAll('a[data-ev-scope]').forEach((link) => {
            const key = link.dataset.evScope;

            if (!key) {
                return;
            }

            link.setAttribute('aria-current', key === scope ? 'true' : 'false');
            link.setAttribute('href', this.urlFor({ scope: key, view, query }));
        });

        this.element.querySelectorAll('a[data-ev-view]').forEach((link) => {
            const key = link.dataset.evView;
            link.setAttribute('aria-current', key === view ? 'true' : 'false');
            link.setAttribute('href', this.urlFor({ scope, view: key, query }));
        });

        this.revealActiveChip();

        if (this.hasSearchClearTarget) {
            this.searchClearTarget.hidden = this.state.query === '';
        }

        if (this.hasSearchHiddenTarget) {
            const params = this.scopeParams(scope);

            if (view === 'calendar') {
                params.view = 'calendar';
            }

            this.searchHiddenTarget.replaceChildren(...Object.entries(params).map(([name, value]) => {
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = name;
                input.value = value;

                return input;
            }));
        }
    }

    // A country chosen in the sheet (or from the rail) gets a chip of its own until another scope is chosen
    ensureScopeChip(scope) {
        if (!this.hasChipsTarget) {
            return;
        }

        this.chipsTarget.querySelectorAll('.ev-chip-extra').forEach((chip) => {
            if (chip.dataset.evScope !== scope) {
                chip.remove();
            }
        });

        if (scope === 'all' || scope === 'online' || this.chipsTarget.querySelector(`[data-ev-scope="${CSS.escape(scope)}"]:not([hidden])`)) {
            return;
        }

        const source = this.element.querySelector(`[data-ev-scope="${CSS.escape(scope)}"][data-ev-name]`);

        if (!source) {
            return;
        }

        const chip = document.createElement('a');
        chip.className = 'ev-chip ev-chip-country ev-chip-extra';
        chip.dataset.evScope = scope;
        chip.dataset.evName = source.dataset.evName;
        chip.dataset.action = 'events-page#chooseScope';
        this.fillCountryChip(chip, scope, source.dataset.evName, Number(source.dataset.evUpcoming || 0));

        const online = this.chipsTarget.querySelector('[data-ev-scope="online"]');
        (online ?? this.chipsTarget.firstElementChild)?.after(chip);
    }

    // The chips scroll sideways on phones - the chosen one is never left out of sight
    revealActiveChip() {
        if (!this.hasChipsTarget) {
            return;
        }

        const row = this.chipsTarget;
        const chip = row.querySelector('[aria-current="true"]:not([hidden])');

        if (!chip || row.scrollWidth <= row.clientWidth) {
            return;
        }

        const left = chip.getBoundingClientRect().left - row.getBoundingClientRect().left + row.scrollLeft;

        if (left < row.scrollLeft || left + chip.offsetWidth > row.scrollLeft + row.clientWidth) {
            row.scrollLeft = Math.max(0, left - 16);
        }
    }

    fillCountryChip(chip, code, name, upcoming) {
        const flag = document.createElement('span');
        flag.className = `fi fi-${code}`;
        flag.setAttribute('aria-hidden', 'true');
        const parts = [flag, document.createTextNode(` ${name}`)];

        if (upcoming > 0) {
            const count = document.createElement('span');
            count.className = 'ev-chip-n';
            count.textContent = String(upcoming);
            parts.push(document.createTextNode(' '), count);
        }

        chip.replaceChildren(...parts);
    }

    // A guest's country, guessed from the browser's languages - offered as a chip, never applied
    offerGuess() {
        if (!this.hasGuessChipTarget) {
            return;
        }

        const offered = new Map();

        this.element.querySelectorAll('.ev-sheet [data-ev-scope][data-ev-name]').forEach((option) => {
            offered.set(option.dataset.evScope, option);
        });

        let guess = null;

        try {
            guess = guessCountry((code) => offered.has(code));
        } catch {
            guess = null;
        }

        if (!guess) {
            return;
        }

        const option = offered.get(guess);
        const chip = this.guessChipTarget;
        chip.dataset.evScope = guess;
        chip.dataset.evName = option.dataset.evName;
        this.fillCountryChip(chip, guess, option.dataset.evName, Number(option.dataset.evUpcoming || 0));
        chip.hidden = false;

        // The same country among the top chips would be there twice
        this.chipsTarget.querySelectorAll(`.ev-chip-country[data-ev-scope="${CSS.escape(guess)}"]`).forEach((duplicate) => {
            duplicate.hidden = true;
        });
    }

    updateUrl() {
        const url = this.urlFor({ ...this.state, query: this.state.query.trim() }) + window.location.hash;

        if (url !== window.location.pathname + window.location.search + window.location.hash) {
            history.replaceState(history.state, '', url);
        }
    }

    urlFor({ scope, view, query }) {
        const params = new URLSearchParams(this.scopeParams(scope));

        if (view === 'calendar') {
            params.set('view', 'calendar');
            const month = new URLSearchParams(window.location.search).get('month') || this.monthValue;

            if (/^\d{4}-\d{2}$/.test(month || '')) {
                params.set('month', month);
            }
        }

        if (query) {
            params.set('q', query);
        }

        const search = params.toString();

        return this.basePath() + (search === '' ? '' : `?${search}`);
    }

    scopeParams(scope) {
        if (scope === 'online') {
            return { onlineOnly: '1' };
        }

        return scope && scope !== 'all' ? { country: scope } : {};
    }

    basePath() {
        if (this.cachedBasePath === undefined) {
            const action = this.element.querySelector('form.ev-search')?.getAttribute('action');
            this.cachedBasePath = action ? new URL(action, window.location.href).pathname : window.location.pathname;
        }

        return this.cachedBasePath;
    }

    // ---- empty state -----------------------------------------------------------------------------------------------

    fillEmptyState(empty, scope, scopeName) {
        this.setText(empty.querySelector('[data-ev-empty-title]'), scope === 'all' ? this.t('noDates', { count: 0 }) : this.t('noneIn', { scope: scopeName }));

        let last = null;

        for (const entry of this.index) {
            if (entry.st === 'past' && !entry.w && entry.f && scopeMatches(entry.sc ?? '', scope) && (last === null || entry.f >= last.f)) {
                last = entry;
            }
        }

        const month = last ? this.format(last.f, { month: 'long', year: 'numeric' }) : '';
        this.setText(empty.querySelector('[data-ev-empty-last]'), last ? this.t('lastOne', { name: last.n, month }) : this.t('noneYet'));
    }

    // ---- archive ---------------------------------------------------------------------------------------------------

    archiveKey() {
        return this.state.scope === 'all'
            ? `all|${this.archiveOpen.year ?? ''}|${this.archiveOpen.all ? 1 : 0}`
            : `scope|${this.state.scope}`;
    }

    searchKey() {
        return `${this.state.scope}|${foldSearchText(this.state.query)}`;
    }

    readArchiveUrls() {
        try {
            return JSON.parse(this.hasArchiveTarget ? (this.archiveTarget.dataset.evArchiveUrls || '{}') : '{}') || {};
        } catch {
            return {};
        }
    }

    pastEntries() {
        return this.index.filter((entry) => entry.st === 'past' && !entry.w && entry.f);
    }

    pastYears() {
        return [...new Set(this.pastEntries().map((entry) => Number(entry.f.slice(0, 4))))].sort((a, b) => b - a);
    }

    // EventsPageBuilder::archiveYears() for one year: several editions of one series are one line placed at its newest
    // edition, lines newest first; each line belongs to the scope of its (newest) occurrence
    archiveLines(entries) {
        const bySeries = new Map();

        entries.forEach((entry) => {
            if (entry.k === 'd' && entry.sid !== null && entry.sid !== undefined) {
                bySeries.set(entry.sid, [...(bySeries.get(entry.sid) ?? []), entry]);
            }
        });

        const lines = [];
        const rolledUp = new Set();

        entries.forEach((entry) => {
            const editions = entry.k === 'd' ? bySeries.get(entry.sid) : null;

            if (editions && editions.length >= 2) {
                if (!rolledUp.has(entry.sid)) {
                    rolledUp.add(entry.sid);
                    const sorted = [...editions].sort((a, b) => (a.f < b.f ? -1 : a.f > b.f ? 1 : 0));
                    const newest = sorted[sorted.length - 1];
                    lines.push({ entries: sorted, newest, sort: newest.f, title: newest.n, scope: newest.sc ?? '' });
                }

                return;
            }

            lines.push({ entries: [entry], newest: entry, sort: entry.f, title: entry.n, scope: entry.sc ?? '' });
        });

        return lines.sort((a, b) => (a.sort < b.sort ? 1 : a.sort > b.sort ? -1 : 0)
            || foldSearchText(a.title).localeCompare(foldSearchText(b.title)));
    }

    renderArchive(scope, scopeName) {
        const body = this.archiveTarget.querySelector('[data-ev-archive-body]');

        if (!body) {
            return;
        }

        const byYear = new Map();

        this.pastEntries().forEach((entry) => {
            const year = Number(entry.f.slice(0, 4));
            byYear.set(year, [...(byYear.get(year) ?? []), entry]);
        });

        const years = [...byYear.keys()].sort((a, b) => b - a);
        const nodes = [];

        if (scope === 'all') {
            nodes.push(this.blockHead(this.t('archiveTitle'), 'ev-archive-title'));

            if (years.length === 0) {
                nodes.push(this.note(this.t('archiveNone')));
            } else {
                const nav = document.createElement('nav');
                nav.className = 'ev-years';
                nav.setAttribute('aria-labelledby', 'ev-archive-title');

                years.forEach((year) => {
                    const chip = document.createElement('a');
                    chip.className = 'ev-chip ev-year';
                    chip.href = this.archiveUrls[`y${year}`] ?? '#';
                    chip.dataset.evYear = String(year);
                    chip.dataset.action = 'events-page#toggleYear';
                    chip.setAttribute('aria-current', year === this.archiveOpen.year ? 'true' : 'false');
                    const count = document.createElement('span');
                    count.className = 'ev-chip-n';
                    count.textContent = String(byYear.get(year).length);
                    chip.append(document.createTextNode(`${year} `), count);
                    nav.append(chip);
                });

                nodes.push(nav);
                const open = this.archiveOpen.year;

                if (open !== null && byYear.has(open)) {
                    const lines = this.archiveLines(byYear.get(open));
                    const list = document.createElement('ul');
                    list.className = 'ev-lines ev-archive-lines';
                    list.append(...(this.archiveOpen.all ? lines : lines.slice(0, ARCHIVE_PREVIEW_LINES)).map((line) => this.lineElement(line, open)));
                    nodes.push(list);

                    if (!this.archiveOpen.all && lines.length > ARCHIVE_PREVIEW_LINES) {
                        const more = document.createElement('a');
                        more.className = 'ev-more';
                        more.href = this.archiveUrls[`y${open}`] ?? '#';
                        more.dataset.evShowAll = '';
                        more.dataset.action = 'events-page#showAllYear';
                        more.textContent = this.t('showAll', { year: open, count: byYear.get(open).length });
                        nodes.push(more);
                    }
                }
            }
        } else {
            nodes.push(this.blockHead(this.t('pastIn', { scope: scopeName }), 'ev-archive-title'));
            let shown = 0;

            years.forEach((year) => {
                const lines = this.archiveLines(byYear.get(year)).filter((line) => scopeMatches(line.scope, scope));

                if (lines.length === 0) {
                    return;
                }

                shown++;
                const group = document.createElement('section');
                group.className = 'ev-group';
                const header = document.createElement('h3');
                header.className = 'ev-month-header';
                const label = document.createElement('span');
                label.textContent = `${scopeName} · ${year}`;
                const count = document.createElement('span');
                count.className = 'ev-month-n';
                count.textContent = this.t('eventsCount', { count: lines.reduce((sum, line) => sum + line.entries.length, 0) });
                header.append(label, count);
                const list = document.createElement('ul');
                list.className = 'ev-lines';
                list.append(...lines.map((line) => this.lineElement(line, year)));
                group.append(header, list);
                nodes.push(group);
            });

            if (shown === 0) {
                nodes.push(this.note(this.t('archiveNone')));
            }
        }

        body.replaceChildren(...nodes);
    }

    renderSearchPast(hits, inScope) {
        const past = this.pastEntries()
            .filter((entry) => hits.has(entry.id) && inScope(entry.sc))
            .sort((a, b) => (a.f < b.f ? 1 : a.f > b.f ? -1 : b.id - a.id));
        const section = this.searchPastTarget;
        const list = section.querySelector('[data-ev-search-lines]');

        list?.replaceChildren(...past.slice(0, SEARCH_PAST_LINES).map((entry) => this.lineElement({
            entries: [entry], newest: entry, sort: entry.f, title: entry.n, scope: entry.sc ?? '',
        }, null, true)));

        this.setText(section.querySelector('[data-ev-search-past-count]'), String(past.length));
        const limited = section.querySelector('[data-ev-search-limited]');

        if (limited) {
            limited.hidden = past.length <= SEARCH_PAST_LINES;
            limited.textContent = this.t('pastLimited', { count: past.length, shown: SEARCH_PAST_LINES });
        }

        const none = section.querySelector('[data-ev-search-none]');

        if (none) {
            none.hidden = past.length > 0;
        }
    }

    // One archive line from the template (_archive_line.html.twig), dated like the server writes it
    lineElement(line, year, withYear = false) {
        const entry = line.newest;
        const element = fillArchiveLine(this.lineTemplate, entry, {
            locale: this.locale,
            withYear,
            resultsLabel: this.messagesValue.results ?? '',
            onlineLabel: this.messagesValue.online ?? '',
        });
        const slot = (name) => element.querySelector(`[data-slot="${name}"]`);
        const rollUp = line.entries.length > 1;
        const date = slot('date');

        if (rollUp) {
            const first = line.entries[0];
            element.classList.add('ev-line-rollup');
            element.setAttribute('data-ev-ids', line.entries.map((item) => item.id).sort((a, b) => a - b).join(' '));
            const series = this.byId.get(entry.sid);
            this.setText(slot('title'), this.t('editionsIn', { series: entry.n, count: line.entries.length, year }));
            const link = slot('link');

            if (series?.u) {
                link?.setAttribute('href', series.u);
            } else {
                link?.removeAttribute('href');
            }

            const fromMonth = this.format(first.f, { month: 'short' });
            const toMonth = this.format(entry.f, { month: 'short' });
            this.setText(date, first.f.slice(0, 7) === entry.f.slice(0, 7) ? fromMonth : `${fromMonth}–${toMonth}`);

            const results = slot('results');

            if (results) {
                const any = line.entries.some((item) => item.r);
                results.hidden = !any;
                results.textContent = any ? (this.messagesValue.results ?? '') : '';
            }
        } else {
            let text = this.dayMonth(entry.f) + (entry.t && entry.t !== entry.f ? `–${this.dayMonth(entry.t)}` : '');

            if (withYear) {
                text += ` ${entry.f.slice(0, 4)}`;
            }

            this.setText(date, text);
        }

        // The place with its flag, like the server's lines
        const place = slot('place');

        if (place && entry.sc !== 'online' && entry.c) {
            const wrap = document.createElement('span');
            wrap.className = 'ev-place';
            const flag = document.createElement('span');
            flag.className = `fi fi-${entry.c} ev-flag`;
            flag.setAttribute('aria-hidden', 'true');
            const label = document.createElement('span');
            label.className = 'ev-place-city';
            label.textContent = entry.p ?? '';
            wrap.append(flag, label);
            place.replaceChildren(wrap);
        }

        if (place) {
            place.removeAttribute('data-slot');
        }

        return element;
    }

    // ---- finding rows for a calendar day ---------------------------------------------------------------------------

    findForDay(ids) {
        const elements = [];
        const seen = new Set();
        const foundIds = new Set();

        ids.forEach((id) => {
            const selector = [
                `.ev-agenda .ev-row[data-ev-ids~="${id}"]`,
                `.ev-archive .ev-line[data-ev-ids~="${id}"]`,
            ].join(', ');

            this.element.querySelectorAll(selector).forEach((element) => {
                if (element.hidden || element.closest('[hidden]')) {
                    return;
                }

                foundIds.add(id);

                // In a month roll-up the session's own chip lights up, not the whole row
                const chip = element.querySelector(`.ev-session[data-ev-ids~="${id}"]`);
                const target = chip ?? element;

                if (!seen.has(target)) {
                    seen.add(target);
                    elements.push(target);
                }
            });
        });

        return { elements, missing: foundIds.size < ids.length };
    }

    flash(element) {
        element.classList.remove('ev-flash');
        void element.offsetWidth;
        element.classList.add('ev-flash');
        // A timer, not animationend - with reduced motion there is no animation, only an outline for a moment
        setTimeout(() => element.classList.remove('ev-flash'), 1600);
    }

    // ---- sticky toolbar: slides away on phones while scrolling down, back on the first scroll up --------------------

    startToolbar() {
        if (!this.hasToolbarTarget) {
            return;
        }

        this.lastScrollY = window.scrollY;
        this.phone = window.matchMedia(PHONE_QUERY);
        this.measureToolbar();

        if (typeof ResizeObserver !== 'undefined') {
            this.toolbarObserver = new ResizeObserver(() => this.measureToolbar());
            this.toolbarObserver.observe(this.toolbarTarget);
        }

        this.onScroll = () => {
            if (this.scrollFrame) {
                return;
            }

            this.scrollFrame = requestAnimationFrame(() => {
                this.scrollFrame = null;
                this.followScroll();
            });
        };
        this.onToolbarFocus = () => this.toolbarAway(false);

        window.addEventListener('scroll', this.onScroll, { passive: true });
        this.toolbarTarget.addEventListener('focusin', this.onToolbarFocus);
    }

    stopToolbar() {
        window.removeEventListener('scroll', this.onScroll);
        this.toolbarTarget?.removeEventListener('focusin', this.onToolbarFocus);
        this.toolbarObserver?.disconnect();
        cancelAnimationFrame(this.scrollFrame);
        this.scrollFrame = null;
        clearTimeout(this.awayTimer);
    }

    followScroll() {
        const toolbar = this.toolbarTarget;
        const y = window.scrollY;
        const headerHeight = this.headerHeight();

        toolbar.classList.toggle('is-stuck', y > 0 && toolbar.getBoundingClientRect().top <= headerHeight + 1);

        const sheetOpen = this.hasSheetTarget && this.sheetTarget.classList.contains('is-open');
        const searching = document.activeElement === (this.hasSearchTarget ? this.searchTarget : null);

        if (!this.phone.matches || sheetOpen || searching) {
            if (!this.phone.matches) {
                this.toolbarAway(false);
            }
        } else if (y > this.lastScrollY + SCROLL_HYSTERESIS && y > SLIDE_AWAY_AFTER) {
            this.toolbarAway(true);
        } else if (y < this.lastScrollY - SCROLL_HYSTERESIS) {
            this.toolbarAway(false);
        }

        this.lastScrollY = y;
    }

    toolbarAway(away) {
        if (!this.hasToolbarTarget || this.toolbarTarget.classList.contains('is-away') === away) {
            return;
        }

        clearTimeout(this.awayTimer);
        this.toolbarTarget.classList.toggle('is-away', away);

        if (away) {
            // Month headers move up only once the bar has finished sliding, never into a bar still visible
            this.awayTimer = setTimeout(() => {
                if (this.toolbarTarget.classList.contains('is-away')) {
                    this.element.style.setProperty('--ev-toolbar-height', '0px');
                }
            }, this.reducedMotion() ? 0 : SLIDE_MS);
        } else {
            this.measureToolbar();
        }
    }

    measureToolbar() {
        if (!this.hasToolbarTarget || this.toolbarTarget.classList.contains('is-away')) {
            return;
        }

        this.element.style.setProperty('--ev-toolbar-height', `${Math.round(this.toolbarTarget.getBoundingClientRect().height)}px`);
    }

    scrollToList() {
        const body = this.element.querySelector('.ev-body');

        if (!body) {
            return;
        }

        const toolbarHeight = this.hasToolbarTarget ? this.toolbarTarget.getBoundingClientRect().height : 0;
        const top = body.getBoundingClientRect().top + window.scrollY - this.headerHeight() - toolbarHeight - 8;

        if (window.scrollY > top) {
            window.scrollTo({ top: Math.max(0, top), behavior: 'auto' });
        }
    }

    // ---- helpers ---------------------------------------------------------------------------------------------------

    t(name, params = {}) {
        const message = this.messagesValue?.t?.[name];

        if (!message || typeof message.message !== 'string') {
            return '';
        }

        let text = typeof params.count === 'number'
            ? (chooseTranslation(message.message, params.count, message.locale || this.locale) ?? message.message)
            : message.message;

        Object.entries(params).forEach(([key, value]) => {
            text = text.replaceAll(`%${key}%`, String(value));
        });

        return text;
    }

    scopeName(scope) {
        if (!scope || scope === 'all') {
            return '';
        }

        if (scope === 'online') {
            return this.t('scopeOnline');
        }

        const source = this.element.querySelector(`[data-ev-scope="${CSS.escape(scope)}"][data-ev-name]`);

        return source?.dataset.evName || scope.toUpperCase();
    }

    rowIds(element) {
        return (element.dataset.evIds || '').split(' ').filter((id) => id !== '').map(Number);
    }

    section(name, callback) {
        const section = this.element.querySelector(`[data-ev-section="${name}"]`);

        if (section) {
            callback(section);
        }
    }

    setText(element, text) {
        if (element && element.textContent !== text) {
            element.textContent = text;
        }
    }

    blockHead(text, id) {
        const head = document.createElement('div');
        head.className = 'ev-block-head';
        const title = document.createElement('h2');
        title.className = 'ev-block-title';
        title.id = id;
        title.textContent = text;
        head.append(title);

        return head;
    }

    note(text) {
        const paragraph = document.createElement('p');
        paragraph.className = 'ev-note';
        paragraph.textContent = text;

        return paragraph;
    }

    format(isoDay, options) {
        try {
            return new Intl.DateTimeFormat(this.locale, { ...options, timeZone: 'UTC' }).format(new Date(`${isoDay}T00:00:00Z`));
        } catch {
            return isoDay;
        }
    }

    // ICU `d LLL`, as the server writes the archive lines ("3 Mar", "3 3月")
    dayMonth(isoDay) {
        const date = new Date(`${isoDay}T00:00:00Z`);

        try {
            const day = new Intl.DateTimeFormat(this.locale, { day: 'numeric', timeZone: 'UTC' })
                .formatToParts(date).find((part) => part.type === 'day')?.value ?? String(date.getUTCDate());

            return `${day} ${new Intl.DateTimeFormat(this.locale, { month: 'short', timeZone: 'UTC' }).format(date)}`;
        } catch {
            return isoDay;
        }
    }

    headerHeight() {
        return parseFloat(getComputedStyle(document.documentElement).getPropertyValue('--header-height')) || 0;
    }

    announce(text) {
        if (!this.hasAnnouncerTarget) {
            return;
        }

        clearTimeout(this.announceTimer);
        this.announceTimer = setTimeout(() => {
            this.announcerTarget.textContent = text;
        }, ANNOUNCE_DELAY);
    }

    track(name, value) {
        try {
            if (typeof window.gtag === 'function') {
                window.gtag('event', name, { value });
            }
        } catch {
            // Analytics never break the page
        }
    }

    lockScroll() {
        document.documentElement.classList.add('ev-sheet-open');
    }

    unlockScroll() {
        document.documentElement.classList.remove('ev-sheet-open');
    }

    focusQuietly(element) {
        element?.focus?.({ preventScroll: true });
    }

    isModifiedClick(event) {
        return event.type === 'click' && (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey || event.button > 0);
    }

    reducedMotion() {
        return window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    }
}
