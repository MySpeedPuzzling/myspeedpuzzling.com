import { Controller } from '@hotwired/stimulus';

/**
 * "Who's with you" of a group in the partner finder listing form (templates/partner_finder/_listing_form.html.twig,
 * docs/features/partner-finder.md "Phase 2 as built"). Registered players only: favorites first, then the people the
 * player puzzles with (my_co_puzzlers, the add form's suggestions), and anybody else by name or code from the player
 * search. At most "group size - 1" people. The picks go into the form's hidden `coMembers` field as player ids;
 * without JavaScript the field keeps what the listing has and the picker stays hidden.
 */
export default class extends Controller {
    static targets = ['field', 'picker', 'value', 'size'];

    static values = {
        suggestionsUrl: String,
        searchUrl: String,
        viewer: String,
        selected: Array,
        texts: Object,
    };

    connect() {
        this.tomSelect = null;

        if (!this.hasPickerTarget || !this.hasValueTarget) {
            return;
        }

        this.fieldTarget.hidden = false;

        Promise.all([import('tom-select'), this.loadSuggestions()]).then(([{ default: TomSelect }, people]) => {
            if (!this.element.isConnected || this.tomSelect) {
                return;
            }

            this.tomSelect = new TomSelect(this.pickerTarget, this.options());
            this.selectedValue.forEach(person => this.tomSelect.addOption({ ...person, favorite: 0, suggested: 1 }));
            people.forEach(person => this.tomSelect.addOption({ ...person, favorite: person.favorite ? 1 : 0, suggested: 1 }));
            this.tomSelect.setValue(this.selectedValue.map(person => person.key), true);
            this.tomSelect.on('change', () => this.write());
            this.limit();
        }).catch(() => {
            // Nothing to pick with - the listing keeps its co-members (the hidden field is untouched)
            this.fieldTarget.hidden = true;
        });
    }

    disconnect() {
        this.tomSelect?.destroy();
        this.tomSelect = null;
    }

    options() {
        const texts = this.textsValue;

        return {
            valueField: 'key',
            labelField: 'label',
            searchField: ['label', 'code'],
            sortField: [
                { field: 'favorite', direction: 'desc' },
                { field: 'suggested', direction: 'desc' },
                { field: '$score', direction: 'desc' },
                { field: '$order', direction: 'asc' },
            ],
            // TomSelect picks single/multi once, from maxItems - a group of 2 starts with room for 1, so say it
            mode: 'multi',
            maxItems: this.maxItems(),
            maxOptions: 20,
            create: false,
            persist: false,
            closeAfterSelect: true,
            openOnFocus: true,
            hideSelected: true,
            refreshThrottle: 0,
            loadThrottle: 250,
            placeholder: texts.placeholder,
            plugins: { remove_button: { title: '' } },
            shouldLoad: query => query.trim().length >= 2,
            load: (query, callback) => {
                this.search(query.trim())
                    .then(people => callback(people))
                    .catch(() => callback());
            },
            render: {
                option: person => `<div class="d-flex align-items-center">${this.avatarHtml(person)}<span>${escapeHtml(person.label)}</span>${this.codeHtml(person)}${person.favorite ? `<i class="bi bi-star-fill text-warning ms-1" title="${escapeHtml(texts.favorite)}" aria-label="${escapeHtml(texts.favorite)}"></i>` : ''}</div>`,
                item: person => `<div class="d-flex align-items-center">${this.avatarHtml(person)}<span>${escapeHtml(person.label)}</span></div>`,
                no_results: () => `<div class="no-results">${escapeHtml(texts.noResults)}</div>`,
                not_loading: () => (this.tomSelect && this.tomSelect.isFull() ? `<div class="no-results">${escapeHtml(texts.full)}</div>` : null),
            },
        };
    }

    /** The group size changed: room for size - 1 people, the last picks dropped when it shrank */
    limit() {
        if (!this.tomSelect) {
            return;
        }

        const max = this.maxItems();
        this.tomSelect.settings.maxItems = max;

        while (this.tomSelect.items.length > max) {
            this.tomSelect.removeItem(this.tomSelect.items[this.tomSelect.items.length - 1], true);
        }

        this.tomSelect.refreshState();
        this.write();
    }

    maxItems() {
        const size = this.hasSizeTarget ? Number.parseInt(this.sizeTarget.value, 10) : 2;

        return Math.max(1, (Number.isFinite(size) ? size : 2) - 1);
    }

    write() {
        if (this.tomSelect) {
            this.valueTarget.value = this.tomSelect.items.join(',');
        }
    }

    loadSuggestions() {
        return fetch(this.suggestionsUrlValue, { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
            .then(response => (response.ok ? response.json() : Promise.reject(new Error(`HTTP ${response.status}`))))
            .then(data => (data.people || []).filter(person => this.pickable(person)))
            // The search by name and code still works without them
            .catch(() => []);
    }

    search(query) {
        const url = new URL(this.searchUrlValue, window.location.origin);
        url.searchParams.set('query', query);

        return fetch(url, { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
            .then(response => (response.ok ? response.json() : Promise.reject(new Error(`HTTP ${response.status}`))))
            .then(people => people
                .filter(person => this.pickable(person) && !person.hidden)
                .map(person => ({ ...person, favorite: 0, suggested: 0 })));
    }

    /** Registered players only (a guest has no account to show on the listing), never the player themselves */
    pickable(person) {
        return person.guest === false && Boolean(person.key) && person.key !== this.viewerValue;
    }

    codeHtml(person) {
        return person.code && person.label !== `#${person.code}` ? `<small class="text-muted ms-1">#${escapeHtml(person.code)}</small>` : '';
    }

    avatarHtml(person) {
        if (person.avatar) {
            return `<img class="copuzzler-avatar" src="${encodeURI(person.avatar)}" alt="" loading="lazy">`;
        }

        const inner = person.country
            ? `<span class="fi fi-${String(person.country).replace(/[^a-z]/gi, '')}"></span>`
            : '<i class="ci-user-circle"></i>';

        return `<span class="copuzzler-avatar copuzzler-avatar--icon" aria-hidden="true">${inner}</span>`;
    }
}

function escapeHtml(value) {
    return String(value).replace(/[&<>"']/g, char => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[char]));
}
