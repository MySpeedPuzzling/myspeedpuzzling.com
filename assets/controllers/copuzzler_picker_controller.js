import { Controller } from '@hotwired/stimulus';

/**
 * Solo / Pair / Team picker of the add/edit time form - docs/features/pairs-and-teams/README.md.
 *
 * Rules this controller exists to keep:
 *  - It never submits, re-renders or navigates: the surrounding form must not notice it at all.
 *  - The hidden group_players[] inputs are the single source of truth and syncInputs() is their only
 *    writer. Everything is rebuilt from them on connect, so a 422 re-render or a restored page
 *    brings the group back. They are never disabled.
 *  - The mode is always switchable and switching never destroys anything: every mode keeps its own
 *    stash, the inputs mirror the active one.
 *  - One person per chip: "Anna, Ben, Clara" typed into the search are three people (the server splits
 *    the same way - PuzzlersGrouping::splitInputs()).
 *  - The one exception to "never notices the form": a submit is stopped while a name sits typed in the search
 *    but not added, or Pair/Team holds nobody - either would save a solo time without a word.
 */
export default class extends Controller {
    static targets = [
        'modeButton', 'card', 'summary', 'summaryText', 'body', 'chips', 'identity', 'notice',
        'teamsSection', 'teamsLabel', 'teams', 'peopleSection', 'peopleLabel', 'people',
        'search', 'nameRow', 'nameToggle', 'nameField', 'nameInput', 'doneButton', 'inputs', 'initial',
        'pending', 'pendingText', 'pendingAdd', 'pendingDiscard',
    ];

    static values = {
        suggestionsUrl: String,
        searchUrl: String,
        locale: String,
        max: { type: Number, default: 15 },
        recentDays: { type: Number, default: 30 },
        defaultMode: { type: String, default: 'solo' },
        // Set from outside ("Add to my profile" of an official pair/team result): the mode to open in, whatever the chips
        initialMode: { type: String, default: '' },
        texts: Object,
    };

    connect() {
        const initial = JSON.parse(this.initialTarget.textContent || '{}');
        const chips = this.chipsFromInputs(initial.chips || []);

        // Set only when somebody else's time is edited: whoever tracked it stays, the viewer is a chip
        this.tracker = initial.tracker || null;
        this.viewerKey = initial.viewerKey || null;
        const initialMode = ['pair', 'team'].includes(this.initialModeValue) && !(this.initialModeValue === 'pair' && chips.length > 1) ? this.initialModeValue : null;
        this.mode = initialMode ?? (chips.length === 0 ? this.defaultModeValue : (chips.length === 1 ? 'pair' : 'team'));
        this.stash = { pair: [], team: [] };
        this.names = { pair: '', team: '' };
        this.suggestions = null;
        this.suggestionsPromise = null;
        this.known = new Map();
        this.showAllTeams = false;
        this.showAllPeople = false;
        this.lastMultiAdd = null;
        this.recentlyRemoved = [];
        this.collapsed = chips.length > 0;
        this.nameOpen = this.nameInputTarget.value.trim() !== '';
        // Why the last submit was stopped: { typed: "Anna, Ben" } or { nobody: true }
        this.blocked = null;
        // What sits typed in the search - TomSelect empties the box when it loses focus; it is put back (see 'blur')
        this.typed = '';

        chips.forEach(person => this.known.set(person.key, person));

        if (this.mode !== 'solo') {
            this.stash[this.mode] = chips;
            this.names[this.mode] = this.nameInputTarget.value;
            this.loadSuggestions();
        }

        // Without a solo option (preparing a pair/team ahead) a pick must not fold the card away
        this.neverCollapse = this.defaultModeValue !== 'solo';

        this.form = this.element.closest('form');
        this.submitGuard = event => this.guardSubmit(event);
        // Capture: before the form's other submit handlers (pace check, first try, Turbo) see it
        this.form?.addEventListener('submit', this.submitGuard, { capture: true });

        this.render();
    }

    disconnect() {
        this.form?.removeEventListener('submit', this.submitGuard, { capture: true });

        if (this.tomSelect) {
            this.tomSelect.destroy();
            this.tomSelect = null;
        }
    }

    // --- state ---------------------------------------------------------------------------------

    /**
     * The inputs win over the embedded display data: a browser restoring the page restores them,
     * not the JSON next to them.
     */
    chipsFromInputs(displayChips) {
        const byValue = new Map(displayChips.map(chip => [chip.value.toLowerCase(), chip]));

        return Array.from(this.inputsTarget.querySelectorAll('input[name="group_players[]"]'))
            .map(input => input.value.trim())
            .filter(value => value !== '')
            .map(value => byValue.get(value.toLowerCase()) || this.personFromValue(value));
    }

