/**
 * A round tab on a phone (< 768 px, O13; docs/features/competitions-management/participants-spreadsheet.md §8) - no
 * grid, lists:
 *
 * - pair/team rounds: the problem counts, the "Without a pair" tray first (tap a person: Pair with… / Add to… / New
 *   pair / Not in this round), then a card per pair/team - "Table 3 · Pinecones · 2/2" with the size in words, member
 *   chips with ✕ (→ the tray), "+ Add partner" (a full-screen search with the same options as the desktop member cell,
 *   `+ Add "Jo Do" as a new participant` included), a ⋯ menu (Rename, Delete, Take out of the round), the result
 *   read-only with the round's Live entry link - and "+ New pair/team" at the end;
 * - solo rounds: the round's people (table, name, flag, result read-only), "Add people to this round", "Take out".
 *
 * 44 px targets, native controls (buttons, `<dialog>`, inputs), nothing drag-only, no horizontal page scroll at 375 px.
 * Every change is the same action as on a desktop (sheet_changes.js through `context.act()`).
 */

import { escapeHtml } from '../sheet_grid.js';
import { buildAction, clearMember, deleteTeam, newTeamRow, renameTeam, setPlace } from '../sheet_changes.js';
import { IN, OUT, cleanName, hasOfficialData, parsePlace, teamPlace } from '../sheet_model.js';
import { newClientId } from '../../official_results_api.js';
import {
    RoundDialog,
    RoundResultsCells,
    flagHtml,
    keepOrder,
    nameCollator,
    personOptions,
    roundEntries,
    safeColor,
    sizeInfo,
    sortedPeopleIds,
    sortedTeamIds,
    teamLabelText,
    teamOptions,
    teamShortLabel,
    usesTables,
} from '../round/round_common.js';
import { resultText, roundRanks } from '../sheet_results.js';

export default function createRoundCardsView(context) {
    return new RoundCardsView(context);
}

export class RoundCardsView {
    constructor(context) {
        this.context = context;
        this.model = context.model;
        this.roundId = context.round.id;
        this.texts = context.texts.round;
        this.core = context.texts.core;
        this.results = new RoundResultsCells(context);
        this.collator = nameCollator(context.locale);
        this.order = [];
        this.cards = new Map();
        this.listeners = [];
        this.dialog = null;
    }

    t(key, params) {
        return this.texts.t(key, params);
    }

    tc(key, count, params) {
        return this.texts.tc(key, count, params);
    }

    solo() {
        return this.model.round(this.roundId)?.category === 'solo';
    }

    tk(key, params) {
        return this.t(`${key}_${this.model.round(this.roundId)?.category === 'duo' ? 'pair' : 'team'}`, params);
    }

    // ---------------------------------------------------------------- the view interface

    render() {
        const root = this.context.root;
        root.classList.add('sheet-view', 'sheet-view-cards');
        this.head = document.createElement('div');
        this.head.className = 'sheet-cards-head';
        this.trayElement = document.createElement('section');
        this.trayElement.className = 'sheet-cards-tray';
        this.trayElement.setAttribute('aria-labelledby', `sheet-cards-tray-${this.roundId}`);
        this.list = document.createElement('ul');
        this.list.className = 'sheet-cards list-unstyled';
        this.list.setAttribute('aria-label', this.solo() ? this.t('cards_people_label') : this.tk('cards_label'));
        this.foot = document.createElement('div');
        this.foot.className = 'sheet-cards-foot';
        root.replaceChildren(this.head, this.trayElement, this.list, this.foot);
        this.trayElement.hidden = this.solo();
        this.cards = new Map();
        this.order = this.solo() ? sortedPeopleIds(this.model, this.roundId, this.collator) : sortedTeamIds(this.model, this.roundId, this.collator);
        this.on(root, 'click', (event) => this.onClick(event));
        this.renderAll();
    }

