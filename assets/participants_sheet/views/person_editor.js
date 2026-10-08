/**
 * The person editor of the participants sheet (docs/features/competitions-management/participants-spreadsheet.md §8,
 * contract O13, "Client architecture (as built)": `export default (context) → {open(personId, options), update(delta),
 * destroy()}`).
 *
 * - Desktop: a side panel - a non-modal `<dialog>` opened with show() at the right of the sheet (the grid makes room);
 *   phones (< 768 px): a full-screen modal `<dialog>`. A sticky title with ✕, ‹ › previous/next within the list the
 *   opener hands in (`options.list()` - its filtered, sorted rows), Esc closes (Esc in a field with unsaved typing puts
 *   the field back first), the focus returns to where it came from (`options.returnFocus(personId)`).
 * - Fields: name, country, external id, private note, the MySpeedPuzzling profile (search → link, Unlink, "Linked to a
 *   MySpeedPuzzling profile" for one the viewer may not see - O9), the rounds (per solo round In/Out; per pair/team round
 *   In/Out and a pair/team picker - teams labelled `Corners · Table 2 · Kim Example, Pat Sample` (O1), "No pair yet",
 *   "+ New pair" - with "with Kim Example" under it), the registration of a managed event (status and the actions its
 *   state allows), Remove from event / Restore.
 * - Every field saves like a cell edit (the same sheet_changes.js builders and context.act(); text on change / blur /
 *   Enter) and shows its marker next to it: saving, changed meanwhile (Keep mine / Use theirs), not saved (the reason).
 *   A text field's change is sent with `from` = the value shown when the organiser started typing - somebody else's
 *   change meanwhile comes back as a conflict instead of being overwritten.
 * - It follows live updates (update(delta)) without wiping what is being typed: a section holding the focus waits until
 *   the focus leaves it.
 */

import { escapeHtml } from '../sheet_grid.js';
import {
    buildAction,
    cleanFieldValue,
    linkProfile,
    newTeamRow,
    putInTeam,
    removePeople,
    restorePeople,
    setField,
    setPlace,
} from '../sheet_changes.js';
import { parsePlace } from '../sheet_model.js';
import { allowedActions, paidBefore, performRegistrationAction, registrationStatus, waitlistPositions } from '../registration_actions.js';
import { formatDate, reasonFor, searchPlayers, teamLabelText } from './people_view.js';

const PHONE_QUERY = '(max-width: 767.98px)';
const TEXT_FIELDS = ['name', 'externalId', 'note'];
const NEW_TEAM = '__new';
const SEARCH_DELAY_MS = 200;
let editorCounter = 0;

export default function createPersonEditor(context) {
    return new PersonEditor(context);
}

export class PersonEditor {
    constructor(context) {
        this.context = context;
        this.model = context.model;
        this.core = context.texts.core;
        this.people = context.texts.people;
        this.id = `sheet-person-${++editorCounter}`;
        this.personId = null;
        this.options = {};
        this.dialog = null;
        this.seen = {};
        this.seenPlace = {};
        this.dirty = new Set();
        this.errors = {};
        this.stale = new Set();
        this.search = { timer: null, controller: null, results: [], active: -1, request: 0 };
        this.cleanups = [];
    }

    say(key, params) {
        return this.people.t(key, params);
    }

    sayCount(key, count, params) {
        return this.people.tc(key, count, params);
    }

    t(key, params) {
        return this.core.t(key, params);
    }

    get host() {
        return this.context.root.closest?.('[data-controller~="participants-sheet"]') ?? document.body;
    }

    get managed() {
        return this.model.competition?.registrationManaged === true;
    }

    // ---------------------------------------------------------------- the editor interface

    /**
     * @param {string} personId
     * @param {{list?: function(): string[], returnFocus?: function(string): void, onShow?: function(string|null): void}} [options]
     */
    open(personId, options = {}) {
        if (this.model.person(personId) === null) {
            return;
        }

        this.ensureDialog();
        this.options = options;
        this.phone = window.matchMedia(PHONE_QUERY).matches;

        if (!this.dialog.open) {
            this.returnTo = document.activeElement;
            this.dialog.classList.toggle('is-modal', this.phone);

            if (this.phone) {
                this.dialog.showModal();
            } else {
                this.placePanel();
                this.dialog.show();
                this.host.classList.add('has-person-panel');
            }
        }

        this.show(personId);

        // Desktop: straight into the name; a phone: the title (no keyboard popping up over half the screen)
        (this.phone ? this.dialog.querySelector(`#${this.id}-title`) : this.dialog.querySelector('[data-field="name"]'))?.focus({ preventScroll: true });
    }

    update() {
        if (this.dialog?.open && this.personId !== null) {
            if (this.model.person(this.personId) === null) {
                this.close();

                return;
            }

            this.sync();
        }
    }

    destroy() {
        clearTimeout(this.search.timer);
        this.search.controller?.abort();
        this.cleanups.forEach((cleanup) => cleanup());
        this.cleanups = [];

        if (this.dialog) {
            if (this.dialog.open) {
                this.dialog.close();
            }

            this.dialog.remove();
            this.dialog = null;
        }

        this.host.classList.remove('has-person-panel');
    }

    listen(target, type, handler, options) {
        target.addEventListener(type, handler, options);
        this.cleanups.push(() => target.removeEventListener(type, handler, options));
    }

    // ---------------------------------------------------------------- the dialog

