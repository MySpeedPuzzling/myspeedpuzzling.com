/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus';
import { chooseTranslation } from '../translation_choice.js';
import { SheetModel } from '../participants_sheet/sheet_model.js';
import { isEmpty } from '../participants_sheet/sheet_changes.js';
import { SheetSaveQueue } from '../participants_sheet/sheet_save_queue.js';
import { SheetUndo } from '../participants_sheet/sheet_undo.js';
import { SheetLive } from '../participants_sheet/sheet_live.js';
import { SheetGrid, escapeHtml } from '../participants_sheet/sheet_grid.js';
import { PreviewDialog } from '../participants_sheet/preview_dialog.js';
import createPeopleView from '../participants_sheet/views/people_view.js';

const PHONE_QUERY = '(max-width: 767.98px)';
const WARNING_MARK_MS = 20000;

/**
 * Which view module shows a tab - `assets/participants_sheet/views/<name>.js`, loaded when first needed (the round
 * views of stream D, the phone people list and the person editor of stream E). A module that is not there yet falls
 * back: the phone People list to the People grid, round tabs to a summary placeholder.
 */
export const VIEW_MODULES = {
    people: { desktop: 'people_view', phone: 'people_list_view' },
    solo: { desktop: 'solo_round_view', phone: 'round_cards_view' },
    duo: { desktop: 'team_round_view', phone: 'round_cards_view' },
    team: { desktop: 'team_round_view', phone: 'round_cards_view' },
};
export const PERSON_EDITOR_MODULE = 'person_editor';

/**
 * Texts of one JSON object (a `_texts_*.html.twig` partial): plain strings (`|trans`, with %placeholders%) and plural
 * messages from browser_translation() (`{message, locale}`), chosen like PHP does (translation_choice.js).
 */
export function makeTexts(source) {
    const texts = source && typeof source === 'object' ? source : {};
    const fill = (text, params) => Object.entries(params ?? {}).reduce((result, [name, value]) => result.replaceAll(`%${name}%`, String(value ?? '')), text);

    return {
        has: (key) => key in texts,
        t(key, params = {}) {
            const message = texts[key];

            if (typeof message === 'string') {
                return fill(message, params);
            }

            if (message && typeof message === 'object' && typeof message.message === 'string') {
                return fill(chooseTranslation(message.message, 1, message.locale) ?? message.message, params);
            }

            return key;
        },
        tc(key, count, params = {}) {
            const message = texts[key];
            let text = null;

            if (message && typeof message === 'object' && typeof message.message === 'string') {
                text = chooseTranslation(message.message, count, message.locale);
            } else if (typeof message === 'string') {
                text = message;
            }

            return fill(text ?? key, { count, ...params });
        },
    };
}

/**
 * The participants spreadsheet page (docs/features/competitions-management/participants-spreadsheet.md, "Client
 * architecture (as built)"): reads the state embedded in the page, keeps the model, the save queue, undo and the live
 * updates, renders the tabs (People + every round, with counts and problem badges), the save status, undo/redo, the
 * problems panel, a polite live region and the keyboard help, and mounts the view of the tab - a grid on desktops, lists
 * on phones (< 768 px). Views are plugged in by name (VIEW_MODULES); every edit goes through act().
 */
export default class extends Controller {
    static targets = ['tabs', 'status', 'undo', 'redo', 'main', 'help'];

    static values = {
        urls: Object,
        csrfToken: String,
        locale: String,
        countries: Object,
        textsCore: Object,
        textsRound: Object,
        textsPeople: Object,
        tab: String,
    };

    connect() {
        this.cleanups = [];
        this.texts = {
            core: makeTexts(this.textsCoreValue),
            round: makeTexts(this.textsRoundValue),
            people: makeTexts(this.textsPeopleValue),
        };
        this.countryCodes = new Set(Object.keys(this.countriesValue ?? {}));
        this.modules = new Map([['people_view', createPeopleView]]);
        this.view = null;
        this.viewName = null;
        this.mountToken = 0;
        this.warningTimers = new Map();
        this.problemsOpen = true;

        const state = this.readState();

        if (state === null) {
            this.mainTarget.textContent = this.texts.core.t('state_unreadable');

            return;
        }

        this.model = new SheetModel(state);
        this.queue = new SheetSaveQueue({
            model: this.model,
            urls: this.urlsValue,
            csrfToken: this.csrfTokenValue,
            texts: { genericError: this.texts.core.t('error_generic') },
        });
        this.undoStack = new SheetUndo();
        this.live = new SheetLive({
            model: this.model,
            queue: this.queue,
            urls: this.urlsValue,
            isVisible: () => document.visibilityState === 'visible',
            onMessage: (data) => this.onLiveMessage(data),
        });

        this.buildChrome();
        this.cleanups.push(this.model.subscribe((delta) => this.onModelChange(delta)));
        this.cleanups.push(this.queue.subscribe((event) => this.onQueueEvent(event)));
        this.cleanups.push(this.queue.installLeaveGuards({
            window,
            document,
            confirm: (message) => window.confirm(message),
            message: this.texts.core.t('leave_unsaved'),
        }));

        this.listen(document, 'visibilitychange', () => this.live.visibilityChanged());
        this.listen(window, 'online', () => this.live.online());
        this.listen(this.element, 'keydown', (event) => this.onKeyDown(event));
        this.media = window.matchMedia(PHONE_QUERY);
        this.phone = this.media.matches;
        this.listen(this.media, 'change', () => this.onBreakpoint());

        this.wireButton(this.hasUndoTarget ? this.undoTarget : null, 'undo', () => this.undo());
        this.wireButton(this.hasRedoTarget ? this.redoTarget : null, 'redo', () => this.redo());
        this.wireButton(this.hasHelpTarget ? this.helpTarget : null, 'showHelp', () => this.showHelp());

        this.currentTab = this.knownTab(this.tabValue || new URL(window.location.href).searchParams.get('tab') || 'people');
        this.renderTabs();
        this.renderStatus();
        this.renderUndo();
        this.showTab(this.currentTab, { replaceUrl: false });
        this.live.start();
    }