    personFromValue(value) {
        const isCode = value.startsWith('#');
        const name = value.replace(/^#+/, '').trim();

        return {
            key: isCode ? `code:${name.toLowerCase()}` : this.guestKey(name),
            value: value,
            label: isCode ? `#${name.toUpperCase()}` : name,
            code: isCode ? name.toUpperCase() : null,
            guest: !isCode,
            country: null,
            avatar: null,
        };
    }

    /**
     * The people typed into the search: one per comma-separated part, a #code is that player, anything else
     * a guest - or, with matchKnown, the player of exactly that name the picker already knows.
     */
    typedPeople(input, matchKnown = false) {
        const people = [];

        input.split(',').map(part => part.trim()).filter(part => part !== '').forEach(part => {
            const known = matchKnown && !part.startsWith('#') ? this.knownByLabel(part) : null;
            const person = known || this.personFromValue(part.startsWith('#') ? part : part.replace(/^#+/, '').trim());

            if (!people.some(other => other.key === person.key)) {
                people.push(person);
            }
        });

        return people;
    }

    knownByLabel(label) {
        const wanted = label.toLowerCase();
        const candidates = [...this.known.values(), ...Object.values(this.tomSelect?.options || {}).filter(option => !option.created)];

        return candidates.find(person => !person.guest && person.label && person.label.toLowerCase() === wanted) || null;
    }

    /** Same normalisation as TeamComposition::guestMemberKey() - good enough to match suggestions. */
    guestKey(name) {
        return 'g:' + name.trim().replace(/\s+/g, ' ').normalize('NFKD').replace(/\p{Mn}+/gu, '').toLowerCase();
    }

    get selection() {
        return this.mode === 'solo' ? [] : this.stash[this.mode];
    }

    isSelected(key) {
        return this.selection.some(person => person.key === key);
    }

    // --- mode switch ---------------------------------------------------------------------------

    switchMode(event) {
        this.setMode(event.currentTarget.dataset.mode);
    }

    switchKeydown(event) {
        const order = this.modeButtonTargets.map(button => button.dataset.mode);
        const index = order.indexOf(event.currentTarget.dataset.mode);
        let next = null;

        if (event.key === 'ArrowRight' || event.key === 'ArrowDown') {
            next = order[(index + 1) % order.length];
        } else if (event.key === 'ArrowLeft' || event.key === 'ArrowUp') {
            next = order[(index + order.length - 1) % order.length];
        } else if (event.key === ' ' || event.key === 'Enter') {
            next = order[index];
        }

        if (next === null) {
            return;
        }

        // Never let a key pressed on the switch reach the form
        event.preventDefault();
        this.setMode(next);
        this.modeButtonTargets.find(button => button.dataset.mode === next)?.focus();
    }

    setMode(mode) {
        if (mode === this.mode) {
            if (mode !== 'solo' && this.collapsed) {
                this.expand();
            }

            return;
        }

        if (this.mode !== 'solo') {
            this.names[this.mode] = this.nameInputTarget.value;
        }

        this.blocked = null;

        // Going from a pair to a team keeps the partner - unless a team was already put together
        if (this.mode === 'pair' && mode === 'team' && this.stash.team.length === 0) {
            this.stash.team = [...this.stash.pair];
        }

        this.mode = mode;
        this.lastMultiAdd = null;
        this.collapsed = mode === 'pair' && this.stash.pair.length === 1;

        if (mode !== 'solo') {
            this.nameInputTarget.value = this.names[mode];
            this.nameOpen = this.names[mode].trim() !== '';
            this.loadSuggestions();
        }

        this.render();
    }

    expand() {
        this.collapsed = false;
        this.render();
    }

    collapse() {
        if (this.selection.length === 0) {
            return;
        }

        this.collapsed = true;
        this.render();
    }

    // --- suggestions ---------------------------------------------------------------------------

    prefetch(event) {
        if (event.currentTarget.dataset.mode !== 'solo') {
            this.loadSuggestions();
        }
    }

    loadSuggestions() {
        if (this.suggestionsPromise !== null) {
            return this.suggestionsPromise;
        }

        this.suggestionsPromise = fetch(this.suggestionsUrlValue, {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
        })
            .then(response => (response.ok ? response.json() : Promise.reject(new Error(`HTTP ${response.status}`))))
            .then(data => {
                this.suggestions = data;
                data.people.forEach(person => this.known.set(person.key, { ...this.known.get(person.key), ...person }));
                this.adoptSuggestionKeys();
                this.render();
                // The search may have been ready first: the people above must be findable by typing too
                this.refreshSearchOptions(false);
            })
            .catch(() => {
                // The picker works without suggestions: search, guests and typed #codes still do
                this.suggestions = { teams: [], people: [] };
                this.render();
            });

        return this.suggestionsPromise;
    }

    /**
     * Chips restored from bare values ("#CODE") carry a provisional key; once suggestions know the
     * same person, take over their data so teams can be matched.
     */
    adoptSuggestionKeys() {
        const byValue = new Map(this.suggestions.people.map(person => [person.value.toLowerCase(), person]));

        ['pair', 'team'].forEach(mode => {
            this.stash[mode] = this.stash[mode].map(person => byValue.get(person.value.toLowerCase()) || person);
        });
    }

    // --- changing the selection ----------------------------------------------------------------

    addPerson(person) {
        if (this.tracker !== null && person.key === this.tracker.key) {
            return;
        }

        this.known.set(person.key, person);
        this.recentlyRemoved = this.recentlyRemoved.filter(key => key !== person.key);
        this.lastMultiAdd = null;
        this.blocked = null;

        // "Sar" typed, then Sarah tapped among the people offered: nothing is left unadded
        if (this.typed.trim() !== '' && person.label.toLowerCase().startsWith(this.typed.trim().toLowerCase())) {
            this.typed = '';
        }

        if (this.mode === 'pair') {
            this.stash.pair = [person];
            this.collapsed = true;
        } else if (this.mode === 'team') {
            if (this.isSelected(person.key) || this.selection.length >= this.maxValue) {
                return;
            }

            this.stash.team = [...this.stash.team, person];
        }

        this.render();
    }

    /** Several people at once ("Anna, Ben, Clara"): more than one is a team. */
    addPeople(people) {
        const fresh = people.filter(person => !(this.tracker !== null && person.key === this.tracker.key));

        if (fresh.length <= 1) {
            fresh.forEach(person => this.addPerson(person));

            return;
        }

        if (this.mode !== 'team') {
            this.setMode('team');
        }

        const added = [];

        fresh.forEach(person => {
            if (this.isSelected(person.key) || this.stash.team.length >= this.maxValue) {
                return;
            }

            this.known.set(person.key, person);
            this.stash.team = [...this.stash.team, person];
            added.push(person.key);
        });

        this.recentlyRemoved = this.recentlyRemoved.filter(key => !added.includes(key));
        this.lastMultiAdd = added.length > 1 ? added : null;
        this.blocked = null;
        this.render();
    }

    pickPerson(event) {
        const person = this.known.get(event.currentTarget.dataset.key);

        if (person) {
            this.addPerson(person);
        }
    }

    removePerson(event) {
        const key = event.currentTarget.dataset.key;

        this.stash[this.mode] = this.selection.filter(person => person.key !== key);
        this.recentlyRemoved = [key, ...this.recentlyRemoved.filter(removed => removed !== key)];
        this.lastMultiAdd = null;
        this.blocked = null;
        this.collapsed = false;
        this.render();
    }

    pickTeam(event) {
        const team = this.suggestions?.teams.find(candidate => candidate.id === event.currentTarget.dataset.teamId);

        if (!team) {
            return;
        }

        const missing = team.members
            .filter(key => !this.isSelected(key) && key !== this.tracker?.key)
            .map(key => this.known.get(key))
            .filter(Boolean);

        if (this.selection.length + missing.length > this.maxValue) {
            return;
        }

        this.stash.team = [...this.stash.team, ...missing];
        this.lastMultiAdd = missing.length > 1 ? missing.map(person => person.key) : null;
        this.render();
    }

    undoMultiAdd() {
        if (this.lastMultiAdd === null) {
            return;
        }

        const added = this.lastMultiAdd;
        this.stash.team = this.stash.team.filter(person => !added.includes(person.key));
        this.lastMultiAdd = null;
        this.render();
    }

    addAnother() {
        this.setMode('team');
    }

    toggleAllTeams() {
        this.showAllTeams = !this.showAllTeams;
        this.render();
    }

    toggleAllPeople() {
        this.showAllPeople = !this.showAllPeople;
        this.render();
    }

    showNameInput() {
        this.nameOpen = true;
        this.render();
        this.nameInputTarget.focus();
    }

    nameChanged() {
        if (this.mode !== 'solo') {
            this.names[this.mode] = this.nameInputTarget.value;
        }
    }

    swallowEnter(event) {
        event.preventDefault();
    }

    // --- a submit that would lose people ------------------------------------------------------

    guardSubmit(event) {
        if (this.mode === 'solo') {
            return;
        }

        const typed = this.typed.trim();

        if (typed !== '') {
            this.blocked = { typed };
        } else if (this.selection.length === 0 && this.tracker === null) {
            this.blocked = { nobody: true };
        } else {
            return;
        }

        event.preventDefault();
        event.stopImmediatePropagation();

        this.collapsed = false;
        this.render();
        this.pendingTarget.scrollIntoView({ behavior: 'smooth', block: 'center' });
        (this.blocked.typed ? this.pendingAddTarget : this.pendingTarget).focus({ preventScroll: true });
    }

    addPending() {
        const typed = this.blocked?.typed;

        if (!typed) {
            return;
        }

        this.typed = '';
        this.tomSelect?.setTextboxValue('');
        this.tomSelect?.close();
        this.addPeople(this.typedPeople(typed, true));
    }

    discardPending() {
        this.typed = '';
        this.tomSelect?.setTextboxValue('');
        this.tomSelect?.close();
        this.blocked = null;
        this.render();
    }

    renderPending() {
        const texts = this.textsValue;
        const blocked = this.mode === 'solo' ? null : this.blocked;

        this.pendingTarget.hidden = blocked === null;

        if (blocked === null) {
            return;
        }

        if (blocked.nobody) {
            this.pendingTextTarget.textContent = texts.nobodyAdded;
            this.pendingAddTarget.hidden = true;
            this.pendingDiscardTarget.hidden = true;

            return;
        }

        const people = this.typedPeople(blocked.typed, true);
        this.pendingTextTarget.textContent = texts.pendingText.replace('%text%', blocked.typed);
        this.pendingAddTarget.hidden = people.length === 0;
        this.pendingAddTarget.textContent = people.length > 1
            ? texts.pendingAddSeveral.replace('%count%', String(people.length))
            : texts.pendingAddOne.replace('%name%', people[0]?.label || blocked.typed);
        this.pendingDiscardTarget.hidden = false;
    }

    // --- what the selection is -----------------------------------------------------------------

    /**
     * The selection as the suggestions see it: everybody but the viewer. Normally that is the
     * selection itself; on somebody else's time the viewer is one of the chips and the tracker is not.
     * Null when the viewer is no longer part of the group - none of their teams can match then.
     */
    effectiveKeys() {
        const keys = this.selection.map(person => person.key);

        if (this.tracker === null) {
            return keys;
        }

        if (this.viewerKey === null || !keys.includes(this.viewerKey)) {
            return null;
        }

        return [...keys.filter(key => key !== this.viewerKey), this.tracker.key];
    }

    matchingTeam() {
        const keys = this.effectiveKeys();

        if (this.suggestions === null || keys === null || keys.length === 0) {
            return null;
        }

        const sorted = keys.slice().sort();

        return this.suggestions.teams.find(team => {
            const members = team.members.slice().sort();

            return members.length === sorted.length && members.every((key, index) => key === sorted[index]);
        }) || null;
    }

    identityText() {
        const texts = this.textsValue;
        const count = this.selection.length;

        if (count === 0) {
            return texts.pickSomeone;
        }

        const team = this.matchingTeam();
        let text;

        if (team && team.name) {
            text = texts.teamNamed.replace('%name%', team.name);
        } else if (count === 1) {
            text = texts.pairWith.replace('%name%', this.selection[0].label);
        } else {
            text = texts.teamOf.replace('%count%', String(count + 1));
        }

        if (this.suggestions === null || this.effectiveKeys() === null) {
            return text;
        }

        return team && team.count > 0
            ? `${text} · ${texts.timesTogether.replace('%count%', String(team.count))}`
            : `${text} · ${texts.firstTime}`;
    }

    // --- rendering -----------------------------------------------------------------------------

    render() {
        this.syncInputs();
        this.renderSwitch();

        this.renderNotice();
        this.renderPending();

        const open = this.mode !== 'solo';
        this.cardTarget.hidden = !open;

        if (!open) {
            return;
        }

        const collapsed = this.collapsed && this.selection.length > 0 && !this.neverCollapse;
        this.summaryTarget.hidden = !collapsed;
        this.bodyTarget.hidden = collapsed;
        this.summaryTextTarget.textContent = this.identityText();

        if (collapsed) {
            return;
        }

        this.ensureSearch();
        this.renderChips();
        this.renderIdentity();
        this.renderTeams();
        this.renderPeople();
        this.renderName();
        this.doneButtonTarget.hidden = this.selection.length === 0;
    }

    /** The only writer of the hidden inputs. */
    syncInputs() {
        const values = this.selection.map(person => person.value);
        const current = Array.from(this.inputsTarget.querySelectorAll('input')).map(input => input.value);

        if (values.length === current.length && values.every((value, index) => value === current[index])) {
            return;
        }

        this.inputsTarget.replaceChildren(...values.map(value => {
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'group_players[]';
            input.value = value;

            return input;
        }));

        this.inputsTarget.dispatchEvent(new Event('change', { bubbles: true }));
    }

    renderSwitch() {
        this.modeButtonTargets.forEach(button => {
            const active = button.dataset.mode === this.mode;
            button.setAttribute('aria-checked', active ? 'true' : 'false');
            button.tabIndex = active ? 0 : -1;
        });
    }

    renderChips() {
        const chips = [];

        if (this.tracker !== null) {
            chips.push(this.chipElement(this.tracker, { locked: true }));
        }

        this.selection.forEach(person => chips.push(this.chipElement(person, { removable: true })));

        if (this.mode === 'pair' && this.selection.length === 1) {
            const more = document.createElement('button');
            more.type = 'button';
            more.className = 'copuzzler-option copuzzler-option--ghost';
            more.dataset.action = 'copuzzler-picker#addAnother';
            more.dataset.testid = 'copuzzler-add-another';
            more.innerHTML = '<i class="ci-add-user me-1" aria-hidden="true"></i>';
            more.append(this.textsValue.completeTeam);
            chips.push(more);
        }

        this.chipsTarget.replaceChildren(...chips);
        this.chipsTarget.hidden = chips.length === 0;
    }

    chipElement(person, { locked = false, removable = false } = {}) {
        const chip = document.createElement('span');
        chip.className = 'copuzzler-chip' + (locked ? ' copuzzler-chip--locked' : '');
        chip.dataset.key = person.key;
        chip.append(this.avatarElement(person));

        const label = document.createElement('span');
        label.className = 'copuzzler-chip__label';
        label.textContent = person.label;
        chip.append(label);

        if (locked) {
            const note = document.createElement('small');
            note.className = 'text-muted ms-1';
            note.textContent = this.textsValue.trackedIt;
            chip.append(note);
        }

        if (removable) {
            const remove = document.createElement('button');
            remove.type = 'button';
            remove.className = 'copuzzler-chip__remove';
            remove.dataset.action = 'copuzzler-picker#removePerson';
            remove.dataset.key = person.key;
            remove.setAttribute('aria-label', this.textsValue.remove.replace('%name%', person.label));
            remove.innerHTML = '<i class="ci-close" aria-hidden="true"></i>';
            chip.append(remove);
        }

        return chip;
    }

    avatarElement(person) {
        if (person.avatar) {
            const image = document.createElement('img');
            image.className = 'copuzzler-avatar';
            image.src = person.avatar;
            image.alt = '';
            image.loading = 'lazy';

            return image;
        }

        const icon = document.createElement('span');
        icon.className = 'copuzzler-avatar copuzzler-avatar--icon';
        icon.setAttribute('aria-hidden', 'true');
        icon.innerHTML = person.country
            ? `<span class="fi fi-${person.country.replace(/[^a-z]/gi, '')}"></span>`
            : `<i class="${person.guest ? 'ci-user' : 'ci-user-circle'}"></i>`;

        return icon;
    }

    renderIdentity() {
        const line = document.createElement('span');
        line.textContent = this.identityText();
        const parts = [line];

        if (this.lastMultiAdd !== null) {
            const undo = document.createElement('button');
            undo.type = 'button';
            undo.className = 'btn btn-link btn-sm p-0 ms-2 align-baseline';
            undo.dataset.action = 'copuzzler-picker#undoMultiAdd';
            undo.textContent = this.textsValue.undo;
            parts.push(undo);
        }

        this.identityTarget.replaceChildren(...parts);
    }

    renderNotice() {
        // Somebody else's time, and the viewer is no longer among its people
        const leaving = this.tracker !== null && this.viewerKey !== null && !this.isSelected(this.viewerKey);
        this.noticeTarget.hidden = !leaving;
        this.noticeTarget.textContent = leaving ? this.textsValue.leavingTime : '';
    }

    renderTeams() {
        const texts = this.textsValue;

        if (this.mode !== 'team' || this.suggestions === null) {
            this.teamsSectionTarget.hidden = true;

            return;
        }

        const selectedKeys = this.effectiveKeys();

        if (selectedKeys === null) {
            this.teamsSectionTarget.hidden = true;

            return;
        }

        const candidates = this.suggestions.teams
            .filter(team => team.size >= 3)
            .filter(team => selectedKeys.every(key => team.members.includes(key)))
            .filter(team => team.members.length > selectedKeys.length);

        candidates.sort((a, b) => this.byRecentThenCount(a, b, { last: team => team.last, count: team => team.count, score: team => team.score }));

        const regulars = candidates.filter(team => team.count >= 2 || team.name);
        const shown = this.showAllTeams ? candidates : regulars.slice(0, 4);

        if (candidates.length === 0) {
            this.teamsSectionTarget.hidden = true;

            return;
        }

        this.teamsSectionTarget.hidden = false;
        const nothingPicked = this.selection.length === 0;
        this.teamsLabelTarget.textContent = nothingPicked ? texts.yourTeams : texts.completeTeam;

        const options = shown.map(team => {
            const missing = team.members.filter(key => !selectedKeys.includes(key)).map(key => this.known.get(key)).filter(Boolean);
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'copuzzler-option copuzzler-option--team';
            button.dataset.action = 'copuzzler-picker#pickTeam';
            button.dataset.teamId = team.id;
            button.disabled = this.selection.length + missing.length > this.maxValue;

            const names = missing.map(person => person.label).join(', ');
            const title = document.createElement('span');
            title.className = 'copuzzler-option__title';
            title.textContent = nothingPicked
                ? (team.name || names)
                : `+ ${names}` + (team.name ? ` → ${team.name}` : '');
            button.append(title);

            const meta = document.createElement('small');
            meta.className = 'copuzzler-option__meta';
            meta.textContent = [
                team.name && nothingPicked ? names : null,
                team.count > 0 ? `${team.count}×` : null,
                this.relativeTime(team.last),
            ].filter(Boolean).join(' · ');
            button.append(meta);

            return button;
        });

        if (candidates.length > shown.length || this.showAllTeams) {
            options.push(this.toggleElement('toggleAllTeams', this.showAllTeams ? texts.showLess : texts.showAll.replace('%count%', String(candidates.length))));
        }

        this.teamsTarget.replaceChildren(...options);
    }

    renderPeople() {
        const texts = this.textsValue;

        if (this.suggestions === null) {
            this.peopleSectionTarget.hidden = true;

            return;
        }

        const pairMode = this.mode === 'pair';
        const fromTeam = pairMode ? this.stash.team.map(person => person.key) : [];
        const rank = person => {
            if (fromTeam.includes(person.key)) {
                return 3;
            }

            return this.recentlyRemoved.includes(person.key) ? 2 : 1;
        };
        // A pair is the two of you: in Pair mode only the times as a pair count, a team last week does not make a partner
        const ranking = pairMode
            ? { last: person => person.pairLast, count: person => person.pairCount, score: person => person.pairScore }
            : { last: person => person.last, count: person => person.count, score: person => person.score };

        const candidates = Array.from(this.known.values())
            .filter(person => !this.isSelected(person.key))
            .filter(person => this.tracker === null || person.key !== this.tracker.key)
            .sort((a, b) => rank(b) - rank(a) || this.byRecentThenCount(a, b, ranking) || a.label.localeCompare(b.label));

        // Favorites the player never puzzled with are a convenience, not a list to scroll through: they only
        // fill the row up, the rest stays behind "Show all" (and in the search)
        const familiar = candidates.filter(person => (person.count || 0) > 0 || rank(person) > 1);
        const visibleCount = Math.max(Math.min(familiar.length, 8), Math.min(candidates.length, 5));

        if (candidates.length === 0) {
            this.peopleSectionTarget.hidden = true;

            return;
        }

        const shown = this.showAllPeople ? candidates : candidates.slice(0, visibleCount);
        const full = this.mode === 'team' && this.selection.length >= this.maxValue;

        this.peopleSectionTarget.hidden = false;
        this.peopleLabelTarget.textContent = full
            ? texts.full
            : (pairMode ? (fromTeam.length > 0 ? texts.whichOne : texts.whoWith) : texts.people);

        const options = shown.map(person => {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'copuzzler-option';
            button.dataset.action = 'copuzzler-picker#pickPerson';
            button.dataset.key = person.key;
            button.disabled = full;
            button.append(this.avatarElement(person));

            const label = document.createElement('span');
            label.className = 'copuzzler-option__title';
            label.textContent = person.label;
            button.append(label);

            const count = pairMode ? person.pairCount : person.count;

            if (count > 0) {
                const meta = document.createElement('small');
                meta.className = 'copuzzler-option__meta';
                meta.textContent = `${count}×`;
                button.append(meta);
            }

            return button;
        });

        if (candidates.length > visibleCount) {
            options.push(this.toggleElement('toggleAllPeople', this.showAllPeople ? texts.showLess : texts.showAll.replace('%count%', String(candidates.length))));
        }

        this.peopleTarget.replaceChildren(...options);
    }

    /**
     * Whoever the player puzzled with in the last recentDays (GetCoPuzzlers::RECENT_DAYS) comes first, latest
     * first - the partners of these weeks. Everybody else by how often, then by the recency-weighted score.
     */
    byRecentThenCount(a, b, { last, count, score }) {
        const recent = item => {
            const days = this.daysSince(last(item));

            return days !== null && days <= this.recentDaysValue ? days : null;
        };
        const recentA = recent(a);
        const recentB = recent(b);

        if (recentA !== null || recentB !== null) {
            if (recentA === null) {
                return 1;
            }

            if (recentB === null) {
                return -1;
            }

            if (recentA !== recentB) {
                return recentA - recentB;
            }
        }

        return (count(b) || 0) - (count(a) || 0) || (score(b) || 0) - (score(a) || 0);
    }

    daysSince(date) {
        if (!date) {
            return null;
        }

        return Math.max(0, Math.round((Date.now() - new Date(`${date}T00:00:00`).getTime()) / 86400000));
    }

    toggleElement(action, text) {
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'btn btn-link btn-sm p-0 align-self-center';
        button.dataset.action = `copuzzler-picker#${action}`;
        button.textContent = text;

        return button;
    }

    renderName() {
        const team = this.matchingTeam();
        const nameable = this.selection.length > 0 && !(team && team.name);

        this.nameRowTarget.hidden = !nameable;
        this.nameInputTarget.disabled = false;
        this.nameToggleTarget.hidden = this.nameOpen;
        this.nameFieldTarget.hidden = !this.nameOpen;

        // An already named team is not renamed from here - nothing to submit then
        if (!nameable && this.nameInputTarget.value !== '') {
            this.nameInputTarget.value = '';
        } else if (nameable && this.nameInputTarget.value === '' && this.names[this.mode]) {
            this.nameInputTarget.value = this.names[this.mode];
        }
    }

    relativeTime(date) {
        if (!date) {
            return null;
        }

        const days = this.daysSince(date);

        try {
            const format = new Intl.RelativeTimeFormat(this.localeValue || 'en', { numeric: 'auto' });

            if (days < 14) {
                return format.format(-Math.max(days, 0), 'day');
            }

            if (days < 60) {
                return format.format(-Math.round(days / 7), 'week');
            }

            if (days < 730) {
                return format.format(-Math.round(days / 30), 'month');
            }

            return format.format(-Math.round(days / 365), 'year');
        } catch (error) {
            return null;
        }
    }

    // --- search --------------------------------------------------------------------------------

    /** TomSelect only ever finds one person at a time; the chips above are ours. Loaded on first use. */
    ensureSearch() {
        if (this.tomSelect || this.searchLoading) {
            return;
        }

        this.searchLoading = true;

        import('tom-select').then(({ default: TomSelect }) => {
            if (!this.element.isConnected || this.tomSelect) {
                return;
            }

            const texts = this.textsValue;
            const escapeHtml = value => String(value).replace(/[&<>"']/g, char => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[char]));

            this.tomSelect = new TomSelect(this.searchTarget, {
                valueField: 'key',
                labelField: 'label',
                // value = "#CODE": typing the code the way it is written on a profile finds the person too
                searchField: ['label', 'code', 'value'],
                // The player's own co-puzzlers and favorites before everybody else, in the order offered above
                sortField: [{ field: 'suggested', direction: 'desc' }, { field: '$score', direction: 'desc' }, { field: '$order', direction: 'asc' }],
                maxItems: 1,
                maxOptions: 20,
                placeholder: texts.searchPlaceholder,
                closeAfterSelect: true,
                // The people worth offering unasked are the chips above - the dropdown answers typing
                openOnFocus: false,
                // Filter on every keystroke (a few hundred local people at most): with TomSelect's default 300 ms a quick
                // "Sarah⏎" met a closed dropdown, and Enter turned the typed text into a guest instead of picking Sarah
                refreshThrottle: 0,
                loadThrottle: 250,
                shouldLoad: query => query.trim().length >= 2,
                create: input => {
                    const people = this.typedPeople(input);

                    // "Anna, Ben, Clara": one option adding all three (onItemAdd)
                    if (people.length > 1) {
                        return { key: `several:${input}`, label: input, several: this.typedPeople(input, true), created: true };
                    }

                    return { ...people[0], created: true };
                },
                createFilter: input => this.typedPeople(input).some(person => !this.isSelected(person.key)),
                load: (query, callback) => {
                    const url = new URL(this.searchUrlValue, window.location.origin);
                    url.searchParams.set('query', query.trim());

                    fetch(url, { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
                        .then(response => (response.ok ? response.json() : Promise.reject(new Error(`HTTP ${response.status}`))))
                        .then(people => callback(people
                            .filter(person => !this.isSelected(person.key))
                            .map(person => ({ ...person, suggested: 0 }))))
                        .catch(() => callback());
                },
                render: {
                    option: person => `<div class="d-flex align-items-center">${this.avatarElement(person).outerHTML}<span>${escapeHtml(person.label)}</span>${person.code && person.label !== `#${person.code}` ? `<small class="text-muted ms-1">#${escapeHtml(person.code)}</small>` : ''}${person.guest ? `<small class="text-muted ms-1">${escapeHtml(texts.guest)}</small>` : ''}</div>`,
                    item: person => `<div>${escapeHtml(person.label)}</div>`,
                    option_create: data => {
                        const people = this.typedPeople(data.input);
                        const text = people.length > 1
                            ? texts.addSeveral.replace('%count%', String(people.length)).replace('%names%', people.map(person => person.label).join(', '))
                            : texts.addGuest.replace('%name%', people[0]?.label || data.input);

                        return `<div class="create">${escapeHtml(text)}</div>`;
                    },
                    // Not next to "Add “…” as a guest": the typed text is somebody, just not found
                    no_results: data => (this.tomSelect?.canCreate(data.input) ? null : `<div class="no-results">${escapeHtml(texts.noResults)}</div>`),
                },
                onItemAdd: key => {
                    const person = this.tomSelect.options[key];

                    if (person?.several) {
                        this.addPeople(person.several);
                    } else if (person) {
                        const { created, suggested, $order, $score, ...clean } = person;
                        this.addPerson(clean);
                    }

                    this.typed = '';
                    this.tomSelect.clear(true);
                    this.tomSelect.clearOptions();
                    this.refreshSearchOptions();
                },
            });

            this.tomSelect.on('type', text => {
                this.typed = text;
            });

            // TomSelect empties the box on blur - a name typed but not added stays visible instead, so it is not
            // lost on the way to Save (guardSubmit asks to add it)
            this.tomSelect.on('blur', () => {
                if (this.typed !== '') {
                    this.tomSelect.setTextboxValue(this.typed);
                }
            });

            // TomSelect never focuses a box holding text on click (it expects such a box to be focused already, and
            // keeps the native mousedown from focusing it) - with the text kept above, it has to
            this.tomSelect.control.addEventListener('click', () => {
                if (!this.tomSelect.isFocused) {
                    this.tomSelect.focus();
                }
            });

            // Back in the box with text still there: offer it again right away
            this.tomSelect.on('focus', () => {
                // After TomSelect's own focus handling, which refreshes with the dropdown closed
                setTimeout(() => {
                    if (this.tomSelect?.isFocused && this.typed.trim() !== '') {
                        this.tomSelect.refreshOptions(true);
                    }
                });
            });

            // Enter must never submit the form. With something typed it picks the highlighted option
            // (TomSelect's own handler); on an empty box it does nothing at all - the people offered
            // above are picked by tapping them, not by a stray Enter.
            this.tomSelect.control_input.addEventListener('keydown', event => {
                if (event.key !== 'Enter') {
                    return;
                }

                event.preventDefault();

                if (this.tomSelect.control_input.value.trim() === '') {
                    event.stopImmediatePropagation();
                    this.tomSelect.close();
                }
            }, { capture: true });

            this.refreshSearchOptions();
        }).finally(() => {
            this.searchLoading = false;
        });
    }

    /**
     * render = false: only when something is typed - an unfiltered list in the closed dropdown would answer
     * the next keystrokes until TomSelect's refresh throttle runs.
     */
    refreshSearchOptions(render = true) {
        if (!this.tomSelect) {
            return;
        }

        Array.from(this.known.values())
            .filter(person => !this.isSelected(person.key))
            .forEach(person => this.tomSelect.addOption({ ...person, suggested: 1 }));

        if (render || this.tomSelect.inputValue().trim() !== '') {
            this.tomSelect.refreshOptions(false);
        }
    }
}