    ensureDialog() {
        if (this.dialog) {
            return;
        }

        const dialog = document.createElement('dialog');
        dialog.className = 'sheet-person';
        dialog.setAttribute('aria-labelledby', `${this.id}-title`);
        dialog.innerHTML = `<div class="sheet-person-head">
                <button type="button" class="btn-close" data-person-close aria-label="${escapeHtml(this.say('editor_close'))}" title="${escapeHtml(this.say('editor_close'))}"></button>
                <h2 class="sheet-person-title" id="${this.id}-title" tabindex="-1"></h2>
                <span class="sheet-person-position" data-person-position></span>
                <span class="sheet-person-nav">
                    <button type="button" class="btn btn-outline-secondary" data-person-prev><i class="bi bi-chevron-left" aria-hidden="true"></i></button>
                    <button type="button" class="btn btn-outline-secondary" data-person-next><i class="bi bi-chevron-right" aria-hidden="true"></i></button>
                </span>
            </div>
            <div class="sheet-person-body" data-person-body></div>`;
        this.host.append(dialog);
        this.dialog = dialog;
        this.body = dialog.querySelector('[data-person-body]');

        this.listen(dialog, 'click', (event) => this.onClick(event));
        this.listen(dialog, 'change', (event) => this.onChange(event));
        this.listen(dialog, 'input', (event) => this.onInput(event));
        this.listen(dialog, 'keydown', (event) => this.onKeyDown(event));
        this.listen(dialog, 'focusin', (event) => this.onFocusIn(event));
        this.listen(dialog, 'focusout', (event) => this.onFocusOut(event));
        this.listen(dialog, 'cancel', (event) => {
            event.preventDefault();
            this.close();
        });
        this.listen(window, 'resize', () => {
            if (this.dialog?.open && !this.phone) {
                this.placePanel();
            }
        });

        const media = window.matchMedia(PHONE_QUERY);
        this.listen(media, 'change', () => {
            // Desktop panel ↔ phone dialog: opened again in the other form, on the same person
            if (this.dialog?.open && media.matches !== this.phone && this.personId !== null) {
                const personId = this.personId;
                const options = this.options;
                this.dialog.close();
                this.host.classList.remove('has-person-panel');
                this.open(personId, options);
            }
        });
    }

    /** The desktop panel sits below the sheet's bar, at the right edge. */
    placePanel() {
        const bar = document.querySelector('.participants-sheet-bar');
        const top = bar ? Math.max(0, Math.round(bar.getBoundingClientRect().bottom)) : 0;
        this.dialog.style.setProperty('--sheet-person-top', `${top}px`);
    }

    close() {
        if (!this.dialog?.open) {
            return;
        }

        // A typed value not saved yet goes before the panel closes (blur of a field in a closing dialog is not reliable)
        const focused = document.activeElement;

        if (focused?.matches?.('[data-field]') && this.dialog.contains(focused) && TEXT_FIELDS.includes(focused.dataset.field)) {
            this.saveText(focused.dataset.field, focused.value);
        }

        const personId = this.personId;
        this.dialog.close();
        this.host.classList.remove('has-person-panel');
        this.closeResults();
        this.personId = null;
        this.options.onShow?.(null);

        if (typeof this.options.returnFocus === 'function' && personId !== null) {
            this.options.returnFocus(personId);
        } else if (this.returnTo?.isConnected) {
            this.returnTo.focus({ preventScroll: true });
        }
    }

    /** Shows a person: everything rendered for them (open, previous/next). */
    show(personId) {
        this.personId = personId;
        this.seen = {};
        this.seenPlace = {};
        this.dirty = new Set();
        this.errors = {};
        this.stale = new Set();
        this.closeResults();
        this.body.innerHTML = this.bodyHtml();
        this.sync(true);
        this.options.onShow?.(personId);
    }

    list() {
        const ids = typeof this.options.list === 'function' ? this.options.list() : this.model.people().map((person) => person.id);

        return Array.isArray(ids) ? ids : [];
    }

    go(step) {
        const ids = this.list();
        const index = ids.indexOf(this.personId);
        const next = ids[index + step];

        if (index === -1 || next === undefined) {
            return;
        }

        // What is typed in the field with the focus is saved first
        const focused = document.activeElement;

        if (focused?.matches?.('[data-field]') && TEXT_FIELDS.includes(focused.dataset.field)) {
            this.saveText(focused.dataset.field, focused.value);
        }

        this.show(next);
        this.context.announce(this.say('editor_now', { name: this.model.person(next)?.name ?? '', position: index + step + 1, count: ids.length }));
        this.dialog.querySelector(step < 0 ? '[data-person-prev]' : '[data-person-next]')?.focus({ preventScroll: true });

        if (document.activeElement?.disabled || !this.dialog.contains(document.activeElement)) {
            this.dialog.querySelector(`#${this.id}-title`)?.focus({ preventScroll: true });
        }
    }

    // ---------------------------------------------------------------- markup