    disconnect() {
        this.mountToken++;
        this.view?.destroy();
        this.view = null;
        this.personEditor?.destroy?.();
        this.live?.close();
        this.queue?.destroy();
        this.cleanups?.forEach((cleanup) => cleanup());
        this.cleanups = [];
        this.warningTimers?.forEach((timer) => clearTimeout(timer));
        this.helpDialog?.close();
        this.helpDialog?.remove();
        this.chrome?.remove();
        cancelAnimationFrame(this.tabsFrame);
    }

    listen(target, type, handler, options) {
        target.addEventListener(type, handler, options);
        this.cleanups.push(() => target.removeEventListener(type, handler, options));
    }

    /** The page's buttons (B's markup): wired here unless the markup already calls the action itself. */
    wireButton(element, action, handler) {
        if (element === null || (element.dataset.action ?? '').includes(`participants-sheet#${action}`)) {
            return;
        }

        this.listen(element, 'click', (event) => {
            event.preventDefault();
            handler();
        });
    }

    readState() {
        const script = this.element.querySelector('#participants-sheet-state') ?? document.getElementById('participants-sheet-state');

        try {
            return script ? JSON.parse(script.textContent) : null;
        } catch (e) {
            return null;
        }
    }

    // ---------------------------------------------------------------- chrome: live region, banner, problems, view root

    buildChrome() {
        const core = this.texts.core;
        this.liveRegion = document.createElement('div');
        this.liveRegion.className = 'visually-hidden';
        this.liveRegion.setAttribute('aria-live', 'polite');
        this.liveRegion.setAttribute('aria-atomic', 'true');

        this.banner = document.createElement('div');
        this.banner.className = 'sheet-banner';
        this.banner.hidden = true;

        this.problemsPanel = document.createElement('section');
        this.problemsPanel.className = 'sheet-problems';
        this.problemsPanel.hidden = true;
        this.problemsPanel.setAttribute('aria-label', core.t('problems_title'));

        this.viewRoot = document.createElement('div');
        this.viewRoot.className = 'sheet-view-root';
        this.viewRoot.id = `${this.element.id || 'participants-sheet'}-panel`;
        this.viewRoot.setAttribute('role', 'tabpanel');

        this.chrome = document.createElement('div');
        this.chrome.className = 'sheet-main';
        this.chrome.append(this.banner, this.problemsPanel, this.viewRoot);
        this.mainTarget.replaceChildren(this.chrome);
        this.element.append(this.liveRegion);
        this.cleanups.push(() => this.liveRegion.remove());

        this.banner.addEventListener('click', (event) => {
            if (event.target.closest('[data-sheet-retry]')) {
                this.queue.retryNow();
                this.queue.refetch();
            }
        });
        this.problemsPanel.addEventListener('click', (event) => this.onProblemClick(event));
    }

    announce(text) {
        if (!text || !this.liveRegion) {
            return;
        }

        // Cleared first: the same text twice is announced twice
        this.liveRegion.textContent = '';
        clearTimeout(this.announceTimer);
        this.announceTimer = setTimeout(() => {
            this.liveRegion.textContent = text;
        }, 60);
    }

    // ---------------------------------------------------------------- tabs

    knownTab(tab) {
        return tab === 'people' || this.model.round(tab) !== null ? tab : 'people';
    }

