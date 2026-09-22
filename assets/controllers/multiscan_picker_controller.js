import { Controller } from '@hotwired/stimulus';
import TomSelect from 'tom-select';

/**
 * The two typeahead pickers of the multiscan action bar, on a <select> bound to a
 * LiveComponent model with on(change). The select sits inside data-live-ignore so
 * re-renders never destroy Tom Select; the pre-rendered options are the "smart"
 * suggestions (people lent to / borrowed from, favourites; own collections).
 *
 *  mode="person":     remote search of players (with avatars) + free text = a name without an account
 *  mode="collection": own collections + free text = a new collection created on apply
 */
export default class extends Controller {
    static values = {
        mode: String,
        searchUrl: String,
        createLabel: String,
        noResultsLabel: String,
    };

    connect() {
        const person = this.modeValue === 'person';

        this.tomSelect = new TomSelect(this.element, {
            maxItems: 1,
            create: (input) => ({ value: input.trim(), text: input.trim(), created: true }),
            createOnBlur: true,
            persist: true,
            valueField: 'value',
            labelField: 'text',
            searchField: ['text', 'code'],
            // Pre-rendered suggestions keep their order (most useful first)
            sortField: [{ field: '$order' }, { field: '$score' }],
            plugins: { clear_button: { title: '' } },
            render: {
                option: (data, escape) => this.renderPerson(data, escape),
                item: (data, escape) => this.renderPerson(data, escape, true),
                option_create: (data, escape) => `<div class="create">${escape(this.createLabelValue.replace('%input%', data.input))}</div>`,
                no_results: () => `<div class="no-results">${this.noResultsLabelValue}</div>`,
            },
            ...(person && this.hasSearchUrlValue && this.searchUrlValue !== '' ? {
                shouldLoad: (query) => query.length >= 2 && !query.startsWith('#'),
                loadThrottle: 250,
                load: (query, callback) => this.searchPlayers(query, callback),
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