    bodyHtml() {
        const id = this.id;
        const countries = Object.entries(this.context.countries)
            .map(([code, label]) => `<option value="${escapeHtml(code)}">${escapeHtml(label)}</option>`)
            .join('');
        const field = (key, label, input, help = '') => `<div class="sheet-person-field">
                <label class="form-label" for="${id}-${key}">${escapeHtml(label)}</label>
                ${input}
                ${help ? `<div class="form-text" id="${id}-${key}-help">${escapeHtml(help)}</div>` : ''}
                <div class="sheet-person-error" id="${id}-${key}-error" data-error="${key}" hidden></div>
                <div class="sheet-person-meanwhile" data-meanwhile="${key}"></div>
                <div class="sheet-person-marker" data-marker="${key}"></div>
            </div>`;

        return `<div class="sheet-person-section" data-section="status"></div>
            ${field('name', this.t('people_col_name'), `<input type="text" class="form-control" id="${id}-name" data-field="name" data-focus-key="name" autocomplete="off" spellcheck="false" enterkeyhint="done" maxlength="300">`)}
            ${field('country', this.t('people_col_country'), `<select class="form-select" id="${id}-country" data-field="country" data-focus-key="country"><option value="">${escapeHtml(this.t('people_no_country'))}</option>${countries}</select>`)}
            ${field('externalId', this.say('col_externalId'), `<input type="text" class="form-control" id="${id}-externalId" data-field="externalId" data-focus-key="externalId" autocomplete="off" spellcheck="false" enterkeyhint="done" maxlength="300">`)}
            ${field('note', this.say('col_note'), `<textarea class="form-control" rows="2" id="${id}-note" data-field="note" data-focus-key="note" maxlength="300" aria-describedby="${id}-note-help"></textarea>`, this.say('col_note_title'))}
            <div class="sheet-person-field">
                <span class="form-label d-block" id="${id}-player-label">${escapeHtml(this.t('people_col_profile'))}</span>
                <div class="sheet-person-section" data-section="player"></div>
                <div class="sheet-person-search" data-player-search hidden>
                    <input type="search" class="form-control" id="${id}-player" data-player-input data-focus-key="player-search" role="combobox" aria-autocomplete="list" aria-expanded="false" aria-controls="${id}-player-list" aria-labelledby="${id}-player-label" placeholder="${escapeHtml(this.say('editor_profile_search'))}" autocomplete="off" spellcheck="false">
                    <ul class="sheet-person-results" id="${id}-player-list" role="listbox" aria-labelledby="${id}-player-label" hidden></ul>
                    <div class="form-text" data-player-hint aria-live="polite"></div>
                </div>
                <div class="sheet-person-marker" data-marker="player"></div>
            </div>
            <fieldset class="sheet-person-rounds">
                <legend class="form-label">${escapeHtml(this.say('editor_rounds'))}</legend>
                <div data-section="rounds"></div>
            </fieldset>
            <div class="sheet-person-section" data-section="registration"></div>
            <div class="sheet-person-section sheet-person-danger" data-section="remove"></div>`;
    }

    /**
     * Fields and sections follow the model: a text field keeps what is being typed (its value changes only while it is
     * not edited), a section with the focus waits until the focus leaves it (`stale`).
     */
    sync(initial = false) {
        const person = this.model.person(this.personId);

        if (person === null) {
            return;
        }

        const removed = person.removedAt !== null;
        const title = this.dialog.querySelector(`#${this.id}-title`);
        title.textContent = person.name;
        this.syncNav();

        for (const key of TEXT_FIELDS) {
            const input = this.body.querySelector(`[data-field="${key}"]`);
            const value = person[key] ?? '';

            // What is being typed stays; an untouched field (focused or not) shows the new value - the organiser starts
            // from it, and a later edit is sent with it as `from`
            if (initial || !this.dirty.has(key)) {
                if (input.value !== value) {
                    input.value = value;
                }

                this.seen[key] = person[key] ?? null;
            }

            input.disabled = removed;
        }

        for (const key of TEXT_FIELDS) {
            this.renderMeanwhile(key, person);
        }

        const country = this.body.querySelector('[data-field="country"]');

        if (document.activeElement !== country || initial) {
            country.value = person.country ?? '';
        }

        country.disabled = removed;

        for (const key of [...TEXT_FIELDS, 'country', 'player']) {
            this.renderMarker(key, `person:${person.id}:${key}`);
        }

        this.renderSection('status', this.statusHtml(person));
        this.renderSection('player', this.playerHtml(person));
        this.body.querySelector('[data-player-search]').hidden = removed || (person.player !== null && person.player !== undefined);
        this.renderSection('rounds', this.roundsHtml(person));
        this.renderSection('registration', this.managed ? this.registrationHtml(person) : '');
        this.renderSection('remove', this.removeHtml(person));
    }

    syncNav() {
        const ids = this.list();
        const index = ids.indexOf(this.personId);
        const previous = index > 0 ? this.model.person(ids[index - 1]) : null;
        const next = index !== -1 && index < ids.length - 1 ? this.model.person(ids[index + 1]) : null;
        const prevButton = this.dialog.querySelector('[data-person-prev]');
        const nextButton = this.dialog.querySelector('[data-person-next]');
        prevButton.disabled = previous === null;
        nextButton.disabled = next === null;
        prevButton.setAttribute('aria-label', previous ? this.say('editor_previous', { name: previous.name }) : this.say('editor_previous_none'));
        nextButton.setAttribute('aria-label', next ? this.say('editor_next', { name: next.name }) : this.say('editor_next_none'));
        prevButton.title = prevButton.getAttribute('aria-label');
        nextButton.title = nextButton.getAttribute('aria-label');
        this.dialog.querySelector('[data-person-position]').textContent = index === -1 ? '' : this.say('editor_position', { position: index + 1, count: ids.length });
    }

    renderSection(name, html) {
        const section = this.body.querySelector(`[data-section="${name}"]`);

        if (section === null || section.__html === html) {
            this.stale.delete(name);

            return;
        }

        const focused = section.contains(document.activeElement) ? document.activeElement : null;

        if (focused !== null && focused.matches('select, input[type="text"], textarea')) {
            // An open picker or a field being used is not pulled away - rendered when the focus leaves
            this.stale.add(name);

            return;
        }

        const focusKey = focused?.dataset.focusKey ?? null;
        section.innerHTML = html;
        section.__html = html;
        this.stale.delete(name);

        if (focusKey !== null) {
            (section.querySelector(`[data-focus-key="${CSS.escape(focusKey)}"]`) ?? this.dialog.querySelector(`#${this.id}-title`))?.focus({ preventScroll: true });
        }
    }

