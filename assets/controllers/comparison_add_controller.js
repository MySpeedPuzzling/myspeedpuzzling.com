/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus';
import { Modal } from 'bootstrap';
import { getComponent } from '@symfony/ux-live-component';
import { chooseTranslation } from '../translation_choice.js';

const UUID = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;
const SUGGESTIONS_LIMIT = 12;

const escapeHtml = (value) => String(value ?? '').replace(/[&<>"']/g, (char) => ({
    '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
}[char]));

/**
 * The add sheet of the compare page (docs/features/player-comparison.md). Costs nothing until the sheet opens: then the
 * suggestions are fetched once (Solo: the viewer's favorites and co-puzzlers from /my-co-puzzlers.json; Pairs/Teams:
 * their own pairs/teams) and TomSelect is loaded for the search (2+ characters, 250 ms throttle, never opens on focus).
 * Picking somebody closes the sheet and runs the live component's `add` action - the component decides, and says so
 * when it cannot (a full line-up offers the swap).
 *
 * Rules kept from the add-time co-puzzler picker: what this controller draws is ignored by re-renders
 * (data-live-ignore), Enter never submits anything, texts come from data attributes (a count's plural form is picked like
 * PHP picks it - browser_translation() + translation_choice.js). Whoever is in the line-up already
 * (`excluded`), guests and private players hidden from the viewer are never offered.
 */
export default class extends Controller {
    static targets = ['search', 'suggestions'];

    static values = {
        kind: String,
        searchUrl: String,
        suggestionsUrl: String,
        excluded: Array,
        texts: Object,
    };