    tabs() {
        const core = this.texts.core;
        const list = [{ id: 'people', label: core.t('tab_people'), count: this.model.people().length, problems: 0 }];

        for (const round of this.model.rounds()) {
            const solo = round.category === 'solo';
            list.push({
                id: round.id,
                label: round.name,
                count: solo ? this.model.peopleIn(round.id).length : this.model.teamsOf(round.id).length,
                countLabel: core.tc(solo ? 'tab_count_people' : (round.category === 'duo' ? 'tab_count_pairs' : 'tab_count_teams'), solo ? this.model.peopleIn(round.id).length : this.model.teamsOf(round.id).length),
                problems: this.model.problems(round.id).total,
                round,
            });
        }

        return list;
    }

    renderTabs() {
        if (!this.hasTabsTarget) {
            return;
        }

        const core = this.texts.core;
        const html = this.tabs().map((tab) => {
            const selected = tab.id === this.currentTab;
            const count = `<span class="sheet-tab-count">${tab.count}</span><span class="visually-hidden"> (${escapeHtml(tab.countLabel ?? core.tc('tab_count_people', tab.count))})</span>`;
            const problems = tab.problems > 0
                ? ` <span class="sheet-tab-problems"><i class="bi bi-exclamation-triangle" aria-hidden="true"></i>${tab.problems}<span class="visually-hidden"> ${escapeHtml(core.tc('tab_problems', tab.problems))}</span></span>`
                : '';
            const swatch = tab.round ? `<span class="sheet-round-swatch" style="background-color:${escapeHtml(/^#[0-9a-f]{3,8}$/i.test(tab.round.color ?? '') ? tab.round.color : 'transparent')}" aria-hidden="true"></span>` : '';

            return `<button type="button" role="tab" class="sheet-tab${selected ? ' active' : ''}" id="sheet-tab-${escapeHtml(tab.id)}" data-tab="${escapeHtml(tab.id)}" aria-selected="${selected ? 'true' : 'false'}" aria-controls="${escapeHtml(this.viewRoot?.id ?? '')}" tabindex="${selected ? '0' : '-1'}">${swatch}<span class="sheet-tab-label">${escapeHtml(tab.label)}</span> ${count}${problems}</button>`;
        }).join('');

        if (this.tabsList === undefined || !this.tabsTarget.contains(this.tabsList)) {
            this.tabsList = document.createElement('div');
            this.tabsList.className = 'sheet-tabs';
            this.tabsList.setAttribute('role', 'tablist');
            this.tabsList.setAttribute('aria-label', core.t('tabs_label'));
            this.tabsTarget.replaceChildren(this.tabsList);
            this.tabsList.addEventListener('click', (event) => {
                const button = event.target.closest('[data-tab]');

                if (button) {
                    this.showTab(button.dataset.tab);
                }
            });
            this.tabsList.addEventListener('keydown', (event) => this.onTabsKeyDown(event));
        }

        if (this.tabsList.dataset.html !== html) {
            const focused = this.tabsList.contains(document.activeElement) ? document.activeElement.dataset.tab : null;
            this.tabsList.innerHTML = html;
            this.tabsList.dataset.html = html;

            if (focused) {
                this.tabsList.querySelector(`[data-tab="${CSS.escape(focused)}"]`)?.focus();
            }
        }

        this.viewRoot?.setAttribute('aria-labelledby', `sheet-tab-${this.currentTab}`);
    }

    scheduleTabs() {
        cancelAnimationFrame(this.tabsFrame);
        this.tabsFrame = requestAnimationFrame(() => this.renderTabs());
    }

    /** APG tabs: arrows, Home/End move between tabs (and open them - switching is cheap). */
    onTabsKeyDown(event) {
        const buttons = [...this.tabsList.querySelectorAll('[role="tab"]')];
        const index = buttons.indexOf(event.target.closest('[role="tab"]'));

        if (index === -1) {
            return;
        }

        const next = { ArrowRight: index + 1, ArrowLeft: index - 1, Home: 0, End: buttons.length - 1 }[event.key];

        if (next === undefined) {
            return;
        }

        event.preventDefault();
        const target = buttons[(next + buttons.length) % buttons.length];
        target.focus();
        this.showTab(target.dataset.tab);
    }

    // ---------------------------------------------------------------- views

    descriptorOf(tab) {
        if (tab === 'people') {
            return { kind: 'people', round: null, modules: VIEW_MODULES.people };
        }

        const round = this.model.round(tab);

        return { kind: 'round', round, modules: VIEW_MODULES[round?.category] ?? VIEW_MODULES.team };
    }

    async loadModule(name) {
        if (!this.modules.has(name)) {
            let factory = null;

            try {
                const module = await import(`../participants_sheet/views/${name}.js`);
                factory = typeof module.default === 'function' ? module.default : null;
            } catch (e) {
                // Not built yet (a later stream) - the tab falls back
                factory = null;
            }

            this.modules.set(name, factory);
        }

        return this.modules.get(name);
    }

