/**
 * The People tab on a phone (< 768 px - contract O13, D4; docs/features/competitions-management/participants-spreadsheet.md
 * §8): no grid - a search and a filter select at the top, the registration counters of a managed event, "+ Add a person"
 * (a name field + Add), and one card per person (name, flag, "Solo · Pairs: Pinecones · Teams", problems in words: "In
 * Pairs, no pair yet", "On the waitlist", "Same name as …"). A tap opens the person editor full screen, with previous/next
 * through the list as filtered.
 *
 * A long list stays fast: cards are rendered in chunks of 50 ("Show 50 more"), an update re-renders only the cards of
 * the people it names (the list when who is shown changed). Native controls, 44 px targets, no sideways scroll.
 */

import { escapeHtml } from '../sheet_grid.js';
import { addPerson } from '../sheet_changes.js';
import { parsePlace } from '../sheet_model.js';
import { performRegistrationAction, registrationStatus } from '../registration_actions.js';
import { FILTERS, filterCounts, offeredFor, reasonFor, registrationSummaryHtml, visiblePeople } from './people_view.js';

export const CHUNK = 50;
const SEARCH_DELAY_MS = 150;

export default function createPeopleListView(context) {
    return new PeopleListView(context);
}

export class PeopleListView {
    constructor(context) {
        this.context = context;
        this.model = context.model;
        this.core = context.texts.core;
        this.people = context.texts.people;
        this.competition = this.model.competition ?? {};
        this.filter = 'all';
        this.query = '';
        this.limit = CHUNK;
        this.held = new Set();
        this.ids = [];
        this.cards = new Map();
        this.cleanups = [];
        this.searchTimer = null;
        this.frame = null;
        this.openId = null;
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

    get managed() {
        return this.competition.registrationManaged === true;
    }

    // ---------------------------------------------------------------- the view interface

    render() {
        const root = this.context.root;
        root.classList.add('sheet-view', 'sheet-view-people-list');
        root.innerHTML = `<div class="sheet-plist">
                <div class="sheet-plist-top">
                    <input type="search" class="form-control" data-plist-search autocomplete="off" spellcheck="false" enterkeyhint="search"
                           placeholder="${escapeHtml(this.say('search_placeholder'))}" aria-label="${escapeHtml(this.say('search_label'))}">
                    <select class="form-select" data-plist-filter aria-label="${escapeHtml(this.say('filters_label'))}"></select>
                </div>
                <div class="sheet-people-registration" data-plist-registration${this.managed ? '' : ' hidden'}></div>
                <form class="sheet-plist-add" data-plist-add novalidate>
                    <label class="visually-hidden" for="sheet-plist-new">${escapeHtml(this.say('add_label'))}</label>
                    <input type="text" class="form-control" id="sheet-plist-new" data-plist-new autocomplete="off" spellcheck="false" enterkeyhint="done"
                           placeholder="${escapeHtml(this.say('add_placeholder'))}">
                    <button type="submit" class="btn btn-primary">${escapeHtml(this.say('add_button'))}</button>
                </form>
                <div class="sheet-plist-added" data-plist-added hidden></div>
                <p class="sheet-plist-count small text-body-secondary" data-plist-count></p>
                <ul class="sheet-plist-list list-unstyled" data-plist-list></ul>
                <button type="button" class="btn btn-outline-secondary sheet-plist-more" data-plist-more hidden></button>
            </div>`;

        this.searchInput = root.querySelector('[data-plist-search]');
        this.filterSelect = root.querySelector('[data-plist-filter]');
        this.registrationElement = root.querySelector('[data-plist-registration]');
        this.list = root.querySelector('[data-plist-list]');
        this.more = root.querySelector('[data-plist-more]');
        this.countElement = root.querySelector('[data-plist-count]');
        this.addedElement = root.querySelector('[data-plist-added]');

        this.listen(this.searchInput, 'input', () => {
            clearTimeout(this.searchTimer);
            this.searchTimer = setTimeout(() => this.setFilter(this.filter, this.searchInput.value), SEARCH_DELAY_MS);
        });
        this.listen(this.filterSelect, 'change', () => this.setFilter(this.filterSelect.value, this.query));
        this.listen(root.querySelector('[data-plist-add]'), 'submit', (event) => {
            event.preventDefault();
            this.add();
        });
        this.listen(this.list, 'click', (event) => {
            const card = event.target.closest('[data-open]');

            if (card) {
                this.openEditor(card.dataset.open);
            }
        });
        this.listen(this.addedElement, 'click', (event) => {
            const button = event.target.closest('[data-open]');

            if (button) {
                this.openEditor(button.dataset.open);
            }
        });
        this.listen(this.registrationElement, 'click', (event) => {
            const button = event.target.closest('[data-promote]');

            if (button) {
                performRegistrationAction(this.context, button.dataset.promote, 'promote');
            }
        });
        this.listen(this.more, 'click', () => {
            const first = this.ids[this.limit] ?? null;
            this.limit += CHUNK;
            this.renderList();

            // The first new card gets the focus - the button moved below it
            if (first !== null) {
                this.list.querySelector(`[data-open="${CSS.escape(first)}"]`)?.focus();
            }
        });

        this.ids = this.visibleIds();
        this.renderControls();
        this.renderList();
    }

    update(delta) {
        const ids = this.visibleIds();
        const changedList = ids.length !== this.ids.length || ids.some((id, index) => this.ids[index] !== id);
        this.ids = ids;

        if (changedList || delta.all) {
            this.renderList();
        } else {
            const people = new Set(delta.people);

            for (const teamId of delta.teams) {
                this.model.membersOf(teamId).forEach((person) => people.add(person.id));
            }

            for (const roundId of delta.rounds) {
                this.model.peopleIn(roundId).forEach((person) => people.add(person.id));
            }

            for (const id of people) {
                this.renderCard(id);
            }
        }

        cancelAnimationFrame(this.frame);
        this.frame = requestAnimationFrame(() => this.renderControls());
    }

    /** A jump to a person (another tab, the problems panel): their editor opens. */
    focus(target = null) {
        if (target?.personId && this.model.person(target.personId) !== null) {
            this.openEditor(target.personId);
        }
    }

    reveal(problem) {
        const [kind, id] = String(problem?.target?.key ?? '').split(':');

        if ((kind === 'person' || kind === 'place') && this.model.person(id) !== null) {
            this.openEditor(id);

            return true;
        }

        return false;
    }

    destroy() {
        clearTimeout(this.searchTimer);
        cancelAnimationFrame(this.frame);
        this.cleanups.forEach((cleanup) => cleanup());
        this.cleanups = [];
        this.context.root.replaceChildren();
    }

    listen(target, type, handler) {
        target.addEventListener(type, handler);
        this.cleanups.push(() => target.removeEventListener(type, handler));
    }

    // ---------------------------------------------------------------- filter, search, list

    visibleIds() {
        return visiblePeople(this.model, this.filter, this.query, this.held);
    }

    setFilter(filter, query) {
        this.filter = offeredFor(FILTERS, this.competition).some((item) => item.key === filter) ? filter : 'all';
        this.query = String(query ?? '');
        this.held = new Set(this.openId ? [this.openId] : []);
        this.limit = CHUNK;
        this.ids = this.visibleIds();
        this.renderList();
        this.renderControls();
        this.context.announce(this.sayCount('shown_count', this.ids.length));
    }

    renderControls() {
        const filters = offeredFor(FILTERS, this.competition);
        const counts = filterCounts(this.model, filters, this.query);
        const html = filters
            .filter(({ key }) => key === 'all' || key === this.filter || counts[key] > 0 || !['joined', 'duplicates', 'removed', 'checked_in'].includes(key))
            .map(({ key }) => `<option value="${escapeHtml(key)}"${key === this.filter ? ' selected' : ''}>${escapeHtml(this.say('filter_option', { filter: this.say(`filter_${key}`), count: counts[key] }))}</option>`)
            .join('');

        if (this.filterSelect.dataset.html !== html && document.activeElement !== this.filterSelect) {
            this.filterSelect.innerHTML = html;
            this.filterSelect.dataset.html = html;
        }

        if (this.managed) {
            const summary = registrationSummaryHtml(this.model, this.competition, (key, params) => this.say(key, params), (key, count, params) => this.sayCount(key, count, params));

            if (this.registrationElement.dataset.html !== summary) {
                this.registrationElement.innerHTML = summary;
                this.registrationElement.dataset.html = summary;
            }
        }
    }

    renderList() {
        const shown = this.ids.slice(0, this.limit);
        this.cards = new Map();
        this.list.innerHTML = shown.map((id) => {
            const html = this.cardHtml(id);
            this.cards.set(id, html);

            return `<li data-card="${escapeHtml(id)}">${html}</li>`;
        }).join('');

        const rest = this.ids.length - shown.length;
        this.more.hidden = rest <= 0;
        this.more.textContent = rest > 0 ? this.sayCount('list_more', Math.min(CHUNK, rest)) : '';
        this.countElement.textContent = rest > 0 ? this.say('list_count_partial', { shown: shown.length, count: this.ids.length }) : this.sayCount('shown_count', this.ids.length);

        if (this.ids.length === 0) {
            this.list.innerHTML = `<li class="sheet-plist-empty">${escapeHtml(this.say(this.query ? 'list_empty_search' : 'list_empty'))}</li>`;
        }
    }

    renderCard(id) {
        if (!this.cards.has(id)) {
            return;
        }

        const html = this.cardHtml(id);

        if (this.cards.get(id) === html) {
            return;
        }

        const item = this.list.querySelector(`[data-card="${CSS.escape(id)}"]`);

        if (item === null) {
            return;
        }

        const focused = item.contains(document.activeElement);
        item.innerHTML = html;
        this.cards.set(id, html);

        if (focused) {
            item.querySelector('[data-open]')?.focus();
        }
    }

    /** A person's card: name and flag, the rounds in words, the problems in words. */
    cardHtml(id) {
        const person = this.model.person(id);

        if (person === null) {
            return '';
        }

        const summary = [];
        const problems = [];

        for (const round of this.model.rounds()) {
            const place = parsePlace(this.model.placeValue(id, round.id));

            if (place.kind === 'out') {
                continue;
            }

            if (round.category === 'solo') {
                summary.push(round.name);
            } else if (place.kind === 'in') {
                summary.push(round.name);
                problems.push(this.say(round.category === 'duo' ? 'card_no_pair' : 'card_no_team', { round: round.name }));
            } else {
                const team = this.model.team(place.teamId);
                summary.push(this.say('card_round_team', { round: round.name, team: team?.name ?? this.t('team_no_name') }));
            }
        }

        if (registrationStatus(person) === 'waitlisted') {
            problems.push(this.t('people_badge_waitlisted'));
        }

        if (person.removedAt === null) {
            const same = this.model.peopleNamed(person.name).filter((other) => other.id !== id);

            if (same.length > 0) {
                problems.push(this.say('same_name_as', { name: same.map((other) => other.name).join(', ') }));
            }
        }

        const flag = person.country ? ` <span class="fi fi-${escapeHtml(person.country)} shadow-custom" role="img" aria-label="${escapeHtml(this.context.countries[person.country] ?? person.country.toUpperCase())}"></span>` : '';
        const removed = person.removedAt !== null ? ` <span class="sheet-badge sheet-badge-removed">${escapeHtml(this.say('badge_removed'))}</span>` : '';
        const joined = person.source === 'self_joined' ? ` <span class="sheet-badge sheet-badge-joined">${escapeHtml(this.t('people_badge_joined'))}</span>` : '';
        const marks = this.model.marks.withPrefix(`person:${id}:`).concat(this.model.marks.withPrefix(`place:${id}:`));
        const attention = marks.find((mark) => mark.state === 'conflict' || mark.state === 'refused');
        const saving = marks.some((mark) => mark.state === 'saving' || mark.state === 'waiting');

        return `<button type="button" class="sheet-pcard${person.removedAt !== null ? ' is-removed' : ''}" data-open="${escapeHtml(id)}">
                <span class="sheet-pcard-name">${person.removedAt !== null ? `<s>${escapeHtml(person.name)}</s>` : escapeHtml(person.name)}${flag}${removed}${joined}</span>
                <span class="sheet-pcard-summary">${summary.length > 0 ? escapeHtml(summary.join(' · ')) : escapeHtml(this.say('no_round_yet'))}</span>
                ${problems.length > 0 ? `<span class="sheet-pcard-problems"><i class="bi bi-exclamation-triangle" aria-hidden="true"></i> ${escapeHtml(problems.join(' · '))}</span>` : ''}
                ${attention ? `<span class="sheet-pcard-problems sheet-pcard-refused"><i class="bi bi-exclamation-octagon" aria-hidden="true"></i> ${escapeHtml(this.context.markerFor(attention.key)?.text ?? '')}</span>` : ''}
                ${saving ? `<span class="visually-hidden">${escapeHtml(this.t('marker_saving'))}</span>` : ''}
                <i class="bi bi-chevron-right sheet-pcard-go" aria-hidden="true"></i>
            </button>`;
    }

    // ---------------------------------------------------------------- adding, opening

    add() {
        const input = this.context.root.querySelector('[data-plist-new]');
        const name = input.value.trim();

        if (name === '') {
            input.focus();

            return;
        }

        const action = addPerson(this.model, { name }, { countries: this.context.countryCodes });
        this.held.add(action.personId);
        const outcome = this.context.act(action, { quiet: true });

        if (!outcome.performed) {
            this.held.delete(action.personId);
            this.context.announce(reasonFor(this.context, action.errors[0]));

            return;
        }

        input.value = '';
        input.focus();

        // The new card is shown even when the list is longer than what is rendered
        const index = this.ids.indexOf(action.personId);

        if (index >= this.limit) {
            this.limit = index + 1;
            this.renderList();
        }

        const same = this.model.peopleNamed(name).filter((other) => other.id !== action.personId);
        const text = same.length > 0 ? `${this.t('people_added', { name })} ${this.t('people_same_name', { name: same[0].name })}` : this.t('people_added', { name });
        this.addedElement.innerHTML = `<span>${escapeHtml(text)}</span> <button type="button" class="btn btn-sm btn-link" data-open="${escapeHtml(action.personId)}">${escapeHtml(this.say('add_open', { name }))}</button>`;
        this.addedElement.hidden = false;
        this.context.announce(text);
    }

    openEditor(personId) {
        this.openId = personId;
        this.held.add(personId);
        this.context.openPersonEditor(personId, {
            list: () => this.visibleIds(),
            onShow: (id) => {
                this.openId = id;

                if (id !== null) {
                    this.held.add(id);
                }
            },
            returnFocus: (id) => {
                // The card of the person shown last (previous/next moved on) - rendered if it was beyond the chunk
                const index = this.ids.indexOf(id);

                if (index >= this.limit) {
                    this.limit = Math.ceil((index + 1) / CHUNK) * CHUNK;
                    this.renderList();
                }

                const card = this.list.querySelector(`[data-open="${CSS.escape(id)}"]`);

                if (card) {
                    card.focus();
                    card.scrollIntoView({ block: 'nearest' });
                }
            },
        });
    }
}