    renderAll() {
        this.renderHead();
        this.renderTray();
        this.renderList(null);
        this.renderFoot();
    }

    update(delta) {
        if (this.model.round(this.roundId) === null) {
            return;
        }

        if (delta.all) {
            this.renderAll();

            return;
        }

        const touched = delta.rounds.has(this.roundId) || delta.people.size > 0 || delta.teams.size > 0;

        if (!touched) {
            return;
        }

        const ids = this.solo() ? this.model.peopleIn(this.roundId).map((person) => person.id) : this.model.teamsOf(this.roundId).map((team) => team.id);
        this.order = keepOrder(this.order, ids);
        this.renderHead();
        this.renderTray();
        // Only the cards whose markup changed are replaced (the html of each is compared)
        this.renderList(delta);
        this.renderFoot();
    }

    focus(target = null) {
        const key = target?.teamId ? `menu:${target.teamId}` : (target?.personId ? (this.solo() ? `out:${target.personId}` : `chip:${target.personId}`) : null);
        const element = key ? this.context.root.querySelector(`[data-key="${CSS.escape(key)}"]`) : null;

        if (element) {
            element.scrollIntoView({ block: 'nearest' });
            element.focus();
        }
    }

    reveal(problem) {
        const key = problem?.target?.key ?? '';

        if (key.startsWith('team:')) {
            this.focus({ teamId: key.split(':')[1] });

            return true;
        }

        if (key.startsWith('place:')) {
            const personId = key.split(':')[1];
            this.focus({ personId, teamId: parsePlace(this.model.placeValue(personId, this.roundId)).teamId });

            return true;
        }

        return false;
    }

    destroy() {
        this.dialog?.close();
        this.listeners.forEach((remove) => remove());
        this.listeners = [];
        this.context.root.replaceChildren();
    }

    on(target, type, handler, options) {
        target.addEventListener(type, handler, options);
        this.listeners.push(() => target.removeEventListener(type, handler, options));
    }

    /** innerHTML replaced only when it changed; the focused control is found again by its data-key. */
    patch(element, html) {
        if (element.dataset.html === html) {
            return;
        }

        const focused = element.contains(document.activeElement) ? document.activeElement.closest('[data-key]')?.dataset.key ?? null : null;
        element.innerHTML = html;
        element.dataset.html = html;

        if (focused) {
            element.querySelector(`[data-key="${CSS.escape(focused)}"]`)?.focus({ preventScroll: true });
        }
    }

    // ---------------------------------------------------------------- rendering

    renderHead() {
        const round = this.model.round(this.roundId);
        const urls = round.urls ?? {};
        const parts = [];

        if (this.solo()) {
            parts.push(this.core.tc('tab_count_people', this.model.peopleIn(this.roundId).length));
        } else {
            const problems = this.model.problems(this.roundId);
            parts.push(this.core.tc(round.category === 'duo' ? 'tab_count_pairs' : 'tab_count_teams', this.model.teamsOf(this.roundId).length));

            if (problems.incomplete > 0) {
                parts.push(`⚠ ${this.tc('filter_incomplete', problems.incomplete)}`);
            }

            if (problems.tooMany > 0) {
                parts.push(`⚠ ${this.tc('filter_too_many', problems.tooMany)}`);
            }

            if (problems.sameName > 0) {
                parts.push(this.tc('filter_same_name', problems.sameName));
            }
        }

        const live = typeof urls.liveEntry === 'string' && urls.liveEntry !== ''
            ? `<a class="btn btn-outline-secondary sheet-cards-button" href="${escapeHtml(urls.liveEntry)}" target="_blank" rel="noopener"><i class="bi bi-broadcast" aria-hidden="true"></i> ${escapeHtml(this.t('link_live_entry'))}<span class="visually-hidden"> ${escapeHtml(this.t('new_tab'))}</span></a>`
            : '';

        this.patch(this.head, `<p class="sheet-cards-summary mb-2"><span class="sheet-round-swatch" style="background-color:${escapeHtml(safeColor(round.color))}" aria-hidden="true"></span> <strong>${escapeHtml(round.name)}</strong> · ${escapeHtml(parts.join(' · '))}</p>
            <div class="sheet-cards-tools">${this.solo() ? `<button type="button" class="btn btn-primary sheet-cards-button" data-key="add-people" data-action="add-people"><i class="bi bi-person-plus" aria-hidden="true"></i> ${escapeHtml(this.t('add_people'))}</button>` : ''}${live}</div>`);
    }