    /**
     * Shows a tab: `?tab=` kept in the URL (replaceState), the view loaded and mounted. `focus` = what the view focuses
     * ({personId, teamId, col}), `reveal` = a problem to jump to.
     */
    async showTab(tab, { focus = null, reveal = null, replaceUrl = true } = {}) {
        const id = this.knownTab(tab);
        const token = ++this.mountToken;
        const changed = id !== this.currentTab || this.view === null;
        this.currentTab = id;
        this.renderTabs();

        if (replaceUrl) {
            const url = new URL(window.location.href);

            if (id === 'people') {
                url.searchParams.delete('tab');
            } else {
                url.searchParams.set('tab', id);
            }

            window.history.replaceState(window.history.state, '', url.toString());
        }

        const descriptor = this.descriptorOf(id);
        const name = this.phone ? descriptor.modules.phone : descriptor.modules.desktop;
        let factory = await this.loadModule(name);
        let used = name;

        if (factory === null && this.phone) {
            factory = await this.loadModule(descriptor.modules.desktop);
            used = descriptor.modules.desktop;
        }

        if (token !== this.mountToken || this.model === undefined) {
            return;
        }

        if (changed || used !== this.viewName) {
            this.view?.destroy();
            this.viewRoot.replaceChildren();
            this.viewRoot.className = 'sheet-view-root';
            this.view = factory !== null ? factory(this.viewContext(descriptor)) : this.placeholderView(descriptor);
            this.viewName = factory !== null ? used : null;
            this.view.render();
        }

        // A tab activated by the organiser keeps the focus on the tab (APG tabs); a jump from a cell lands on a cell
        if (reveal !== null) {
            this.view.reveal?.(reveal);
        } else if (focus !== null) {
            this.view.focus?.(focus);
        }
    }

    viewContext(descriptor) {
        return {
            root: this.viewRoot,
            kind: descriptor.kind,
            round: descriptor.round,
            phone: this.phone,
            model: this.model,
            queue: this.queue,
            undo: this.undoStack,
            texts: this.texts,
            countries: this.countriesValue ?? {},
            countryCodes: this.countryCodes,
            locale: this.localeValue,
            urls: this.urlsValue,
            csrfToken: this.csrfTokenValue,
            act: (action, options) => this.act(action, options),
            announce: (text) => this.announce(text),
            switchTab: (tab, focus = null) => this.showTab(tab, { focus }),
            openPersonEditor: (personId, options) => this.openPersonEditor(personId, options),
            createGrid: (options) => new SheetGrid({
                texts: this.texts.core,
                announce: (text) => this.announce(text),
                undo: () => this.undo(),
                redo: () => this.redo(),
                ...options,
            }),
            preview: (options) => new PreviewDialog({ host: this.element, texts: this.texts.core, ...options }).open(),
            reasonText: (code) => this.reasonText(code),
            markerFor: (key) => this.markerFor(key),
        };
    }

    /** A round tab whose view module is not there yet: what the round holds, in words. */
    placeholderView(descriptor) {
        const root = this.viewRoot;
        const core = this.texts.core;
        const model = this.model;

        const draw = () => {
            const round = descriptor.round;

            if (round === null) {
                return;
            }

            const problems = model.problems(round.id);
            const solo = round.category === 'solo';
            const lines = [
                solo ? core.tc('tab_count_people', model.peopleIn(round.id).length) : core.tc(round.category === 'duo' ? 'tab_count_pairs' : 'tab_count_teams', model.teamsOf(round.id).length),
            ];

            if (!solo) {
                lines.push(core.tc('round_without_team', problems.withoutTeam));
            }

            root.innerHTML = `<div class="sheet-placeholder-view"><h2 class="h5">${escapeHtml(round.name)}</h2><p>${escapeHtml(lines.join(' · '))}</p><p class="text-body-secondary small">${escapeHtml(core.t('round_view_unavailable'))}</p></div>`;
        };

        return {
            render: draw,
            update: draw,
            focus: () => {},
            reveal: () => false,
            destroy: () => root.replaceChildren(),
        };
    }

    onBreakpoint() {
        const phone = this.media.matches;

        if (phone !== this.phone) {
            this.phone = phone;
            this.viewName = '__changed';
            this.showTab(this.currentTab, { replaceUrl: false });
        }
    }

    /**
     * Opens the person editor (stream E) - resolves to false when there is none (the caller falls back).
     * `options` (handed to the editor as they are): `list` = a function giving the ids previous/next walk through (the
     * view's filtered, sorted rows), `returnFocus(personId)` = where the focus goes when the editor closes.
     */
    async openPersonEditor(personId, options = {}) {
        const factory = await this.loadModule(PERSON_EDITOR_MODULE);

        if (factory === null || this.model === undefined) {
            return false;
        }

        if (!this.personEditor) {
            this.personEditor = factory(this.viewContext({ kind: 'person', round: null }));
        }

        this.personEditor.open?.(personId, options);

        return true;
    }

