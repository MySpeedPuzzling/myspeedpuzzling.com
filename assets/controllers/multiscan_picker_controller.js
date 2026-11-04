import { Controller } from '@hotwired/stimulus';
import TomSelect from 'tom-select';

// Compared like the server does (ManufacturerResolver): spacing and case only - "Cech" is not "Čech"
const normalizeName = (name) => name.trim().replace(/\s+/g, ' ').toLowerCase();

/**
 * The typeahead pickers of the multiscan tray, on a <select> bound to a
 * LiveComponent model with on(change). The select sits inside data-live-ignore so
 * re-renders never destroy Tom Select; the pre-rendered options are the "smart"
 * suggestions (people lent to / borrowed from, favourites; own collections).
 *
 *  mode="person":     remote search of players (with avatars) + free text = a name without an account
 *  mode="collection": own collections + free text = a new collection created on apply
 *  mode="brand":      quick-add's brand - every brand (fetched once from optionsUrl, most puzzles first,
 *                     with logos), searchable by name or EAN prefix; free text = a new brand, unless it
 *                     is an existing brand's name (then that brand is picked)
 */
export default class extends Controller {
    static values = {
        mode: String,
        searchUrl: String,
        optionsUrl: String,
        createLabel: String,
        noResultsLabel: String,
    };

    connect() {
        const person = this.modeValue === 'person';
        const brand = this.modeValue === 'brand';

        this.tomSelect = new TomSelect(this.element, {
            maxItems: 1,
            create: (input) => (brand ? this.createBrand(input) : { value: input.trim(), text: input.trim(), created: true }),
            createOnBlur: true,
            persist: true,
            valueField: 'value',
            labelField: 'text',
            // Pre-rendered suggestions carry `code` + `avatar` in `data-data` (TomSelect's name for it is the dataset key)
            dataAttr: 'data',
            searchField: brand ? ['text', 'eanPrefix'] : ['text', 'code'],
            // Hundreds of brands match one letter; the most common ones are first anyway
            maxOptions: brand ? 60 : 50,
            // Pre-rendered suggestions keep their order (most useful first)
            sortField: [{ field: '$order' }, { field: '$score' }],
            plugins: { clear_button: { title: '' } },
            render: {
                option: (data, escape) => (brand ? this.renderBrand(data, escape) : this.renderPerson(data, escape)),
                item: (data, escape) => (brand ? this.renderBrand(data, escape, true) : this.renderPerson(data, escape, true)),
                option_create: (data, escape) => `<div class="create">${escape(this.createLabelValue.replace('%input%', data.input))}</div>`,
                no_results: () => `<div class="no-results">${this.noResultsLabelValue}</div>`,
            },
            ...(person && this.hasSearchUrlValue && this.searchUrlValue !== '' ? {
                shouldLoad: (query) => query.length >= 2 && !query.startsWith('#'),
                loadThrottle: 250,
                load: (query, callback) => this.searchPlayers(query, callback),
            } : {}),
            ...(brand && this.hasOptionsUrlValue && this.optionsUrlValue !== '' ? {
                preload: true,
                shouldLoad: () => !this.brandsLoaded,
                load: (query, callback) => this.loadBrands(callback),
            } : {}),
        });

        // Tom Select updates the underlying select silently; the on(change) model modifier needs a real event
        this.tomSelect.on('change', () => {
            this.element.dispatchEvent(new Event('change', { bubbles: true }));
            this.tomSelect.blur();
        });
    }

    disconnect() {
        if (this.tomSelect) {
            this.tomSelect.destroy();
        }
    }

    async loadBrands(callback) {
        if (this.brandsLoaded) {
            callback();
            return;
        }

        try {
            const response = await fetch(this.optionsUrlValue, { headers: { Accept: 'application/json' } });

            if (!response.ok) {
                callback();
                return;
            }

            const brands = await response.json();
            this.brandsLoaded = true;
            callback(brands);

            // The pre-rendered selected option (the brand the barcode belongs to) is kept by addOption(): give it its logo
            const selected = this.tomSelect.getValue();
            const loaded = selected ? brands.find((option) => option.value === selected) : null;
            if (loaded) {
                this.tomSelect.updateOption(selected, loaded);
            }
        } catch (e) {
            callback();
        }
    }

    createBrand(input) {
        const wanted = normalizeName(input);

        for (const [value, option] of Object.entries(this.tomSelect.options)) {
            if (!option.created && typeof option.text === 'string' && normalizeName(option.text) === wanted) {
                this.tomSelect.addItem(value);
                return false;
            }
        }

        return { value: input.trim(), text: input.trim(), created: true };
    }

    renderBrand(data, escape, item = false) {
        const logo = data.logo
            ? `<img class="multiscan-brand-logo me-2 flex-shrink-0" alt="" loading="lazy" src="${escape(data.logo)}">`
            : `<span class="multiscan-brand-logo multiscan-brand-logo-empty me-2 flex-shrink-0" aria-hidden="true">${data.created ? '<i class="bi-plus-lg"></i>' : escape((data.text || '?').trim().charAt(0).toUpperCase())}</span>`;
        const count = !item && data.count ? `<small class="text-muted ms-auto ps-2 flex-shrink-0">${escape(String(data.count))}</small>` : '';

        return `<div class="d-flex align-items-center">${logo}<span class="text-truncate">${escape(data.text)}</span>${count}</div>`;
    }

    async searchPlayers(query, callback) {
        try {
            const url = new URL(this.searchUrlValue, window.location.origin);
            url.searchParams.set('query', query);
            const response = await fetch(url.toString(), { headers: { Accept: 'application/json' } });

            if (!response.ok) {
                callback();
                return;
            }

            const players = await response.json();
            callback(players.map((player) => ({
                value: '#' + player.code,
                text: player.label,
                code: player.code,
                avatar: player.avatar,
            })));
        } catch (e) {
            callback();
        }
    }

    renderPerson(data, escape, item = false) {
        const avatar = data.avatar
            ? `<img class="rounded-circle me-2 flex-shrink-0" style="width: 22px; height: 22px; object-fit: cover;" alt="" loading="lazy" src="${escape(data.avatar)}">`
            : (data.code ? '<i class="ci-user me-2"></i>' : (this.modeValue === 'person' ? '<i class="bi-person-lines-fill me-2 text-muted"></i>' : ''));
        const code = data.code && !item ? `<small class="text-muted ms-1">#${escape(data.code)}</small>` : '';

        return `<div class="d-flex align-items-center">${avatar}<span class="text-truncate">${escape(data.text)}</span>${code}</div>`;
    }
}