    renderTray() {
        if (this.solo()) {
            return;
        }

        const tray = this.model.trayOf(this.roundId);
        const chips = tray.map((person) => `<li><button type="button" class="sheet-chip sheet-chip-large" data-key="chip:${escapeHtml(person.id)}" data-action="chip" data-person="${escapeHtml(person.id)}" aria-haspopup="dialog">${flagHtml(person.country)}${escapeHtml(person.name)}${this.model.isWaitlisted(person.id) ? ` <span class="sheet-badge sheet-badge-waitlist">${escapeHtml(this.t('waitlisted'))}</span>` : ''}</button></li>`).join('');

        this.patch(this.trayElement, `<h2 class="h6 mb-2" id="sheet-cards-tray-${escapeHtml(this.roundId)}">${escapeHtml(this.tc(this.model.round(this.roundId)?.category === 'duo' ? 'tray_short_pair' : 'tray_short_team', tray.length))}</h2>
            ${tray.length > 0 ? `<ul class="sheet-chips list-unstyled">${chips}</ul>` : `<p class="sheet-muted small mb-2">${escapeHtml(this.tk('tray_empty'))}</p>`}
            <button type="button" class="btn btn-outline-secondary sheet-cards-button" data-key="tray-add" data-action="add-people"><i class="bi bi-person-plus" aria-hidden="true"></i> ${escapeHtml(this.t('add_people'))}</button>`);
    }

    renderFoot() {
        if (this.solo()) {
            this.patch(this.foot, '');

            return;
        }

        this.patch(this.foot, `<button type="button" class="btn btn-primary sheet-cards-button w-100" data-key="new" data-action="new-team"><i class="bi bi-plus-lg" aria-hidden="true"></i> ${escapeHtml(this.tk('new_card'))}</button>`);
    }

    renderList() {
        const ids = this.order.filter((id) => (this.solo() ? this.model.placeValue(id, this.roundId) !== OUT && this.model.person(id)?.removedAt === null : this.model.team(id)?.roundId === this.roundId));
        const ranks = roundRanks(roundEntries(this.model, this.roundId, this.results.pending(), this.texts));
        const wanted = new Set(ids);

        for (const [id, element] of this.cards) {
            if (!wanted.has(id)) {
                element.remove();
                this.cards.delete(id);
            }
        }

        let previous = null;

        for (const id of ids) {
            const html = this.solo() ? this.personHtml(id, ranks) : this.teamHtml(id, ranks);
            let element = this.cards.get(id);

            if (element === undefined) {
                element = document.createElement('li');
                element.className = 'sheet-card';
                this.cards.set(id, element);
            }

            this.patch(element, html);
            const expected = previous === null ? this.list.firstElementChild : previous.nextElementSibling;

            if (expected !== element) {
                this.list.insertBefore(element, expected);
            }

            previous = element;
        }

        if (ids.length === 0) {
            this.list.dataset.empty = this.solo() ? this.t('cards_people_empty') : this.tk('cards_empty');
        } else {
            delete this.list.dataset.empty;
        }
    }