    /**
     * A field being typed in whose value changed meanwhile (a live update): "Changed meanwhile to "X"" with Keep mine
     * (my text goes over it) and Use theirs (the field shows theirs).
     */
    renderMeanwhile(key, person) {
        const element = this.body.querySelector(`[data-meanwhile="${key}"]`);
        const current = person[key] ?? null;
        let html = '';

        if (element !== null && this.dirty.has(key) && key in this.seen && this.seen[key] !== current) {
            html = `<div class="sheet-person-mark sheet-person-mark-conflict" role="alert">
                    <span><i class="bi bi-people" aria-hidden="true"></i> ${escapeHtml(this.say('editor_changed_meanwhile', { value: current ?? this.t('value_empty') }))}</span>
                    <span class="sheet-person-mark-actions">
                        <button type="button" class="btn btn-sm btn-outline-danger" data-meanwhile-keep="${escapeHtml(key)}">${escapeHtml(this.t('problem_keep_mine'))}</button>
                        <button type="button" class="btn btn-sm btn-outline-secondary" data-meanwhile-theirs="${escapeHtml(key)}">${escapeHtml(this.t('problem_use_theirs'))}</button>
                    </span>
                </div>`;
        }

        if (element !== null && element.__html !== html) {
            element.innerHTML = html;
            element.__html = html;
        }
    }

    renderMarker(key, markKey) {
        const element = this.body.querySelector(`[data-marker="${key}"]`);
        const html = this.markerHtml(markKey);

        if (element !== null && element.__html !== html) {
            element.innerHTML = html;
            element.__html = html;
        }
    }

    /** A field's state in words, with Keep mine / Use theirs for a conflict and OK for a refusal. */
    markerHtml(key) {
        const mark = this.model.marks.get(key);
        const marker = this.context.markerFor(key);

        if (mark === null || marker === null) {
            return '';
        }

        const icon = { saving: 'bi-arrow-repeat', waiting: 'bi-cloud-slash', conflict: 'bi-people', refused: 'bi-exclamation-octagon', warning: 'bi-exclamation-triangle', info: 'bi-info-circle' }[mark.state] ?? 'bi-dot';
        let buttons = '';

        if (mark.problemId && mark.state === 'conflict') {
            buttons = `<button type="button" class="btn btn-sm btn-outline-danger" data-problem-keep="${escapeHtml(mark.problemId)}" data-focus-key="keep-${escapeHtml(key)}">${escapeHtml(this.t('problem_keep_mine'))}</button>
                <button type="button" class="btn btn-sm btn-outline-secondary" data-problem-theirs="${escapeHtml(mark.problemId)}" data-focus-key="theirs-${escapeHtml(key)}">${escapeHtml(this.t('problem_use_theirs'))}</button>`;
        } else if (mark.problemId && mark.state === 'refused') {
            buttons = `<button type="button" class="btn btn-sm btn-outline-secondary" data-problem-theirs="${escapeHtml(mark.problemId)}" data-focus-key="ok-${escapeHtml(key)}">${escapeHtml(this.t('problem_dismiss'))}</button>`;
        }

        return `<div class="sheet-person-mark sheet-person-mark-${escapeHtml(mark.state)}" role="${mark.state === 'saving' ? 'status' : 'alert'}">
                <span><i class="bi ${icon}" aria-hidden="true"></i> <strong>${escapeHtml(marker.text)}</strong>${mark.message ? ` - ${escapeHtml(mark.message)}` : ''}</span>
                ${buttons ? `<span class="sheet-person-mark-actions">${buttons}</span>` : ''}
            </div>`;
    }

    statusHtml(person) {
        const lines = [];

        if (person.removedAt !== null) {
            lines.push(`<span class="sheet-badge sheet-badge-removed">${escapeHtml(this.say('badge_removed'))}</span>`);
        }

        if (person.source === 'self_joined') {
            lines.push(escapeHtml(person.connectedAt ? this.say('editor_joined_on', { date: formatDate(person.connectedAt, this.context.locale, true) }) : this.t('people_badge_joined')));
        } else {
            lines.push(escapeHtml(this.say(person.source === 'imported' ? 'source_imported' : 'source_manual')));
        }

        if (person.registration?.status === 'waitlisted') {
            lines.push(`<span class="sheet-badge sheet-badge-waitlist">${escapeHtml(this.t('people_badge_waitlisted'))}</span>`);
        }

        const same = person.removedAt === null ? this.model.peopleNamed(person.name).filter((other) => other.id !== person.id) : [];

        if (same.length > 0) {
            lines.push(`<span class="sheet-attention"><i class="bi bi-exclamation-triangle" aria-hidden="true"></i> ${escapeHtml(this.say('same_name_as', { name: same.map((other) => other.name).join(', ') }))}</span>`);
        }

        return `<p class="sheet-person-status small text-body-secondary">${lines.join(' · ')}</p>`;
    }

    playerHtml(person) {
        const player = person.player;
        const removed = person.removedAt !== null;

        if (player === null || player === undefined) {
            return '';
        }

        const unlink = removed ? '' : ` <button type="button" class="btn btn-sm btn-outline-secondary" data-unlink data-focus-key="unlink">${escapeHtml(this.t('people_profile_unlink'))}</button>`;

        if (player.visible !== true) {
            return `<div class="sheet-person-player"><span class="sheet-muted">${escapeHtml(this.t('people_profile_hidden'))}</span>${unlink}</div>`;
        }

        const code = player.code ? `#${String(player.code).toUpperCase()}` : '';
        const name = player.name ?? code;
        const avatar = player.avatar
            ? `<img class="sheet-avatar" src="${escapeHtml(player.avatar)}" alt="" width="24" height="24">`
            : '<i class="bi bi-person-circle sheet-avatar-icon" aria-hidden="true"></i>';
        const label = `${avatar}${escapeHtml(name)}${code && code !== name ? ` <span class="sheet-muted">${escapeHtml(code)}</span>` : ''}`;
        const shown = player.profileUrl
            ? `<a href="${escapeHtml(player.profileUrl)}" target="_blank" rel="noopener" data-focus-key="profile">${label} <i class="bi bi-box-arrow-up-right" aria-hidden="true"></i><span class="visually-hidden"> (${escapeHtml(this.say('editor_new_tab'))})</span></a>`
            : `<span>${label}</span>`;

        return `<div class="sheet-person-player">${shown}${unlink}</div>`;
    }