    // ---------------------------------------------------------------- acting

    /**
     * An organiser's action (sheet_changes.js): shown at once, queued, one undo step. Client refusals are announced;
     * results changes (`action.results`, RecordRoundResults) go to their round's pending cells.
     *
     * @param {object} action
     * @param {{origin?: string, quiet?: boolean}} [options] origin = the tab it was made on (the problems panel jumps
     *        there); quiet = the caller shows a refusal itself
     * @returns {{performed: boolean, errors: Array}}
     */
    act(action, { origin = this.currentTab, quiet = false } = {}) {
        const errors = action.errors ?? [];

        // `quiet`: the caller shows the refusal itself (an editor's error, read out by its role=alert)
        if (errors.length > 0 && !quiet) {
            this.announce(this.reasonText(errors[0].reason));
        }

        if (isEmpty(action)) {
            return { performed: false, errors };
        }

        for (const group of action.groups) {
            group.origin = origin;
            this.model.applyLocal(group.id, group.changes);
        }

        this.queue.enqueueGroups(action.groups);

        const rounds = new Set();

        for (const change of action.results ?? []) {
            this.queue.results(change.roundId).set(change.ref, change.field, change.to, change.from);
            rounds.add(change.roundId);
        }

        rounds.forEach((roundId) => this.queue.enqueueResults(roundId));

        if (action.kind === 'undo' || action.kind === 'redo') {
            this.undoStack.done(action);
        } else {
            this.undoStack.record(action);
        }

        this.renderUndo();

        return { performed: true, errors };
    }

    undo() {
        const action = this.undoStack.undo(this.model);

        if (action === null) {
            this.announce(this.texts.core.t('undo_nothing'));
            this.renderUndo();

            return;
        }

        this.act(action);
        this.announce(this.texts.core.t('undo_done', { action: this.labelOf(action.label) }));
    }

    redo() {
        const action = this.undoStack.redo(this.model);

        if (action === null) {
            this.announce(this.texts.core.t('redo_nothing'));
            this.renderUndo();

            return;
        }

        this.act(action);
        this.announce(this.texts.core.t('redo_done', { action: this.labelOf(action.label) }));
    }

    labelOf(label) {
        const key = `action_${label?.key ?? 'edit'}`;

        return this.texts.core.has(key) ? this.texts.core.t(key) : this.texts.core.t('action_edit');
    }

    renderUndo() {
        const core = this.texts.core;

        for (const [target, has, step, key] of [
            [this.hasUndoTarget ? this.undoTarget : null, this.undoStack.canUndo(), this.undoStack.peekUndo(), 'undo_label'],
            [this.hasRedoTarget ? this.redoTarget : null, this.undoStack.canRedo(), this.undoStack.peekRedo(), 'redo_label'],
        ]) {
            if (target === null) {
                continue;
            }

            target.disabled = !has;
            const label = has ? core.t(key, { action: this.labelOf(step?.label) }) : core.t(`${key}_none`);
            target.setAttribute('title', label);
            target.setAttribute('aria-label', label);
        }
    }

    onKeyDown(event) {
        if (event.defaultPrevented) {
            return;
        }

        const typing = event.target.closest?.('input, textarea, select, [contenteditable="true"]') !== null && !event.target.matches?.('input.sheet-check');
        const mod = event.ctrlKey || event.metaKey;
        const letter = /^[a-z]$/i.test(event.key) ? event.key.toLowerCase() : (/^Key([A-Z])$/.exec(event.code ?? '')?.[1]?.toLowerCase() ?? null);

        if (!typing && mod && !event.altKey && (letter === 'z' || letter === 'y')) {
            event.preventDefault();

            if (letter === 'y' || event.shiftKey) {
                this.redo();
            } else {
                this.undo();
            }

            return;
        }

        // "?" opens the help - not inside a grid, where it is a character to type
        if (!typing && !mod && event.key === '?' && event.target.closest?.('.sheet-grid') === null) {
            event.preventDefault();
            this.showHelp();
        }
    }

    // ---------------------------------------------------------------- model and queue events

    onModelChange(delta) {
        this.view?.update(delta);
        this.personEditor?.update?.(delta);

        if (delta.rows || delta.rounds.size > 0 || delta.teams.size > 0 || delta.all) {
            this.scheduleTabs();
        }
    }

    onQueueEvent(event) {
        switch (event.type) {
            case 'status':
                this.renderStatus(event.status);
                break;
            case 'outcome': {
                const refused = this.undoStack.outcome(event.group.id, event.outcome.status);

                if (refused !== null) {
                    this.announce(this.texts.core.t(refused === 'undo' ? 'undo_refused' : 'redo_refused'));
                }

                this.view?.onOutcome?.(event);
                this.renderUndo();
                break;
            }
            case 'warnings':
                this.showWarnings(event.warnings);
                break;
            case 'problems':
                this.renderProblems();
                break;
            case 'state':
                if (event.kind === 'ok') {
                    this.scheduleTabs();
                }
                break;
            case 'gone':
                this.renderStatus();
                break;
            default:
        }
    }