    resultLine(ref, server, rank) {
        const round = this.model.round(this.roundId);
        const value = this.results.shown(ref, 'result', server);

        if ((value === null || value === undefined) && round?.started !== true) {
            return '';
        }

        const text = resultText(value, round?.piecesCount ?? null, this.results.resultTexts()) || this.t('result_preview_none');
        const rankText = rank ? ` · ${this.t('rank_short', { rank })}` : '';

        return `<p class="sheet-card-result small mb-0"><span class="sheet-muted">${escapeHtml(this.t('col_result'))}:</span> ${escapeHtml(text)}${escapeHtml(rankText)}</p>`;
    }

    teamHtml(teamId, ranks) {
        const team = this.model.team(teamId);
        const size = sizeInfo(this.model, teamId, this.texts);
        const shownTable = usesTables(this.model, this.model.round(this.roundId)) ? this.results.shown(`team:${teamId}`, 'table_number', team.table) : null;
        const title = teamLabelText(this.model, teamId, this.texts, { members: false, table: shownTable });
        const members = this.model.membersOf(teamId);
        const marker = (key) => {
            const mark = this.context.markerFor(key);

            return mark ? ` <span class="sheet-marker sheet-marker-${escapeHtml(mark.state)}">${escapeHtml(mark.text)}</span>` : '';
        };
        const chips = members.map((person) => `<li class="sheet-member-chip">${flagHtml(person.country)}<span>${escapeHtml(person.name)}</span>${this.model.isWaitlisted(person.id) ? ` <span class="sheet-badge sheet-badge-waitlist">${escapeHtml(this.t('waitlisted'))}</span>` : ''}${marker(`place:${person.id}:${this.roundId}`)}
                <button type="button" class="sheet-member-remove" data-key="remove:${escapeHtml(person.id)}" data-action="remove" data-person="${escapeHtml(person.id)}" data-team="${escapeHtml(teamId)}" aria-label="${escapeHtml(this.t('card_remove_member', { name: person.name, team: title }))}"><i class="bi bi-x-lg" aria-hidden="true"></i></button></li>`).join('');

        return `<div class="sheet-card-head">
                <h3 class="sheet-card-title">${escapeHtml(title)}${marker(`team:${teamId}:name`)}</h3>
                <button type="button" class="sheet-card-menu" data-key="menu:${escapeHtml(teamId)}" data-action="menu" data-team="${escapeHtml(teamId)}" aria-haspopup="dialog" aria-label="${escapeHtml(this.t('row_actions_label', { team: title }))}"><i class="bi bi-three-dots" aria-hidden="true"></i></button>
            </div>
            <p class="sheet-card-size small mb-2${size.warn ? ' sheet-attention' : ' sheet-muted'}">${size.warn ? '<i class="bi bi-exclamation-triangle" aria-hidden="true"></i> ' : ''}${escapeHtml([size.text, size.sameAs].filter(Boolean).join(' · '))}</p>
            ${members.length > 0 ? `<ul class="sheet-member-chips list-unstyled">${chips}</ul>` : ''}
            <button type="button" class="btn btn-outline-secondary sheet-cards-button" data-key="add:${escapeHtml(teamId)}" data-action="add-member" data-team="${escapeHtml(teamId)}"><i class="bi bi-plus-lg" aria-hidden="true"></i> ${escapeHtml(this.tk('add_member'))}</button>
            ${this.resultLine(`team:${teamId}`, team.result, ranks.get(teamId))}`;
    }