    connect() {
        this.suggestions = null;
        this.suggestionsPromise = null;
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

    // --- values follow the line-up (re-renders change them) ---------------------------------------------------------

    kindValueChanged(kind, previous) {
        if (!this.connected || previous === undefined || kind === previous) {
            return;
        }

        this.suggestions = null;
        this.suggestionsPromise = null;
        this.suggestionsTarget.replaceChildren();

        if (this.tomSelect) {
            this.tomSelect.clear(true);
            this.tomSelect.clearOptions();
            this.tomSelect.settings.placeholder = this.textsValue.searchPlaceholder;
            this.tomSelect.inputState();
        }
    }

    excludedValueChanged() {
        if (this.connected && this.suggestions !== null) {
            this.renderSuggestions();
        }
    }

    // --- suggestions -----------------------------------------------------------------------------------------------

    loadSuggestions() {
        if (this.suggestionsPromise !== null) {
            return this.suggestionsPromise;
        }

        const kind = this.kindValue;
        this.renderStatus(this.textsValue.loading);

        this.suggestionsPromise = fetch(this.suggestionsUrlValue, {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
        })
            .then((response) => (response.ok ? response.json() : Promise.reject(new Error(`HTTP ${response.status}`))))
            .then((data) => {
                if (kind !== this.kindValue) {
                    return;
                }

                this.suggestions = kind === 'solo' ? this.peopleFromCoPuzzlers(data) : this.teamsFromSearch(data);
                this.renderSuggestions();
            })
            .catch(() => {
                // The search still works without suggestions
                this.suggestions = [];
                this.renderSuggestions();
            });

        return this.suggestionsPromise;
    }

    /** Favorites first, then whoever the viewer puzzles with most - registered and visible players only */
    peopleFromCoPuzzlers(data) {
        const people = (data.people || []).filter((person) => !person.guest
            && UUID.test(person.key || '')
            // A private co-puzzler is only known by the code once typed - not offered here
            && !String(person.label || '').startsWith('#'));

        people.sort((a, b) => Number(Boolean(b.favorite)) - Number(Boolean(a.favorite)));

        return people.map((person) => ({
            ref: `p-${person.key.toLowerCase()}`,
            label: person.label,
            sub: person.code ? `#${person.code}` : '',
            avatar: person.avatar,
            country: person.country,
            team: false,
            mine: false,
            favorite: Boolean(person.favorite),
        }));
    }

    teamsFromSearch(data) {
        return (Array.isArray(data) ? data : []).map((team) => ({
            ref: team.ref,
            label: team.label,
            sub: [team.named ? team.members : '', this.together(Number(team.count))].filter(Boolean).join(' · '),
            members: team.members,
            avatar: null,
            country: null,
            team: true,
            mine: Boolean(team.mine),
            favorite: false,
        }));
    }

    /** "1 result" / "12 results" - the raw message carries every plural form and the locale of its catalogue */
    together(count) {
        const text = this.textsValue.together;

        return text ? (chooseTranslation(text.message, count, text.locale) ?? '') : '';
    }

    renderSuggestions() {
        const excluded = new Set(this.excludedValue);
        const items = (this.suggestions || []).filter((item) => !excluded.has(item.ref)).slice(0, SUGGESTIONS_LIMIT);

        if (items.length === 0) {
            this.renderStatus(this.textsValue.noSuggestions);

            return;
        }

        this.suggestionsTarget.replaceChildren(...items.map((item) => {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'cmp-option';
            button.dataset.ref = item.ref;
            button.setAttribute('aria-label', `${this.textsValue.add} ${item.label}`);
            button.innerHTML = this.optionHtml(item, true);
            button.addEventListener('click', () => this.pick(item.ref));

            return button;
        }));
    }

    renderStatus(text) {
        const status = document.createElement('p');
        status.className = 'cmp-options__status';
        status.textContent = text;
        this.suggestionsTarget.replaceChildren(status);
    }

    optionHtml(item, withPlus) {
        const avatar = this.avatarHtml(item);
        const tags = [
            item.mine ? `<span class="cmp-tag">${escapeHtml(this.textsValue.youreInIt)}</span>` : '',
            item.favorite ? '<i class="bi bi-star-fill cmp-option__star" aria-hidden="true"></i>' : '',
        ].join('');
        const sub = item.sub ? `<span class="cmp-option__sub">${escapeHtml(item.sub)}</span>` : '';
        const plus = withPlus ? '<i class="bi bi-plus-lg cmp-option__plus" aria-hidden="true"></i>' : '';

        return `${avatar}<span class="cmp-option__text"><span class="cmp-option__name">${escapeHtml(item.label)}</span>${sub}</span>${tags}${plus}`;
    }

    avatarHtml(item) {
        if (item.team) {
            return '<span class="cmp-option__avatar cmp-option__avatar--icon" aria-hidden="true"><i class="bi bi-people-fill"></i></span>';
        }

        if (item.avatar) {
            return `<img class="cmp-option__avatar" src="${escapeHtml(item.avatar)}" alt="" loading="lazy">`;
        }

        if (item.country) {
            return `<span class="cmp-option__avatar cmp-option__avatar--icon" aria-hidden="true"><span class="fi fis fi-${escapeHtml(String(item.country).replace(/[^a-z]/gi, ''))}"></span></span>`;
        }

        const initial = String(item.label || '').replace(/^#/, '').charAt(0).toUpperCase();

        return `<span class="cmp-option__avatar cmp-option__avatar--icon" aria-hidden="true">${escapeHtml(initial)}</span>`;
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
                maxItems: 1,
                maxOptions: 20,
                placeholder: this.textsValue.searchPlaceholder,
                closeAfterSelect: true,
                openOnFocus: false,
                loadThrottle: 250,
                // The server found them (accents, member codes) - do not filter its answer again
                score: () => () => 1,
                shouldLoad: (query) => query.trim().replace(/^#/, '').length >= 2,
                load: (query, callback) => this.search(query, callback),
                render: {
                    option: (item) => `<div class="cmp-option cmp-option--result">${this.optionHtml(item, false)}</div>`,
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
                    ? (Array.isArray(data) ? data : [])
                        .filter((person) => !person.guest && !person.hidden && UUID.test(person.key || ''))
                        .map((person) => ({
                            ref: `p-${person.key.toLowerCase()}`,
                            label: person.label,
                            sub: person.code && person.label !== `#${person.code}` ? `#${person.code}` : '',
                            avatar: person.avatar,
                            country: person.country,
                            team: false,
                            mine: false,
                            favorite: false,
                        }))
                    : this.teamsFromSearch(data);

                this.tomSelect.clearOptions();
                callback(items.filter((item) => !excluded.has(item.ref)));
            })
            .catch(() => callback());
    }

    focusSearch() {
        // A phone keyboard over the suggestions is worse than one more tap
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