    roundsHtml(person) {
        const rounds = this.model.rounds();

        if (rounds.length === 0) {
            return `<p class="small text-body-secondary mb-0">${escapeHtml(this.say('editor_no_rounds'))}</p>`;
        }

        return rounds.map((round) => this.roundHtml(person, round)).join('');
    }

    roundHtml(person, round) {
        const id = `${this.id}-round-${round.id}`;
        const place = parsePlace(this.model.placeValue(person.id, round.id));
        const inRound = place.kind !== 'out';
        const removed = person.removedAt !== null;
        const disabled = removed ? ' disabled' : '';
        const kind = this.t(`round_kind_${round.category}`);
        const swatch = `<span class="sheet-round-swatch" style="background-color:${escapeHtml(/^#[0-9a-f]{3,8}$/i.test(round.color ?? '') ? round.color : 'transparent')}" aria-hidden="true"></span>`;
        let picker = '';

        if (round.category !== 'solo' && inRound) {
            const duo = round.category === 'duo';
            const options = [`<option value="in"${place.kind === 'in' ? ' selected' : ''}>${escapeHtml(this.t(duo ? 'people_no_pair_yet' : 'people_no_team_yet'))}</option>`];

            for (const team of this.model.teamsOf(round.id)) {
                options.push(`<option value="team:${escapeHtml(team.id)}"${place.teamId === team.id ? ' selected' : ''}>${escapeHtml(teamLabelText(this.model, team.id, (key, params) => this.t(key, params), (key, params) => this.say(key, params)))}</option>`);
            }

            options.push(`<option value="${NEW_TEAM}">${escapeHtml(this.say(duo ? 'editor_new_pair' : 'editor_new_team'))}</option>`);
            const others = place.teamId ? this.model.membersOf(place.teamId).filter((member) => member.id !== person.id).map((member) => member.name) : [];
            let withText = '';

            if (others.length > 0) {
                withText = this.say('editor_with', { names: others.join(', ') });
            } else if (place.kind === 'in') {
                withText = this.say(duo ? 'editor_in_no_pair' : 'editor_in_no_team', { round: round.name });
            }

            const size = place.teamId ? this.model.sizeStatus(place.teamId) : null;
            const sizeText = size && (size.status === 'incomplete' || size.status === 'too_many') ? ` · ${this.t('team_size_short', { count: size.count, expected: size.expected })}` : '';

            picker = `<div class="sheet-person-team">
                    <label class="visually-hidden" for="${id}-team">${escapeHtml(this.say(duo ? 'editor_pair_in' : 'editor_team_in', { round: round.name }))}</label>
                    <select class="form-select form-select-sm" id="${id}-team" data-round-team="${escapeHtml(round.id)}" data-focus-key="team-${escapeHtml(round.id)}"${disabled}>${options.join('')}</select>
                    ${withText ? `<div class="small ${place.kind === 'in' ? 'sheet-attention' : 'text-body-secondary'}">${place.kind === 'in' ? '<i class="bi bi-exclamation-triangle" aria-hidden="true"></i> ' : ''}${escapeHtml(withText)}${escapeHtml(sizeText)}</div>` : ''}
                </div>`;
        }

        const error = this.errors[`place:${round.id}`];

        return `<div class="sheet-person-round" data-round="${escapeHtml(round.id)}">
                <div class="form-check form-switch">
                    <input class="form-check-input" type="checkbox" role="switch" id="${id}" data-round-switch="${escapeHtml(round.id)}" data-focus-key="round-${escapeHtml(round.id)}"${inRound ? ' checked' : ''}${disabled}>
                    <label class="form-check-label" for="${id}">${swatch} ${escapeHtml(round.name)} <span class="text-body-secondary small">${escapeHtml(kind)}</span></label>
                </div>
                ${picker}
                ${error ? `<div class="sheet-person-error" role="alert">${escapeHtml(error)}</div>` : ''}
                ${this.markerHtml(`place:${person.id}:${round.id}`)}
            </div>`;
    }

    registrationHtml(person) {
        const status = registrationStatus(person);

        if (status === null) {
            return '';
        }

        const registration = person.registration;
        const position = status === 'waitlisted' ? waitlistPositions(this.model.people()).get(person.id) : null;
        const label = status === 'waitlisted' && position ? this.say('status_waitlisted_position', { position }) : this.say(`status_${status}`);
        const facts = [];

        if (status === 'paid' && registration.paidAt) {
            facts.push(this.say('editor_paid_on', { date: formatDate(registration.paidAt, this.context.locale, false) }));
        }

        const before = paidBefore(person);

        if (before) {
            facts.push(this.say('paid_before', { date: formatDate(before, this.context.locale, false) }));
        }

        if (registration.checkedInAt) {
            facts.push(this.say('editor_checked_in_at', { date: formatDate(registration.checkedInAt, this.context.locale, true) }));
        }

        const actions = allowedActions(person, { checkIn: this.model.competition?.isOnline !== true }).map((action) => `<button type="button" class="btn btn-sm ${action === 'markPaid' || action === 'promoteAndMarkPaid' || action === 'promote' || action === 'checkIn' ? 'btn-outline-success' : 'btn-outline-secondary'}" data-registration="${escapeHtml(action)}" data-focus-key="reg-${escapeHtml(action)}" aria-describedby="${this.id}-reg-${escapeHtml(action)}">${escapeHtml(this.say(`reg_${action}`))}</button><span class="visually-hidden" id="${this.id}-reg-${escapeHtml(action)}">${escapeHtml(this.say(`reg_${action}_help`))}</span>`).join('');
        const help = allowedActions(person, { checkIn: this.model.competition?.isOnline !== true }).filter((action) => action === 'markPaid' || action === 'promoteAndMarkPaid' || action === 'promote').map((action) => this.say(`reg_${action}_help`));

        return `<h3 class="form-label">${escapeHtml(this.say('col_registration'))}</h3>
            <p class="mb-1"><span class="sheet-reg sheet-reg-${escapeHtml(status)}">${escapeHtml(label)}</span>${facts.length > 0 ? ` <span class="small text-body-secondary">${escapeHtml(facts.join(' · '))}</span>` : ''}</p>
            ${actions ? `<div class="sheet-person-actions">${actions}</div>` : ''}
            ${help.length > 0 ? `<p class="form-text mb-0">${escapeHtml([...new Set(help)].join(' '))}</p>` : ''}
            ${this.markerHtml(`person:${person.id}:registration`)}`;
    }

