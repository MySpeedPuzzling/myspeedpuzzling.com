/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus';
import { Modal } from 'bootstrap';
import { getComponent } from '@symfony/ux-live-component';
import { chooseTranslation } from '../translation_choice.js';

// "Your favorites (N)" shows this many rows, then "Show all (N)"; with more favorites than that it gets a filter too
const FAVORITES_PREVIEW = 8;

const escapeHtml = (value) => String(value ?? '').replace(/[&<>"']/g, (char) => ({
    '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
}[char]));

/** Case and accent insensitive - "kate" finds "Kateřina", "#ab1" and "ab1" find the code */
const fold = (value) => String(value ?? '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase().trim().replace(/^#/, '');

/**
 * The add sheet of the compare page (docs/features/player-comparison.md "Add sheet"). Costs nothing until the sheet
 * opens: then its lists are fetched once - Solo: ALL the viewer's favorites and a few people they puzzle with
 * (comparison_people); Pairs/Teams: their own pairs/teams - and TomSelect is loaded for the search (2+ characters,
 * 250 ms throttle, never opens on focus). Picking somebody closes the sheet and runs the live component's `add` action -
 * the component decides, and says so when it cannot (a full line-up offers the swap).
 *
 * "Your favorites (N)": the first 8 rows, "Show all (N)" for the rest and, from 9 favorites on, a filter of their own
 * (instant, no request). Whoever is in the line-up already (`excluded`) stays listed, marked "Added" - rows never move
 * under a finger. Rows are built once per load and reused by the filter, so their images never reload.
 *
 * Avatars are _player_avatar.html.twig in JavaScript (same classes, same rules: photo + corner flag, else the round
 * flag, else the initial on the player's tint); skill tier icons are cloned from the <template>s the sheet renders with
 * the leaderboards' skill_icon() - members get a player's tier (`tier` in the JSON), everybody else the lock.
 *
 * Rules kept from the add-time co-puzzler picker: what this controller draws is ignored by re-renders
 * (data-live-ignore), Enter never submits anything, texts come from data attributes (a count's plural form is picked
 * like PHP picks it - browser_translation() + translation_choice.js). Guests and private players hidden from the viewer
 * are never offered (the endpoints leave them out).
 */
export default class extends Controller {
    static targets = ['search', 'suggestions', 'tierIcon'];

    static values = {
        kind: String,
        searchUrl: String,
        suggestionsUrl: String,
        excluded: Array,
        texts: Object,
    };

    connect() {
        this.reset();
        this.onShow = () => {
            this.loadSuggestions();
            this.ensureSearch();
        };
        this.onShown = () => this.focusSearch();
        this.element.addEventListener('show.bs.modal', this.onShow);
        this.element.addEventListener('shown.bs.modal', this.onShown);
        this.connected = true;
    }

    disconnect() {
        this.connected = false;
        this.element.removeEventListener('show.bs.modal', this.onShow);
        this.element.removeEventListener('shown.bs.modal', this.onShown);
        this.tomSelect?.destroy();
        this.tomSelect = null;
    }

    reset() {
        this.lists = null;
        this.listsPromise = null;
        this.rows = new Map();
        this.favoritesExpanded = false;
        this.favoritesQuery = '';
        this.favorites = null;
    }

    // --- values follow the line-up (re-renders change them) ---------------------------------------------------------

    kindValueChanged(kind, previous) {
        if (!this.connected || previous === undefined || kind === previous) {
            return;
        }

        this.reset();
        this.renderLoading();

        if (this.tomSelect) {
            this.tomSelect.clear(true);
            this.tomSelect.clearOptions();
            this.tomSelect.settings.placeholder = this.textsValue.searchPlaceholder;
            this.tomSelect.inputState();
        }
    }

    /** Somebody was added or removed: their row says so (the rows stay where they are) */
    excludedValueChanged() {
        if (!this.connected || this.lists === null) {
            return;
        }

        if (this.favorites !== null) {
            this.renderFavoriteRows();
        }

        this.element.querySelectorAll('[data-list="plain"]').forEach((list) => {
            list.replaceChildren(...this.plainItems(list.dataset.section).map((item) => this.rowFor(item)));
        });
    }

    // --- lists -----------------------------------------------------------------------------------------------------

    loadSuggestions() {
        if (this.listsPromise !== null) {
            return this.listsPromise;
        }

        const kind = this.kindValue;

        this.listsPromise = fetch(this.suggestionsUrlValue, {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
        })
            .then((response) => (response.ok ? response.json() : Promise.reject(new Error(`HTTP ${response.status}`))))
            .then((data) => {
                if (kind !== this.kindValue) {
                    return;
                }

                this.lists = kind === 'solo'
                    ? {
                        favorites: (data.favorites || []).map((person) => this.personItem(person)),
                        coPuzzlers: (data.coPuzzlers || []).map((person) => this.personItem(person)),
                    }
                    : { teams: this.teamsFromSearch(data) };
                this.renderLists();
            })
            .catch(() => {
                // The search still works without the lists
                if (kind === this.kindValue) {
                    this.lists = kind === 'solo' ? { favorites: [], coPuzzlers: [] } : { teams: [] };
                    this.renderLists();
                }
            });

        return this.listsPromise;
    }

    personItem(person) {
        const code = String(person.code || '');

        return {
            ref: person.ref,
            id: String(person.id || ''),
            label: person.label,
            sub: code !== '' && person.label !== `#${code}` ? `#${code}` : '',
            avatar: person.avatar,
            country: person.country,
            countryName: person.countryName,
            // Absent for anybody but a member: the leaderboards' lock
            tier: person.tier ?? 'locked',
            favorite: Boolean(person.favorite),
            team: false,
            mine: false,
            search: fold(`${person.label} ${code}`),
        };
    }

    teamsFromSearch(data) {
        return (Array.isArray(data) ? data : []).map((team) => ({
            ref: team.ref,
            label: team.label,
            sub: [team.named ? team.members : '', this.together(Number(team.count))].filter(Boolean).join(' · '),
            members: team.members,
            team: true,
            mine: Boolean(team.mine),
            favorite: false,
        }));
    }

    /** "1 result" / "12 results" - the raw message carries every plural form and the locale of its catalogue */
    together(count) {
        return this.choose(this.textsValue.together, count);
    }

    choose(text, count) {
        return text ? (chooseTranslation(text.message, count, text.locale) ?? '') : '';
    }

    renderLoading() {
        this.suggestionsTarget.setAttribute('aria-busy', 'true');
        this.suggestionsTarget.replaceChildren(...[1, 2, 3].map(() => {
            const row = document.createElement('div');
            row.className = 'cmp-option cmp-option--skeleton';
            row.setAttribute('aria-hidden', 'true');
            row.innerHTML = '<span></span><span></span>';

            return row;
        }));
    }

    renderLists() {
        this.rows = new Map();
        this.favorites = null;
        this.suggestionsTarget.setAttribute('aria-busy', 'false');

        const sections = this.kindValue === 'solo'
            ? [this.favoritesSection(), this.plainSection('coPuzzlers', this.textsValue.coPuzzlersHeading)]
            : [this.plainSection('teams', this.textsValue.teamsHeading)];
        const shown = sections.filter((section) => section !== null);

        if (shown.length === 0) {
            const status = document.createElement('p');
            status.className = 'cmp-options__status';
            status.textContent = this.textsValue.noSuggestions;
            this.suggestionsTarget.replaceChildren(status);

            return;
        }

        this.suggestionsTarget.replaceChildren(...shown);

        if (this.favorites !== null) {
            this.renderFavoriteRows();
        }
    }

    favoritesSection() {
        const items = this.lists.favorites;

        if (items.length === 0) {
            return null;
        }

        const section = this.section('favorites', this.choose(this.textsValue.favoritesHeading, items.length));
        const list = this.list('favorites');
        const empty = document.createElement('p');
        empty.className = 'cmp-options__status';
        empty.hidden = true;
        empty.textContent = this.textsValue.favoritesNoMatch;

        if (items.length > FAVORITES_PREVIEW) {
            const filter = document.createElement('input');
            filter.type = 'search';
            filter.className = 'form-control cmp-add-filter';
            filter.autocomplete = 'off';
            filter.enterKeyHint = 'search';
            filter.placeholder = this.textsValue.favoritesFilter;
            filter.setAttribute('aria-label', this.textsValue.favoritesFilter);
            filter.setAttribute('aria-controls', list.id);
            filter.dataset.action = 'input->comparison-add#filterFavorites keydown.enter->comparison-add#closeKeyboard';
            filter.dataset.testid = 'comparison-add-favorites-filter';
            section.append(filter);
        }

        section.append(list, empty);

        const more = document.createElement('button');
        more.type = 'button';
        more.className = 'cmp-add-more';
        more.textContent = this.choose(this.textsValue.favoritesShowAll, items.length);
        more.dataset.action = 'comparison-add#showAllFavorites';
        more.dataset.testid = 'comparison-add-favorites-more';
        section.append(more);

        this.favorites = { list, empty, more };

        return section;
    }

    plainSection(name, heading) {
        if (this.plainItems(name).length === 0) {
            return null;
        }

        const section = this.section(name, heading);
        const list = this.list(name);
        list.dataset.list = 'plain';
        list.replaceChildren(...this.plainItems(name).map((item) => this.rowFor(item)));
        section.append(list);

        return section;
    }

    plainItems(name) {
        return (this.lists && this.lists[name]) || [];
    }

    section(name, heading) {
        const section = document.createElement('section');
        section.className = 'cmp-add-section';
        section.dataset.testid = `comparison-add-${name}`;

        const title = document.createElement('h3');
        title.className = 'cmp-sheet__label';
        title.id = `comparison-add-${name}-heading`;
        title.textContent = heading || '';
        section.setAttribute('aria-labelledby', title.id);
        section.append(title);

        return section;
    }

    list(name) {
        const list = document.createElement('div');
        list.className = 'cmp-options';
        list.id = `comparison-add-${name}-list`;
        list.dataset.section = name;

        return list;
    }

    renderFavoriteRows() {
        const { list, empty, more } = this.favorites;
        const all = this.lists.favorites;
        const query = fold(this.favoritesQuery);
        const filtering = query !== '';
        const matches = filtering ? all.filter((item) => item.search.includes(query)) : all;
        const shown = filtering || this.favoritesExpanded ? matches : matches.slice(0, FAVORITES_PREVIEW);

        list.replaceChildren(...shown.map((item) => this.rowFor(item)));
        empty.hidden = !(filtering && matches.length === 0);
        more.hidden = filtering || this.favoritesExpanded || all.length <= FAVORITES_PREVIEW;
    }

    filterFavorites(event) {
        this.favoritesQuery = event.target.value;
        this.renderFavoriteRows();
    }

    /** Enter only closes the phone keyboard over the filtered list - nothing to submit */
    closeKeyboard(event) {
        event.preventDefault();
        event.target.blur();
    }

    showAllFavorites() {
        this.favoritesExpanded = true;
        this.renderFavoriteRows();

        // The button is gone: the focus goes to the first row it revealed, the page does not move
        const revealed = Array.from(this.favorites.list.children).slice(FAVORITES_PREVIEW).find((row) => !row.disabled);
        revealed?.focus({ preventScroll: true });
    }

    pickOption(event) {
        const ref = event.currentTarget.dataset.ref;

        if (ref && !event.currentTarget.disabled) {
            this.pick(ref);
        }
    }

    /** One node per person and state - the filter moves them around instead of drawing (and loading) them again */
    rowFor(item) {
        const added = this.excludedValue.includes(item.ref);
        const cached = this.rows.get(item.ref);

        if (cached && cached.added === added) {
            return cached.node;
        }

        const button = document.createElement('button');
        button.type = 'button';
        button.className = `cmp-option${added ? ' is-added' : ''}`;
        button.dataset.ref = item.ref;
        button.dataset.action = 'comparison-add#pickOption';
        button.dataset.testid = 'comparison-add-option';
        button.disabled = added;
        button.setAttribute('aria-label', added ? `${item.label} - ${this.textsValue.added}` : `${this.textsValue.add} ${item.label}`);
        button.innerHTML = this.optionHtml(item, { added, plus: true, star: false });
        this.rows.set(item.ref, { added, node: button });

        return button;
    }

    optionHtml(item, { added, plus, star }) {
        const tier = item.team ? '' : this.tierIconHtml(item.tier);
        const favorite = star && item.favorite
            ? `<i class="ci-star-filled text-warning cmp-option__star" aria-hidden="true"></i><span class="visually-hidden">${escapeHtml(this.textsValue.favorite)}:</span>`
            : '';
        const sub = item.sub ? `<span class="cmp-option__sub">${escapeHtml(item.sub)}</span>` : '';
        const tag = item.mine ? `<span class="cmp-tag">${escapeHtml(this.textsValue.youreInIt)}</span>` : '';
        let trailing = '';

        if (added) {
            trailing = `<span class="cmp-option__added"><i class="bi bi-check-lg" aria-hidden="true"></i>${escapeHtml(this.textsValue.added)}</span>`;
        } else if (plus) {
            trailing = '<i class="bi bi-plus-lg cmp-option__plus" aria-hidden="true"></i>';
        }

        return `${this.avatarHtml(item)}<span class="cmp-option__text"><span class="cmp-option__name">${tier}${favorite}<span class="cmp-option__label">${escapeHtml(item.label)}</span></span>${sub}</span>${tag}${trailing}`;
    }

    /** The sheet's <template> of that tier - rendered by skill_icon(), so it is the leaderboards' icon */
    tierIconHtml(tier) {
        const template = this.tierIconTargets.find((candidate) => candidate.dataset.tier === tier);

        return template ? template.innerHTML.trim() : '';
    }

    /** _player_avatar.html.twig (size lg) inside the compared subjects' wrapper (_subject_avatar.html.twig) */
    avatarHtml(item) {
        if (item.team) {
            return '<span class="cmp-avatar cmp-avatar--lg" aria-hidden="true"><span class="cmp-avatar__icon"><i class="bi bi-people-fill"></i></span></span>';
        }

        const country = String(item.country || '').toLowerCase().replace(/[^a-z]/g, '');
        const title = item.countryName ? ` title="${escapeHtml(item.countryName)}"` : '';
        let inner;

        if (item.avatar) {
            inner = `<img class="lb-avatar-img" src="${escapeHtml(item.avatar)}" alt="" loading="lazy" decoding="async">`
                + (country ? `<span class="lb-flag fi fi-${country}"${title}></span>` : '');
        } else if (country) {
            // No photo: the round flag is the avatar (and then no flag on the corner)
            inner = `<span class="lb-avatar-img lb-avatar-flag fi fis fi-${country}"${title}></span>`;
        } else {
            // The tint is picked by the last hex digit of the player id - a player keeps their colour on every page
            const tint = /[0-9a-f]$/.test(item.id) ? item.id.slice(-1) : 'guest';
            const initial = (Array.from(String(item.label || '').replace(/^#+|#+$/g, ''))[0] || '').toUpperCase();
            inner = `<span class="lb-avatar-img lb-avatar-initial lb-tint-${tint}" aria-hidden="true">${escapeHtml(initial)}</span>`;
        }

        return `<span class="cmp-avatar cmp-avatar--lg"><span class="lb-avatar lb-avatar-lg">${inner}</span></span>`;
    }

    // --- search ----------------------------------------------------------------------------------------------------

    /** TomSelect is loaded the first time the sheet opens */
    ensureSearch() {
        if (this.tomSelect || this.searchLoading || !this.hasSearchTarget) {
            return;
        }

        this.searchLoading = true;

        import('tom-select').then(({ default: TomSelect }) => {
            if (!this.element.isConnected || this.tomSelect) {
                return;
            }

            this.tomSelect = new TomSelect(this.searchTarget, {
                valueField: 'ref',
                labelField: 'label',
                searchField: ['label', 'sub', 'members'],
                // Whoever is in the line-up is found too, marked "Added" - and cannot be picked twice
                disabledField: 'disabled',
                maxItems: 1,
                maxOptions: 20,
                placeholder: this.textsValue.searchPlaceholder,
                closeAfterSelect: true,
                openOnFocus: false,
                loadThrottle: 250,
                // The server found them (accents, member codes) - do not filter its answer again
                score: () => () => 1,
                shouldLoad: (query) => fold(query).length >= 2,
                load: (query, callback) => this.search(query, callback),
                render: {
                    option: (item) => `<div class="cmp-option cmp-option--result${item.disabled ? ' is-added' : ''}">${this.optionHtml(item, { added: item.disabled, plus: false, star: true })}</div>`,
                    item: (item) => `<div>${escapeHtml(item.label)}</div>`,
                    no_results: () => `<div class="no-results">${escapeHtml(this.textsValue.noResults)}</div>`,
                    loading: () => `<div class="no-results">${escapeHtml(this.textsValue.loading)}</div>`,
                },
                onItemAdd: (ref) => {
                    this.tomSelect.clear(true);
                    this.tomSelect.clearOptions();
                    this.pick(ref);
                },
            });

            // Enter never submits or navigates: with something typed TomSelect picks the highlighted option, on an
            // empty box it does nothing
            this.tomSelect.control_input.addEventListener('keydown', (event) => {
                if (event.key !== 'Enter') {
                    return;
                }

                event.preventDefault();

                if (this.tomSelect.control_input.value.trim() === '') {
                    event.stopImmediatePropagation();
                    this.tomSelect.close();
                }
            }, { capture: true });

            if (this.element.classList.contains('show')) {
                this.focusSearch();
            }
        }).finally(() => {
            this.searchLoading = false;
        });
    }

    search(query, callback) {
        const kind = this.kindValue;
        const url = new URL(this.searchUrlValue, window.location.origin);
        url.searchParams.set('query', query.trim());

        fetch(url, { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
            .then((response) => (response.ok ? response.json() : Promise.reject(new Error(`HTTP ${response.status}`))))
            .then((data) => {
                if (kind !== this.kindValue || !this.tomSelect) {
                    callback();

                    return;
                }

                const excluded = new Set(this.excludedValue);
                const items = kind === 'solo'
                    ? (Array.isArray(data) ? data : []).map((person) => this.personItem(person))
                    : this.teamsFromSearch(data);

                this.tomSelect.clearOptions();
                callback(items.map((item) => ({ ...item, disabled: excluded.has(item.ref) })));
            })
            .catch(() => callback());
    }

    focusSearch() {
        // A phone keyboard over the lists is worse than one more tap
        if (this.tomSelect && window.matchMedia('(min-width: 576px)').matches) {
            this.tomSelect.focus();
        }
    }

    // --- picking ---------------------------------------------------------------------------------------------------

    async pick(ref) {
        Modal.getInstance(this.element)?.hide();

        const root = this.element.closest('[data-controller~="live"]');

        if (root === null) {
            return;
        }

        const component = await getComponent(root);
        component.action('add', { ref });
    }
}