    onLiveMessage(data) {
        if (data.type === 'participants_sheet.changed' && data.version !== this.model.version) {
            this.announce(this.texts.core.t('live_changed_elsewhere'));
        }
    }

    /** Server warnings (never refusals): announced, and marked on the person's / team's cell for a while. */
    showWarnings(warnings) {
        const messages = [...new Set(warnings.map((warning) => warning.message).filter(Boolean))];

        if (messages.length > 0) {
            this.announce(messages.slice(0, 3).join(' '));
        }

        for (const warning of warnings) {
            let key = null;
            const entities = {};

            if (warning.participantId) {
                key = `person:${warning.participantId}:name`;
                entities.people = [warning.participantId];
            } else if (warning.teamId) {
                key = `team:${warning.teamId}:name`;
                entities.teams = [warning.teamId];
            }

            if (key === null || this.model.marks.get(key)?.state === 'conflict' || this.model.marks.get(key)?.state === 'refused') {
                continue;
            }

            this.model.marks.set(key, { state: 'warning', message: warning.message ?? null }, entities);
            clearTimeout(this.warningTimers.get(key));
            this.warningTimers.set(key, setTimeout(() => {
                if (this.model.marks.get(key)?.state === 'warning') {
                    this.model.marks.set(key, null);
                }
            }, WARNING_MARK_MS));
        }
    }

    markerFor(key) {
        const mark = this.model.marks.get(key);

        if (mark === null) {
            return null;
        }

        const text = this.texts.core.t(`marker_${mark.state}`);

        return { state: mark.state, text, title: mark.message ? `${text}: ${mark.message}` : text };
    }

    reasonText(code) {
        const key = `reason_${code}`;

        return this.texts.core.has(key) ? this.texts.core.t(key) : this.texts.core.t('reason_invalid_change');
    }

    // ---------------------------------------------------------------- status pill and banner

    renderStatus(status = this.queue.status()) {
        const core = this.texts.core;
        const states = {
            saved: { icon: 'bi-check2-circle', text: core.t('status_saved') },
            saving: { icon: 'bi-arrow-repeat', text: core.t('status_saving') },
            waiting: { icon: 'bi-hourglass-split', text: core.tc('status_waiting', status.waiting) },
            offline: { icon: 'bi-wifi-off', text: core.tc('status_offline', status.waiting) },
            attention: { icon: 'bi-exclamation-triangle', text: core.tc('status_attention', status.attention) },
            auth: { icon: 'bi-person-lock', text: core.t('status_sign_in') },
            forbidden: { icon: 'bi-shield-lock', text: core.t('status_reload') },
            gone: { icon: 'bi-x-octagon', text: core.t('status_reload') },
        };
        const shown = states[status.state] ?? states.saved;

        if (this.hasStatusTarget) {
            if (!this.statusButton || !this.statusTarget.contains(this.statusButton)) {
                this.statusButton = document.createElement('button');
                this.statusButton.type = 'button';
                this.statusTarget.replaceChildren(this.statusButton);
                this.statusButton.addEventListener('click', () => this.onStatusClick());
            }

            this.statusButton.className = `sheet-status sheet-status-${status.state}`;
            this.statusButton.innerHTML = `<i class="bi ${shown.icon}" aria-hidden="true"></i> <span>${escapeHtml(shown.text)}</span>`;
            this.statusButton.title = core.t(`status_hint_${status.state}`);
        }

        if (this.lastStatusState !== status.state) {
            const previous = this.lastStatusState;
            this.lastStatusState = status.state;

            // Announced when something needs the organiser - and when it is fine again after that
            if (['offline', 'waiting', 'attention', 'auth', 'forbidden', 'gone'].includes(status.state) || (status.state === 'saved' && ['offline', 'waiting', 'auth'].includes(previous))) {
                this.announce(shown.text);
            }
        }

        this.renderBanner(status);
    }