    personHtml(personId, ranks) {
        const person = this.model.person(personId);
        const place = this.model.place(personId, this.roundId);
        const ref = this.model.entryRef(personId, this.roundId);
        const table = usesTables(this.model, this.model.round(this.roundId)) ? this.results.shown(ref, 'table_number', place.table) : null;
        const holds = this.model.holdsDataInRound(personId, this.roundId);

        return `<div class="sheet-card-head">
                <h3 class="sheet-card-title">${table !== null && table !== undefined ? `<span class="sheet-card-table">${escapeHtml(this.t('label_table', { table }))}</span> ` : ''}${flagHtml(person.country)}${escapeHtml(person.name)}${this.model.isWaitlisted(personId) ? ` <span class="sheet-badge sheet-badge-waitlist">${escapeHtml(this.t('waitlisted'))}</span>` : ''}</h3>
                <button type="button" class="btn btn-sm btn-outline-secondary sheet-card-out" data-key="out:${escapeHtml(personId)}" data-action="out" data-person="${escapeHtml(personId)}"${holds ? ' aria-disabled="true"' : ''} aria-label="${escapeHtml(this.t('card_take_out_label', { name: person.name }))}">${escapeHtml(this.t('card_take_out'))}</button>
            </div>
            ${this.resultLine(ref, place.result, ranks.get(place.id))}`;
    }

    // ---------------------------------------------------------------- actions

    onClick(event) {
        const button = event.target.closest('[data-action]');

        if (!button || !this.context.root.contains(button)) {
            return;
        }

        const { action, person, team } = button.dataset;

        switch (action) {
            case 'chip':
                this.chipMenu(person, button);
                break;
            case 'remove':
                this.removeMember(person, team);
                break;
            case 'add-member':
                this.addMember(team, button);
                break;
            case 'menu':
                this.teamMenu(team, button);
                break;
            case 'new-team':
                this.newTeam(button);
                break;
            case 'add-people':
                this.addPeople(button);
                break;
            case 'out':
                this.takeOut(person);
                break;
            default:
        }
    }

    options() {
        return { countries: this.context.countryCodes };
    }

    reasonText(error) {
        const code = error?.reason ?? 'invalid_change';
        const change = error?.change ?? {};
        const teamId = change.team ?? parsePlace(change.to ?? '').teamId ?? null;
        const params = {
            name: this.model.person(change.participant ?? change.id ?? '')?.name ?? change.name ?? '',
            round: this.model.round(change.round ?? this.roundId)?.name ?? '',
            team: teamId ? teamShortLabel(this.model, teamId, this.texts) : '',
            max: 255,
            other: '',
        };

        return this.core.has(`reason_${code}`) ? this.core.t(`reason_${code}`, params) : this.context.reasonText(code);
    }

    /** An action through the controller; a refusal is said aloud (and shown in the problems panel by the core). */
    perform(action, success = '') {
        const outcome = this.context.act(action, { quiet: true });

        if (outcome.errors.length > 0) {
            this.context.announce(this.reasonText(outcome.errors[0]));

            return false;
        }

        if (success) {
            this.context.announce(success);
        }

        return outcome.performed;
    }

    openDialog(options) {
        this.dialog?.close();
        this.dialog = new RoundDialog({ host: this.context.root, sheet: true, closeLabel: this.t('dialog_close'), ...options }).open();

        return this.dialog.result;
    }

    /** Focus after something left the screen: the element with that key, else the next sensible one. */
    focusKey(...keys) {
        for (const key of keys) {
            const element = key ? this.context.root.querySelector(`[data-key="${CSS.escape(key)}"]`) : null;

            if (element) {
                element.focus({ preventScroll: false });

                return;
            }
        }
    }