    removeHtml(person) {
        const error = this.errors.removed ? `<div class="sheet-person-error" role="alert">${escapeHtml(this.errors.removed)}</div>` : '';

        if (person.removedAt !== null) {
            return `<p class="small text-body-secondary mb-2">${escapeHtml(this.say('editor_removed_note'))}</p>
                <button type="button" class="btn btn-outline-success" data-restore data-focus-key="restore"><i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i> ${escapeHtml(this.say('row_restore'))}</button>${error}${this.markerHtml(`person:${person.id}:removed`)}`;
        }

        return `<button type="button" class="btn btn-outline-danger" data-remove data-focus-key="remove"><i class="bi bi-person-x" aria-hidden="true"></i> ${escapeHtml(this.say('row_remove'))}</button>${error}${this.markerHtml(`person:${person.id}:removed`)}`;
    }

    // ---------------------------------------------------------------- saving

    perform(action) {
        const blocked = action.groups.length === 0 && action.errors.length > 0;
        this.context.act(action, { quiet: true });

        return blocked ? reasonFor(this.context, action.errors[0]) : null;
    }

    builderOptions() {
        return { countries: this.context.countryCodes };
    }

    showError(key, message) {
        const element = this.body.querySelector(`[data-error="${key}"]`);
        const input = this.body.querySelector(`[data-field="${key}"]`);

        if (element === null || input === null) {
            return;
        }

        element.textContent = message ?? '';
        element.hidden = !message;

        if (message) {
            input.setAttribute('aria-invalid', 'true');
            input.setAttribute('aria-describedby', [element.id, key === 'note' ? `${this.id}-note-help` : ''].filter(Boolean).join(' '));
        } else {
            input.removeAttribute('aria-invalid');

            if (key === 'note') {
                input.setAttribute('aria-describedby', `${this.id}-note-help`);
            } else {
                input.removeAttribute('aria-describedby');
            }
        }
    }

    /**
     * A text field saved: `from` = what the organiser saw when they started typing (a change meanwhile comes back as a
     * conflict). A value the server would refuse stays in the field with the reason.
     */
    saveText(key, value) {
        const person = this.model.person(this.personId);

        if (person === null || person.removedAt !== null) {
            return null;
        }

        const current = person[key] ?? null;
        const seen = key in this.seen ? this.seen[key] : current;
        const to = cleanFieldValue(key, value);
        this.dirty.delete(key);

        if (to === seen) {
            // Nothing typed over what was shown: nothing is sent - whatever arrived meanwhile stays
            this.seen[key] = current;
            this.showError(key, null);
            this.renderMeanwhile(key, person);

            return null;
        }

        if (to === current) {
            // Somebody else typed the same meanwhile
            this.seen[key] = current;
            this.showError(key, null);
            this.renderMeanwhile(key, person);

            return null;
        }

        const action = seen === current
            ? setField(this.model, person.id, key, value, this.builderOptions())
            : buildAction(this.model, [[{ op: 'field', participant: person.id, field: key, from: seen, to }]], { label: { key: 'field', field: key }, ...this.builderOptions() });
        const error = this.perform(action);

        if (error !== null) {
            if (TEXT_FIELDS.includes(key)) {
                this.dirty.add(key);
                this.showError(key, error);
            }

            return error;
        }

        this.showError(key, null);
        this.seen[key] = to;
        this.renderMeanwhile(key, this.model.person(this.personId));

        return null;
    }

    onInput(event) {
        const field = event.target.closest('[data-field]');

        if (field && TEXT_FIELDS.includes(field.dataset.field)) {
            this.dirty.add(field.dataset.field);
            this.showError(field.dataset.field, null);

            return;
        }

        if (event.target.matches('[data-player-input]')) {
            this.queueSearch(event.target.value);
        }
    }

    onChange(event) {
        const target = event.target;
        const person = this.model.person(this.personId);

        if (person === null) {
            return;
        }

        if (target.matches('[data-field]')) {
            if (TEXT_FIELDS.includes(target.dataset.field)) {
                this.saveText(target.dataset.field, target.value);
            } else if (target.dataset.field === 'country') {
                const error = this.saveText('country', target.value === '' ? null : target.value);

                if (error !== null) {
                    target.value = person.country ?? '';
                    this.context.announce(error);
                }
            }

            return;
        }

        if (target.matches('[data-round-switch]')) {
            this.setRound(target.dataset.roundSwitch, target.checked ? 'in' : 'out', target);

            return;
        }

        if (target.matches('[data-round-team]')) {
            const roundId = target.dataset.roundTeam;
            const value = target.value;

            if (value === NEW_TEAM) {
                const action = newTeamRow(this.model, roundId, { members: [person.id] }, this.placeOptions(roundId));
                this.roundOutcome(roundId, this.perform(action), target);

                if (action.groups.length > 0) {
                    this.context.announce(this.say(this.model.round(roundId)?.category === 'duo' ? 'editor_new_pair_done' : 'editor_new_team_done', { round: this.model.round(roundId)?.name ?? '' }));
                }
            } else if (value === 'in') {
                this.setRound(roundId, 'in', target);
            } else {
                this.roundOutcome(roundId, this.perform(putInTeam(this.model, roundId, value.slice('team:'.length), person.id, this.placeOptions(roundId))), target);
            }
        }
    }