    renderBanner(status) {
        if (!this.banner) {
            return;
        }

        const core = this.texts.core;
        const loginUrl = this.urlsValue.login ?? core.t('login_url');
        let html = '';

        if (status.state === 'auth') {
            html = `<div class="alert alert-danger d-flex flex-wrap align-items-center gap-2 mb-2" role="alert"><span class="me-auto"><i class="bi bi-person-lock me-1" aria-hidden="true"></i>${escapeHtml(core.t('banner_auth'))}</span><a class="btn btn-sm btn-danger" href="${escapeHtml(loginUrl)}" target="_blank" rel="noopener">${escapeHtml(core.t('banner_sign_in'))}</a><button type="button" class="btn btn-sm btn-outline-danger" data-sheet-retry>${escapeHtml(core.t('banner_try_again'))}</button></div>`;
        } else if (status.state === 'forbidden') {
            html = `<div class="alert alert-danger mb-2" role="alert"><i class="bi bi-shield-lock me-1" aria-hidden="true"></i>${escapeHtml(core.t('banner_forbidden'))}</div>`;
        } else if (status.state === 'gone') {
            html = `<div class="alert alert-danger mb-2" role="alert"><i class="bi bi-x-octagon me-1" aria-hidden="true"></i>${escapeHtml(core.t('banner_gone'))}</div>`;
        } else if (status.state === 'offline') {
            html = `<div class="alert alert-warning d-flex flex-wrap align-items-center gap-2 mb-2" role="status"><span class="me-auto"><i class="bi bi-wifi-off me-1" aria-hidden="true"></i>${escapeHtml(core.t('banner_offline'))}</span><button type="button" class="btn btn-sm btn-outline-secondary" data-sheet-retry>${escapeHtml(core.t('banner_try_now'))}</button></div>`;
        }

        if (this.banner.dataset.html !== html) {
            this.banner.innerHTML = html;
            this.banner.dataset.html = html;
            this.banner.hidden = html === '';
        }
    }

    onStatusClick() {
        const status = this.queue.status();

        if (status.state === 'attention') {
            this.problemsOpen = true;
            this.renderProblems();
            this.problemsPanel.querySelector('button')?.focus();
        } else if (status.state === 'offline' || status.state === 'waiting' || status.state === 'auth') {
            this.queue.retryNow();
            this.queue.refetch();
        } else if (status.state === 'forbidden' || status.state === 'gone') {
            window.location.reload();
        } else {
            this.queue.flushNow();
        }
    }

    // ---------------------------------------------------------------- problems panel

    renderProblems() {
        const core = this.texts.core;
        const problems = this.queue.problems();
        this.problemsPanel.hidden = problems.length === 0;

        if (problems.length === 0) {
            this.problemsPanel.replaceChildren();

            return;
        }

        const items = problems.map((problem) => {
            const where = this.describeProblem(problem);
            const conflict = problem.status === 'conflict';
            const buttons = [`<button type="button" class="btn btn-sm btn-outline-secondary" data-problem-action="show" data-problem="${escapeHtml(problem.id)}">${escapeHtml(core.t('problem_show'))}</button>`];

            if (conflict) {
                buttons.push(`<button type="button" class="btn btn-sm btn-outline-danger" data-problem-action="keep" data-problem="${escapeHtml(problem.id)}">${escapeHtml(core.t('problem_keep_mine'))}</button>`);
                buttons.push(`<button type="button" class="btn btn-sm btn-outline-secondary" data-problem-action="theirs" data-problem="${escapeHtml(problem.id)}">${escapeHtml(core.t('problem_use_theirs'))}</button>`);
            } else {
                if (problem.kind === 'results') {
                    buttons.push(`<button type="button" class="btn btn-sm btn-outline-secondary" data-problem-action="retry" data-problem="${escapeHtml(problem.id)}">${escapeHtml(core.t('problem_try_again'))}</button>`);
                }

                buttons.push(`<button type="button" class="btn btn-sm btn-outline-secondary" data-problem-action="dismiss" data-problem="${escapeHtml(problem.id)}">${escapeHtml(core.t('problem_dismiss'))}</button>`);
            }

            const icon = conflict ? 'bi-people' : 'bi-exclamation-octagon';
            const state = core.t(conflict ? 'marker_conflict' : 'marker_refused');

            return `<li class="sheet-problem sheet-problem-${escapeHtml(problem.status)}">
                <span class="sheet-problem-text"><i class="bi ${icon}" aria-hidden="true"></i> <strong>${escapeHtml(state)}:</strong> ${escapeHtml(where)}${problem.message ? ` - ${escapeHtml(problem.message)}` : ''}</span>
                <span class="sheet-problem-actions">${buttons.join('')}</span>
            </li>`;
        }).join('');

        this.problemsPanel.innerHTML = `<div class="sheet-problems-head">
                <h2 class="h6 mb-0"><i class="bi bi-exclamation-triangle me-1" aria-hidden="true"></i>${escapeHtml(core.tc('status_attention', problems.length))}</h2>
                <button type="button" class="btn btn-sm btn-link" data-problem-action="toggle" aria-expanded="${this.problemsOpen ? 'true' : 'false'}">${escapeHtml(core.t(this.problemsOpen ? 'problems_hide' : 'problems_show'))}</button>
            </div>
            <ul class="sheet-problems-list list-unstyled mb-0"${this.problemsOpen ? '' : ' hidden'}>${items}</ul>`;
    }