    async chipMenu(personId, anchor) {
        const person = this.model.person(personId);

        if (person === null) {
            return;
        }

        const holds = this.model.holdsDataInRound(personId, this.roundId);
        const hasTeams = this.model.teamsOf(this.roundId).length > 0;
        const choice = await this.openDialog({
            title: person.name,
            description: this.tk('chip_description'),
            items: [
                { value: 'pair', label: this.tk('chip_pair_with'), icon: 'bi-people' },
                { value: 'add', label: this.t('chip_add_to'), icon: 'bi-box-arrow-in-right', disabled: !hasTeams, reason: hasTeams ? '' : this.tk('chip_add_to_none') },
                { value: 'new', label: this.tk('chip_new'), icon: 'bi-plus-lg' },
                { value: 'out', label: this.t('chip_out'), icon: 'bi-box-arrow-right', danger: true, disabled: holds, reason: holds ? this.reasonText({ reason: 'has_result_in_round', change: { participant: personId, round: this.roundId } }) : '' },
            ],
            returnFocus: () => this.focusKey(`chip:${personId}`, 'tray-add'),
            onDisabled: (item) => this.context.announce(item.reason),
        });

        if (choice === null) {
            return;
        }

        if (choice.value === 'pair') {
            const picked = await this.openDialog({
                title: this.tk('pair_with_title', { name: person.name }),
                picker: {
                    label: this.t('search_people'),
                    placeholder: this.t('search_people_placeholder'),
                    empty: this.tk('pair_with_empty'),
                    none: this.t('no_matches'),
                    options: (query) => personOptions(this.model, this.roundId, query, this.texts, { only: 'tray', exclude: new Set([personId]), create: false, countries: this.context.countries }).map((option) => ({ ...option, detail: '' })),
                },
                returnFocus: () => this.focusKey(`chip:${personId}`, 'tray-add'),
            });

            if (picked?.personId) {
                const action = newTeamRow(this.model, this.roundId, { members: [personId, picked.personId] }, this.options());

                if (this.perform(action, this.tk('paired', { first: person.name, second: picked.label }))) {
                    this.focusKey(`menu:${action.teamId}`);
                }
            }
        } else if (choice.value === 'add') {
            const picked = await this.openDialog({
                title: this.tk('add_to_title', { name: person.name }),
                picker: {
                    label: this.tk('search_teams'),
                    placeholder: this.tk('search_teams_placeholder'),
                    none: this.t('no_matches'),
                    options: (query) => teamOptions(this.model, this.roundId, query, this.texts),
                },
                returnFocus: () => this.focusKey(`chip:${personId}`, 'tray-add'),
            });

            if (picked?.teamId) {
                const changes = [{ op: 'place', participant: personId, round: this.roundId, from: this.model.placeValue(personId, this.roundId), to: teamPlace(picked.teamId) }];

                if (this.perform(buildAction(this.model, [changes], { label: { key: 'put_in_team' }, ...this.options() }), this.t('added_to', { name: person.name, team: picked.label }))) {
                    this.focusKey(`remove:${personId}`);
                }
            }
        } else if (choice.value === 'new') {
            const action = newTeamRow(this.model, this.roundId, { members: [personId] }, this.options());

            if (this.perform(action, this.tk('created_with', { name: person.name }))) {
                this.focusKey(`add:${action.teamId}`);
            }
        } else if (choice.value === 'out') {
            this.perform(setPlace(this.model, personId, this.roundId, OUT, { label: { key: 'round_out' }, ...this.options() }), this.t('taken_out_person', { name: person.name }));
            this.focusKey('tray-add');
        }
    }

    removeMember(personId, teamId) {
        const team = this.model.team(teamId);

        if (team === null) {
            return;
        }

        if (hasOfficialData(team) && this.model.membersOf(teamId).length <= 1) {
            this.context.announce(this.reasonText({ reason: 'team_has_result', change: { team: teamId } }));

            return;
        }

        const name = this.model.person(personId)?.name ?? '';

        if (this.perform(clearMember(this.model, this.roundId, personId, this.options()), this.tk('member_to_tray', { name }))) {
            // The card (or what is left of it) keeps the focus; the pair/team may be gone (no name, emptied)
            this.focusKey(`add:${teamId}`, `chip:${personId}`, 'tray-add');
        }
    }