    setRound(roundId, to, control) {
        const person = this.model.person(this.personId);
        const error = this.perform(setPlace(this.model, person.id, roundId, to, this.placeOptions(roundId)));
        this.roundOutcome(roundId, error, control);
    }

    /** `from` of a place change = the place shown when the control got the focus. */
    placeOptions(roundId) {
        return roundId in this.seenPlace ? { ...this.builderOptions(), from: this.seenPlace[roundId] } : this.builderOptions();
    }

    /** A refused round change: the control goes back, the reason stays under the round. */
    roundOutcome(roundId, error, control) {
        this.errors[`place:${roundId}`] = error ?? undefined;
        this.seenPlace[roundId] = this.model.placeValue(this.personId, roundId);

        if (error !== null && error !== undefined) {
            this.context.announce(error);
        }

        // Re-rendered at once (the control's own state included) - the focus stays on the same control
        const section = this.body.querySelector('[data-section="rounds"]');
        section.__html = null;
        const key = control?.dataset.focusKey ?? null;
        section.innerHTML = this.roundsHtml(this.model.person(this.personId));
        section.__html = section.innerHTML;

        if (key !== null) {
            section.querySelector(`[data-focus-key="${CSS.escape(key)}"]`)?.focus({ preventScroll: true });
        }
    }

    onClick(event) {
        const target = event.target;

        if (target.closest('[data-person-close]')) {
            this.close();

            return;
        }

        if (target.closest('[data-person-prev]')) {
            this.go(-1);

            return;
        }

        if (target.closest('[data-person-next]')) {
            this.go(1);

            return;
        }

        const person = this.model.person(this.personId);

        if (person === null) {
            return;
        }

        const keepMine = target.closest('[data-meanwhile-keep]');

        if (keepMine) {
            // My text goes over theirs: saved now, from what is there now
            const key = keepMine.dataset.meanwhileKeep;
            this.seen[key] = person[key] ?? null;
            this.saveText(key, this.body.querySelector(`[data-field="${key}"]`).value);
            this.body.querySelector(`[data-field="${key}"]`)?.focus({ preventScroll: true });

            return;
        }

        const useTheirs = target.closest('[data-meanwhile-theirs]');

        if (useTheirs) {
            const key = useTheirs.dataset.meanwhileTheirs;
            const input = this.body.querySelector(`[data-field="${key}"]`);
            input.value = person[key] ?? '';
            this.dirty.delete(key);
            this.seen[key] = person[key] ?? null;
            this.showError(key, null);
            this.renderMeanwhile(key, person);
            input.focus({ preventScroll: true });

            return;
        }

        const keep = target.closest('[data-problem-keep]');

        if (keep) {
            this.context.queue.keepMine(keep.dataset.problemKeep);
            this.dirty.clear();

            return;
        }

        const theirs = target.closest('[data-problem-theirs]');

        if (theirs) {
            this.context.queue.dismiss(theirs.dataset.problemTheirs);
            this.dirty.clear();
            this.sync(true);

            return;
        }

        if (target.closest('[data-unlink]')) {
            const error = this.perform(linkProfile(this.model, person.id, null, this.builderOptions()));

            if (error !== null) {
                this.context.announce(error);
            } else {
                this.context.announce(this.say('editor_unlinked'));
                this.body.querySelector('[data-player-search]').hidden = false;
                this.body.querySelector('[data-player-input]')?.focus();
            }

            return;
        }

        const option = target.closest('[data-player-option]');

        if (option) {
            this.pickPlayer(Number(option.dataset.playerOption));

            return;
        }

        const registration = target.closest('[data-registration]');

        if (registration) {
            performRegistrationAction(this.context, person.id, registration.dataset.registration);

            return;
        }

        if (target.closest('[data-remove]')) {
            const action = removePeople(this.model, [person.id], this.builderOptions());
            this.errors.removed = this.perform(action) ?? undefined;

            if (action.groups.length > 0) {
                this.context.announce(this.sayCount('removed_count', 1));
            }

            this.renderSection('remove', this.removeHtml(this.model.person(person.id)));
            this.dialog.querySelector('[data-restore], [data-remove]')?.focus({ preventScroll: true });

            return;
        }

        if (target.closest('[data-restore]')) {
            const action = restorePeople(this.model, [person.id], this.builderOptions());
            this.errors.removed = this.perform(action) ?? undefined;

            if (action.groups.length > 0) {
                this.context.announce(this.sayCount('restored_count', 1));
            }

            this.renderSection('remove', this.removeHtml(this.model.person(person.id)));
            this.dialog.querySelector('[data-remove], [data-restore]')?.focus({ preventScroll: true });
        }
    }

    onKeyDown(event) {
        const target = event.target;

        if (target.matches?.('[data-player-input]') && this.onSearchKey(event)) {
            return;
        }

        if (event.key === 'Enter' && target.matches?.('input[data-field]')) {
            event.preventDefault();
            this.saveText(target.dataset.field, target.value);

            return;
        }

        if (event.key !== 'Escape') {
            return;
        }

        if (target.matches?.('[data-field]') && TEXT_FIELDS.includes(target.dataset.field) && this.dirty.has(target.dataset.field)) {
            // The first Esc puts the typed value back, the second one closes
            event.preventDefault();
            event.stopPropagation();
            target.value = this.model.person(this.personId)?.[target.dataset.field] ?? '';
            this.dirty.delete(target.dataset.field);
            this.showError(target.dataset.field, null);

            return;
        }

        if (!this.phone) {
            // A non-modal dialog gets no `cancel` event
            event.preventDefault();
            this.close();
        }
    }