    /** "Kim Example · Country - changed meanwhile to Canada" - who/what a problem is about. */
    describeProblem(problem) {
        const core = this.texts.core;

        if (problem.kind === 'results') {
            const round = this.model.round(problem.roundId)?.name ?? '';
            const ref = problem.ref ?? '';
            let who = '';

            if (ref.startsWith('team:')) {
                who = this.model.team(ref.slice(5))?.name ?? core.t('team_no_name');
            } else {
                const place = this.model.placeById(ref.slice('participant_round:'.length));
                who = place ? (this.model.person(place.participantId)?.name ?? '') : '';
            }

            return [round, who, core.t(`field_${problem.field}`)].filter(Boolean).join(' · ');
        }

        const change = problem.change;
        const parts = [];

        if (change.participant || change.op === 'newParticipant') {
            parts.push(this.model.person(change.participant ?? change.id)?.name ?? change.name ?? '');
        }

        if (change.team) {
            parts.push(this.model.team(change.team)?.name ?? core.t('team_no_name'));
        }

        if (change.round) {
            parts.push(this.model.round(change.round)?.name ?? '');
        }

        parts.push(core.t(change.op === 'field' ? `field_${change.field}` : `op_${change.op}`));

        let text = parts.filter(Boolean).join(' · ');

        if (problem.status === 'conflict' && problem.current !== undefined) {
            text += ` (${core.t('problem_now', { value: this.formatValue(change, problem.current) })})`;
        }

        return text;
    }

    formatValue(change, value) {
        const core = this.texts.core;

        if (value === null || value === undefined || value === '') {
            return core.t('value_empty');
        }

        if (change.op === 'field' && change.field === 'country') {
            return this.countriesValue?.[value] ?? String(value).toUpperCase();
        }

        if (change.op === 'place') {
            if (value === 'out') {
                return core.t('value_out');
            }

            if (value === 'in') {
                return core.t('value_in');
            }

            const teamId = String(value).slice(5);

            return this.model.team(teamId)?.name ?? core.t('team_no_name');
        }

        return String(value);
    }

    onProblemClick(event) {
        const button = event.target.closest('[data-problem-action]');

        if (!button) {
            return;
        }

        const id = button.dataset.problem;
        const problem = id ? this.queue.problem(id) : null;

        switch (button.dataset.problemAction) {
            case 'toggle':
                this.problemsOpen = !this.problemsOpen;
                this.renderProblems();
                this.problemsPanel.querySelector('[data-problem-action="toggle"]')?.focus();
                break;
            case 'show':
                if (problem !== null) {
                    const tab = problem.kind === 'results' ? problem.roundId : (problem.group?.origin ?? 'people');
                    this.showTab(tab, { reveal: problem });
                }
                break;
            case 'keep':
                this.queue.keepMine(id);
                break;
            case 'theirs':
            case 'dismiss':
                this.queue.dismiss(id);
                break;
            case 'retry':
                this.queue.retryProblem(id);
                break;
            default:
        }
    }

    // ---------------------------------------------------------------- help

    showHelp() {
        const core = this.texts.core;

        if (!this.helpDialog) {
            const rows = ['move', 'edit', 'type', 'commit', 'cancel', 'tab', 'space', 'list', 'select', 'select_column', 'copy', 'fill', 'clear', 'undo', 'leave', 'ime']
                .map((key) => `<tr><th scope="row"><kbd>${escapeHtml(core.t(`help_keys_${key}`))}</kbd></th><td>${escapeHtml(core.t(`help_does_${key}`))}</td></tr>`)
                .join('');
            const dialog = document.createElement('dialog');
            dialog.className = 'sheet-help';
            dialog.setAttribute('aria-labelledby', 'sheet-help-title');
            dialog.innerHTML = `<div class="sheet-help-head"><h2 class="h5 mb-0" id="sheet-help-title">${escapeHtml(core.t('help_title'))}</h2><button type="button" class="btn-close" data-help-close aria-label="${escapeHtml(core.t('help_close'))}"></button></div>
                <div class="sheet-help-body">
                    <table class="table table-sm align-middle mb-3"><caption class="visually-hidden">${escapeHtml(core.t('help_title'))}</caption><tbody>${rows}</tbody></table>
                    <p class="small mb-1">${escapeHtml(core.t('help_saving'))}</p>
                    <p class="small mb-1">${escapeHtml(core.t('help_undo_note'))}</p>
                    <p class="small mb-0">${escapeHtml(core.t('help_mac'))}</p>
                </div>`;
            dialog.addEventListener('click', (event) => {
                if (event.target.closest('[data-help-close]') || event.target === dialog) {
                    dialog.close();
                }
            });
            dialog.addEventListener('close', () => this.helpReturn?.focus?.({ preventScroll: true }));
            this.element.append(dialog);
            this.helpDialog = dialog;
        }

        this.helpReturn = document.activeElement;
        this.helpDialog.showModal();
        this.helpDialog.querySelector('[data-help-close]')?.focus();
    }
}
