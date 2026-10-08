import { Controller } from '@hotwired/stimulus';

/**
 * "They have an account now" of a guest on the Pairs & teams page: finds the player by name or by code.
 *
 * Progressive enhancement of the plain player code input - the form still posts `code`, TomSelect only fills it.
 * The order is the add form's (copuzzler_picker_controller.js): the people the player knows first - a name like
 * the guest's, then favorites, then the other co-puzzlers - and everybody else from the player search after them.
 * Started when the player opens the section, so a page full of guests loads nothing until somebody is linked.
 */

// One page, many guests: the player's own people are fetched once and shared by every guest's picker
const suggestionsCache = new Map();

const fold = value => String(value || '')
    .normalize('NFD')
    .replace(/[̀-ͯ]/g, '')
    .toLowerCase()
    .replace(/[^\p{L}\p{N}]+/gu, ' ')
    .trim();

export default class extends Controller {
    static targets = ['input'];

    static values = {
        suggestionsUrl: String,
        searchUrl: String,
        guestName: String,
        viewer: String,
        texts: Object,
    };

    connect() {
        this.tomSelect = null;
        this.loading = false;
    }

    disconnect() {
        this.tomSelect?.destroy();
        this.tomSelect = null;
    }

    start() {
        if (this.tomSelect || this.loading || !this.hasInputTarget) {
            return;
        }

        this.loading = true;

        Promise.all([import('tom-select'), this.loadSuggestions()]).then(([{ default: TomSelect }, people]) => {
            if (!this.element.isConnected || this.tomSelect) {
                return;
            }

            this.tomSelect = new TomSelect(this.inputTarget, this.options());
            people.forEach(person => this.tomSelect.addOption({ ...this.ranked(person), suggested: 1 }));
            this.preloadGuestName();
        }).catch(() => {
            // The plain code input keeps working
        }).finally(() => {
            this.loading = false;
        });
    }

    options() {
        const texts = this.textsValue;
        const escapeHtml = value => String(value).replace(/[&<>"']/g, char => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[char]));

        return {
            // The form posts the player code, exactly what the plain input took
            valueField: 'code',
            labelField: 'label',
            searchField: ['label', 'code', 'value'],
            sortField: [
                { field: 'nameMatch', direction: 'desc' },
                { field: 'favorite', direction: 'desc' },
                { field: 'suggested', direction: 'desc' },
                { field: '$score', direction: 'desc' },
                { field: '$order', direction: 'asc' },
            ],
            maxItems: 1,
            maxOptions: 20,
            create: false,
            persist: false,
            closeAfterSelect: true,
            // The best guesses for this guest show up as soon as the field is tapped
            openOnFocus: true,
            refreshThrottle: 0,
            loadThrottle: 250,
            placeholder: texts.placeholder,
            shouldLoad: query => query.trim().length >= 2,
            load: (query, callback) => {
                this.search(query.trim())
                    .then(people => callback(people))
                    .catch(() => callback());
            },
            render: {
                option: person => `<div class="d-flex align-items-center">${this.avatarHtml(person)}<span>${escapeHtml(person.label)}</span>${person.label !== `#${person.code}` ? `<small class="text-muted ms-1">#${escapeHtml(person.code)}</small>` : ''}${person.favorite ? `<i class="bi bi-star-fill text-warning ms-1" title="${escapeHtml(texts.favorite)}" aria-label="${escapeHtml(texts.favorite)}"></i>` : ''}</div>`,
                item: person => `<div>${escapeHtml(person.label)}${person.label !== `#${person.code}` ? `<small class="text-muted ms-1">#${escapeHtml(person.code)}</small>` : ''}</div>`,
                no_results: () => `<div class="no-results">${escapeHtml(texts.noResults)}</div>`,
            },
        };
    }

    loadSuggestions() {
        const url = this.suggestionsUrlValue;

        if (!suggestionsCache.has(url)) {
            suggestionsCache.set(url, fetch(url, { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
                .then(response => (response.ok ? response.json() : Promise.reject(new Error(`HTTP ${response.status}`))))
                .then(data => data.people || [])
                .catch(() => {
                    suggestionsCache.delete(url);

                    // Search by name and code still works without them
                    return [];
                }));
        }

        // Guests have no account to link to, and the player is not their own guest
        return suggestionsCache.get(url).then(people => people.filter(person => this.linkable(person)));
    }

    search(query) {
        const url = new URL(this.searchUrlValue, window.location.origin);
        url.searchParams.set('query', query);

        return fetch(url, { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
            .then(response => (response.ok ? response.json() : Promise.reject(new Error(`HTTP ${response.status}`))))
            .then(people => people
                .filter(person => this.linkable(person))
                .map(person => ({ ...this.ranked(person), favorite: 0, suggested: 0 })));
    }

    /** Players named like the guest are the likeliest answer: offered before anything is typed. */
    preloadGuestName() {
        const words = fold(this.guestNameValue).split(' ').filter(word => word.length >= 2);
        const query = (words[0] || '').length >= 3 ? words[0] : words.join(' ');

        if (query.length < 2) {
            return;
        }

        this.search(query).then(people => {
            if (!this.tomSelect) {
                return;
            }

            people.filter(person => person.nameMatch > 0).forEach(person => this.tomSelect.addOption(person));

            if (this.tomSelect.isOpen) {
                this.tomSelect.refreshOptions(false);
            }
        }).catch(() => {});
    }

    linkable(person) {
        return person.guest === false && Boolean(person.code) && person.key !== this.viewerValue;
    }

    /** 2 = the same name as the guest, 1 = the same first name, 0 = anybody else */
    ranked(person) {
        const guest = fold(this.guestNameValue);
        const name = fold(person.label);
        const sameFirstName = guest !== '' && name !== '' && guest.split(' ')[0] === name.split(' ')[0];

        return {
            ...person,
            favorite: person.favorite ? 1 : 0,
            nameMatch: guest !== '' && name === guest ? 2 : (sameFirstName ? 1 : 0),
        };
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