    /** What the organiser sees when a control gets the focus - sent as `from` when it changes. */
    onFocusIn(event) {
        const person = this.model.person(this.personId);
        const target = event.target;
        const field = target.closest?.('[data-field]');

        if (person === null) {
            return;
        }

        if (field && !this.dirty.has(field.dataset.field)) {
            this.seen[field.dataset.field] = person[field.dataset.field] ?? null;
        } else if (target.matches?.('[data-round-switch], [data-round-team]')) {
            const roundId = target.dataset.roundSwitch ?? target.dataset.roundTeam;
            this.seenPlace[roundId] = this.model.placeValue(person.id, roundId);
        } else if (target.matches?.('[data-player-input]')) {
            this.seen.player = person.player?.id ?? null;
        }
    }

    onFocusOut() {
        if (this.stale.size === 0) {
            return;
        }

        // A section that waited for the focus to leave catches up
        setTimeout(() => {
            if (this.dialog?.open && this.personId !== null && this.stale.size > 0) {
                this.sync();
            }
        }, 0);
    }

    // ---------------------------------------------------------------- the profile search

    queueSearch(text) {
        clearTimeout(this.search.timer);
        this.search.timer = setTimeout(() => this.runSearch(text), SEARCH_DELAY_MS);
    }

    async runSearch(text) {
        const hint = this.body.querySelector('[data-player-hint]');
        const query = text.trim();
        const request = ++this.search.request;

        if (query.length < 2) {
            this.closeResults();
            hint.textContent = query.length > 0 ? this.t('people_profile_min_chars') : '';

            return;
        }

        hint.textContent = this.say('editor_searching');
        this.search.controller?.abort();
        this.search.controller = typeof AbortController === 'undefined' ? null : new AbortController();
        const found = await searchPlayers(this.context, this.model, query, this.personId, this.search.controller?.signal);

        if (request !== this.search.request || !this.dialog?.open) {
            return;
        }

        if (found === null) {
            this.closeResults();
            hint.textContent = this.t('people_profile_search_failed');

            return;
        }

        this.search.results = found;
        this.search.active = found.length > 0 ? 0 : -1;
        hint.textContent = found.length === 0 ? this.t('people_profile_none') : this.sayCount('editor_results', found.length);
        this.renderResults();
    }

    renderResults() {
        const list = this.body.querySelector(`#${this.id}-player-list`);
        const input = this.body.querySelector('[data-player-input]');
        list.innerHTML = this.search.results.map((player, index) => `<li role="option" id="${this.id}-player-${index}" class="sheet-person-result${index === this.search.active ? ' is-active' : ''}" aria-selected="${index === this.search.active ? 'true' : 'false'}" data-player-option="${index}">
                ${player.country ? `<span class="fi fi-${escapeHtml(player.country)} shadow-custom" aria-hidden="true"></span> ` : ''}${escapeHtml(player.name)}
                <small class="text-body-secondary">${escapeHtml([player.code ? `#${String(player.code).toUpperCase()}` : '', player.countryLabel, player.linkedTo ? this.t('people_profile_linked_to', { name: player.linkedTo }) : ''].filter(Boolean).join(' · '))}</small>
            </li>`).join('');
        list.hidden = this.search.results.length === 0;
        input.setAttribute('aria-expanded', list.hidden ? 'false' : 'true');

        if (this.search.active >= 0) {
            input.setAttribute('aria-activedescendant', `${this.id}-player-${this.search.active}`);
        } else {
            input.removeAttribute('aria-activedescendant');
        }
    }

    closeResults() {
        clearTimeout(this.search.timer);
        this.search.request++;
        this.search.results = [];
        this.search.active = -1;
        const list = this.body?.querySelector(`#${this.id}-player-list`);
        const input = this.body?.querySelector('[data-player-input]');

        if (list) {
            list.hidden = true;
            list.innerHTML = '';
        }

        if (input) {
            input.setAttribute('aria-expanded', 'false');
            input.removeAttribute('aria-activedescendant');
        }
    }

    onSearchKey(event) {
        const count = this.search.results.length;

        if ((event.key === 'ArrowDown' || event.key === 'ArrowUp') && count > 0) {
            event.preventDefault();
            this.search.active = (this.search.active + (event.key === 'ArrowDown' ? 1 : -1) + count) % count;
            this.renderResults();

            return true;
        }

        if (event.key === 'Enter') {
            event.preventDefault();

            if (this.search.active >= 0) {
                this.pickPlayer(this.search.active);
            }

            return true;
        }

        if (event.key === 'Escape' && count > 0) {
            event.preventDefault();
            event.stopPropagation();
            this.closeResults();

            return true;
        }

        return false;
    }

    pickPlayer(index) {
        const found = this.search.results[index];
        const person = this.model.person(this.personId);

        if (!found || person === null) {
            return;
        }

        const error = this.perform(linkProfile(this.model, person.id, found.player, 'player' in this.seen ? { ...this.builderOptions(), from: this.seen.player } : this.builderOptions()));
        const input = this.body.querySelector('[data-player-input]');

        if (error !== null) {
            this.body.querySelector('[data-player-hint]').textContent = error;
            this.context.announce(error);

            return;
        }

        input.value = '';
        this.closeResults();
        this.body.querySelector('[data-player-hint]').textContent = '';
        this.context.announce(this.say('editor_linked', { name: found.name }));
        this.sync();
        this.body.querySelector('[data-section="player"] [data-focus-key]')?.focus({ preventScroll: true });
    }
}