    async addMember(teamId, anchor) {
        const label = teamShortLabel(this.model, teamId, this.texts);
        const picked = await this.openDialog({
            title: this.tk('add_member_title', { team: label }),
            picker: {
                label: this.t('search_people'),
                placeholder: this.t('search_people_placeholder'),
                empty: this.t('member_hint'),
                none: this.t('no_matches'),
                options: (query) => personOptions(this.model, this.roundId, query, this.texts, { teamId, countries: this.context.countries }),
            },
            returnFocus: () => this.focusKey(`add:${teamId}`),
        });

        if (picked === null) {
            return;
        }

        const changes = [];
        let personId = picked.personId ?? null;

        if (picked.create) {
            personId = newClientId();
            changes.push({ op: 'newParticipant', id: personId, name: cleanName(picked.name), country: null, externalId: null });
        }

        if (personId === null) {
            return;
        }

        const from = parsePlace(this.model.placeValue(personId, this.roundId));

        if (this.leavesResultEmpty(personId, teamId)) {
            return;
        }

        changes.push({ op: 'place', participant: personId, round: this.roundId, from: this.model.placeValue(personId, this.roundId), to: teamPlace(teamId) });
        const moves = from.kind === 'team' && from.teamId !== teamId ? this.t('moves_announce', { name: picked.label, where: teamShortLabel(this.model, from.teamId, this.texts) }) : '';
        this.perform(buildAction(this.model, [changes], { label: { key: 'put_in_team' }, ...this.options() }), [this.t('added_to', { name: picked.create ? cleanName(picked.name) : picked.label, team: label }), moves].filter(Boolean).join(' '));
        this.focusKey(`add:${teamId}`);
    }

    /** Moving the last person out of a pair/team with a result is refused (the server would too) - said aloud. */
    leavesResultEmpty(personId, teamId) {
        const from = parsePlace(this.model.placeValue(personId, this.roundId));

        if (from.kind === 'team' && from.teamId !== teamId && hasOfficialData(this.model.team(from.teamId)) && this.model.membersOf(from.teamId).length <= 1) {
            this.context.announce(this.reasonText({ reason: 'team_has_result', change: { team: from.teamId } }));

            return true;
        }

        return false;
    }

    async teamMenu(teamId, anchor) {
        const team = this.model.team(teamId);

        if (team === null) {
            return;
        }

        const label = teamShortLabel(this.model, teamId, this.texts);
        const official = hasOfficialData(team);
        const holder = this.model.membersOf(teamId).find((person) => this.model.holdsDataInRound(person.id, this.roundId)) ?? null;
        const choice = await this.openDialog({
            title: label,
            items: [
                { value: 'rename', label: this.tk('menu_rename'), icon: 'bi-pencil' },
                { value: 'delete', label: this.tk('menu_delete'), icon: 'bi-trash', detail: this.tk('menu_delete_detail'), disabled: official, reason: official ? this.reasonText({ reason: 'team_has_result', change: { team: teamId } }) : '' },
                { value: 'out', label: this.tk('menu_take_out'), icon: 'bi-box-arrow-right', danger: true, disabled: official || holder !== null, reason: official ? this.reasonText({ reason: 'team_has_result', change: { team: teamId } }) : (holder ? this.reasonText({ reason: 'has_result_in_round', change: { participant: holder.id, round: this.roundId } }) : '') },
            ],
            returnFocus: () => this.focusKey(`menu:${teamId}`, 'new'),
            onDisabled: (item) => this.context.announce(item.reason),
        });

        if (choice?.value === 'rename') {
            const answer = await this.openDialog({
                title: this.tk('menu_rename'),
                form: { label: this.tk('col_name'), value: team.name ?? '', placeholder: this.core.t('team_no_name'), submitLabel: this.t('save') },
                returnFocus: () => this.focusKey(`menu:${teamId}`),
            });

            if (answer !== null) {
                this.perform(renameTeam(this.model, teamId, answer.value, this.options()), this.t('renamed'));
            }
        } else if (choice?.value === 'delete') {
            this.perform(deleteTeam(this.model, teamId, this.options()), this.tk('deleted', { team: label }));
            this.focusKey('tray-add', 'new');
        } else if (choice?.value === 'out') {
            const members = this.model.membersOf(teamId).map((person) => person.id);
            const changes = [{ op: 'deleteTeam', team: teamId }, ...members.map((personId) => ({ op: 'place', participant: personId, round: this.roundId, from: IN, to: OUT }))];
            this.perform(buildAction(this.model, [changes], { label: { key: 'round_out' }, ...this.options() }), this.tk('taken_out', { team: label }));
            this.focusKey('new');
        }
    }

    /** "+ New pair/team": an optional name and the first person (or the name only - a Minnesota night). */
    async newTeam() {
        const picked = await this.openDialog({
            title: this.tk('new_card'),
            picker: {
                extra: { label: this.tk('new_name_label'), placeholder: this.t('new_name_placeholder') },
                label: this.t('new_first_member'),
                placeholder: this.t('search_people_placeholder'),
                empty: this.t('member_hint'),
                none: this.t('no_matches'),
                options: (query) => personOptions(this.model, this.roundId, query, this.texts, { teamId: null, countries: this.context.countries }),
                submit: { label: this.tk('new_name_only') },
            },
            returnFocus: () => this.focusKey('new'),
        });

        if (picked === null) {
            return;
        }

        const name = picked.extra ?? '';
        const members = picked.create ? [{ name: picked.name, country: null }] : (picked.personId ? [picked.personId] : []);

        if (members.length === 0 && name.trim() === '') {
            this.context.announce(this.tk('new_needs_something'));

            return;
        }

        if (picked.personId && this.leavesResultEmpty(picked.personId, null)) {
            return;
        }

        const action = newTeamRow(this.model, this.roundId, { name, members }, this.options());

        if (this.perform(action, this.tk('created', { name: name.trim() || this.core.t('team_no_name') }))) {
            this.focusKey(`add:${action.teamId}`);
        }
    }

    async addPeople() {
        const picked = await this.openDialog({
            title: this.t('add_people_title', { round: this.model.round(this.roundId)?.name ?? '' }),
            picker: {
                label: this.t('search_people'),
                placeholder: this.t('search_people_placeholder'),
                empty: this.t('add_people_hint'),
                none: this.t('no_matches'),
                options: (query) => (query.trim() === '' ? [] : personOptions(this.model, this.roundId, query, this.texts, { only: 'out', countries: this.context.countries })),
            },
            returnFocus: () => this.focusKey('add-people', 'tray-add'),
        });

        if (picked === null) {
            return;
        }

        if (picked.create) {
            const id = newClientId();
            const changes = [
                { op: 'newParticipant', id, name: cleanName(picked.name), country: null, externalId: null },
                { op: 'place', participant: id, round: this.roundId, from: OUT, to: IN },
            ];
            this.perform(buildAction(this.model, [changes], { label: { key: 'add_person' }, ...this.options() }), this.t('added_new_to_round', { name: cleanName(picked.name) }));
        } else if (picked.personId) {
            this.perform(setPlace(this.model, picked.personId, this.roundId, IN, { label: { key: 'round_in' }, ...this.options() }), this.t('added_to_round', { name: picked.label }));
        }
    }

    takeOut(personId) {
        const person = this.model.person(personId);

        if (person === null) {
            return;
        }

        if (this.model.holdsDataInRound(personId, this.roundId)) {
            this.context.announce(this.reasonText({ reason: 'has_result_in_round', change: { participant: personId, round: this.roundId } }));

            return;
        }

        const index = this.order.indexOf(personId);
        const next = this.order.slice(index + 1).find((id) => this.cards.has(id)) ?? this.order.slice(0, index).reverse().find((id) => this.cards.has(id));

        if (this.perform(setPlace(this.model, personId, this.roundId, OUT, { label: { key: 'round_out' }, ...this.options() }), this.t('taken_out_person', { name: person.name }))) {
            this.focusKey(next ? `out:${next}` : null, 'add-people');
        }
    }
}
