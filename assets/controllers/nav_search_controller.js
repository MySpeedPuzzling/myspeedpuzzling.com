import { Controller } from '@hotwired/stimulus';
import {
    audiencesOf,
    BAR_LIMIT,
    locationOf,
    prepareIndex,
    reportsZero,
    search,
    ZERO_IDLE_MS,
    zeroResultQuery,
} from '../navigation/nav_search.js';
import { SEARCH_ZERO_EVENT } from '../nav_instrumentation.js';

// One list per URL for the whole tab (both search fields, every Turbo visit): fetched on the first focus of a field
const lists = new Map();

// Zero-result reports of this page view (decision 39): reset by every page load
let reported = new Set();
document.addEventListener('turbo:load', () => {
    reported = new Set();
});

// How long the server results may take after the pause before the zero check gives up (Live request in flight)
const SETTLE_RETRY_MS = 300;
const SETTLE_RETRIES = 20;

function load(url) {
    if (!lists.has(url)) {
        const entry = { data: null, promise: null };

        // No cookies: the list is the same public JSON for everybody (stateless firewall)
        entry.promise = fetch(url, { credentials: 'omit', headers: { Accept: 'application/json' } })
            .then((response) => (response.ok ? response.json() : null))
            .then((data) => {
                entry.data = data;

                return data;
            })
            .catch(() => {
                // Try again on the next focus
                lists.delete(url);

                return null;
            });
        lists.set(url, entry);
    }

    return lists.get(url).promise;
}

function privateEntries() {
    const element = document.getElementById('nav-search-private');

    if (!element) {
        return [];
    }

    try {
        const entries = JSON.parse(element.textContent || '[]');

        return Array.isArray(entries) ? entries : [];
    } catch (e) {
        return [];
    }
}

/**
 * "Pages & settings" in a GlobalSearch instance (templates/components/GlobalSearch.html.twig; navigation MENU-03, and
 * the top bar's from SRCH-01): the registry's list (nav-search.json) loaded on the first focus of the field, filtered by
 * the page's <body data-viewer-audience> (+ #nav-search-private for moderators and admins), ranked by
 * assets/navigation/nav_search.js and rendered into its data-live-ignore container above the server results - before
 * them, synchronously, so nothing moves when the Live results arrive. `limit` rows first, then "Show N more".
 *
 * Links are plain links: a visit from the open Menu panel is a replace visit (assets/layer_history.js rule (d)).
 * Send feedback opens the Help sheet like its Menu row; Language opens the Menu's language switch; Log out leaves Turbo.
 * A search without any result - no page, no player, no puzzle - is reported once it paused (the P0-03 beacon).
 */
export default class extends Controller {
    static targets = ['input', 'results'];
    static values = {
        url: String,
        surface: { type: String, default: 'bar' },
        limit: { type: Number, default: BAR_LIMIT },
        title: String,
        more: String,
    };

    connect() {
        this.expanded = false;
        this.shownQuery = null;
        this.zeroTimer = null;

        this.inputTarget.addEventListener('focus', this.handleFocus);
        this.inputTarget.addEventListener('input', this.handleInput);
        this.inputTarget.addEventListener('change', this.handleInput);
        this.resultsTarget.addEventListener('click', this.handleClick);

        // A field restored with text (Back/Forward, a re-render): show its pages too
        if (this.inputTarget.value.trim() !== '') {
            this.handleFocus();
        }
    }

    disconnect() {
        this.inputTarget.removeEventListener('focus', this.handleFocus);
        this.inputTarget.removeEventListener('input', this.handleInput);
        this.inputTarget.removeEventListener('change', this.handleInput);
        this.resultsTarget.removeEventListener('click', this.handleClick);
        clearTimeout(this.zeroTimer);
    }

    get navSurface() {
        return this.surfaceValue === 'menu' ? 'menu' : 'topbar';
    }

    handleFocus = () => {
        if (!this.urlValue) {
            return;
        }

        load(this.urlValue).then(() => this.update());
    };

    // `change` also fires when the field loses focus - a tap on a result: the rows must stay the same elements, or the
    // tap's click never reaches the link (mousedown and mouseup on two different nodes)
    handleInput = () => {
        if (this.inputTarget.value === this.shownQuery) {
            return;
        }

        this.expanded = false;
        this.update();
    };

    update() {
        const query = this.inputTarget.value;
        const entry = lists.get(this.urlValue);

        if (!entry || !entry.data) {
            if (query.trim() !== '') {
                this.handleFocus();
            }

            this.render([], query);

            return;
        }

        const index = prepareIndex(entry.data, privateEntries());
        const results = search(index, query, { audiences: audiencesOf(document.body.dataset.viewerAudience) });

        this.render(results, query);
        this.scheduleZeroCheck(query, results.length);
    }

    render(results, query) {
        const container = this.resultsTarget;
        container.replaceChildren();
        this.shownQuery = query;

        if (results.length === 0) {
            container.hidden = true;

            return;
        }

        const limit = this.limitValue > 0 ? this.limitValue : BAR_LIMIT;
        const shown = this.expanded ? results : results.slice(0, limit);
        const titleId = `${this.element.id || 'nav-search'}-pages-title`;

        const title = document.createElement('p');
        title.className = 'nav-search-title';
        title.id = titleId;
        title.setAttribute('role', 'heading');
        title.setAttribute('aria-level', '3');
        title.textContent = this.titleValue;

        const list = document.createElement('ul');
        list.className = 'nav-search-list';
        list.setAttribute('aria-labelledby', titleId);
        shown.forEach((result) => list.appendChild(this.row(result)));

        container.append(title, list);

        if (shown.length < results.length) {
            const more = document.createElement('button');
            more.type = 'button';
            more.className = 'nav-search-more';
            more.setAttribute('data-nav', `${this.navSurface}/search/more`);
            more.setAttribute('data-nav-search-more', '');
            more.textContent = this.moreValue.replace('%count%', String(results.length - shown.length));
            container.append(more);
        }

        container.hidden = false;
    }

    row({ entry }) {
        const item = document.createElement('li');
        const link = document.createElement('a');
        link.className = 'mr nav-search-row';
        link.href = entry.u;
        link.setAttribute('data-nav', `${this.navSurface}/search/${entry.id}`);
        link.setAttribute('data-nav-search-id', entry.id);
        link.setAttribute('data-press', '');

        if (entry.x === 'help') {
            this.helpLink(link);
        } else if (entry.x === 'language') {
            link.setAttribute('data-nav-search-language', '');
        } else if (entry.x === 'logout') {
            link.setAttribute('data-turbo', 'false');
        }

        const icon = document.createElement('i');
        icon.className = `bi ${entry.i || 'bi-chevron-right'}`;
        icon.setAttribute('aria-hidden', 'true');

        const label = document.createElement('span');
        label.className = 'mr-l';
        label.textContent = entry.l;

        const where = locationOf(entry) || entry.d || '';

        if (where !== '') {
            const small = document.createElement('small');
            small.className = 'mr-d';
            small.textContent = where;
            label.append(small);
        }

        link.append(icon, label);
        item.append(link);

        return item;
    }

    /** Send feedback opens the Help sheet with the current page attached - exactly as the Menu's row does */
    helpLink(link) {
        const row = document.querySelector('#site-menu [data-nav$="/send_feedback"]');

        if (row) {
            ['href', 'data-turbo-frame', 'data-modal-scrim', 'rel'].forEach((name) => {
                if (row.hasAttribute(name)) {
                    link.setAttribute(name, row.getAttribute(name));
                }
            });

            return;
        }

        link.href = `${link.getAttribute('href')}?url=${encodeURIComponent(window.location.pathname + window.location.search)}`;
        link.setAttribute('data-turbo-frame', 'modal-frame');
        link.setAttribute('data-modal-scrim', 'own');
        link.setAttribute('rel', 'nofollow');
    }

    handleClick = (event) => {
        const target = event.target && typeof event.target.closest === 'function' ? event.target : null;

        if (!target) {
            return;
        }

        if (target.closest('[data-nav-search-more]')) {
            event.preventDefault();
            this.expanded = true;
            this.update();

            return;
        }

        const language = target.closest('[data-nav-search-language]');
        const switcher = document.getElementById('nav-language');

        // In the Menu panel the language switch is right there: open it instead of leaving
        if (language && switcher && this.surfaceValue === 'menu' && switcher.closest('.menu-layer')) {
            event.preventDefault();
            this.inputTarget.value = '';
            this.inputTarget.dispatchEvent(new Event('input', { bubbles: true }));
            switcher.open = true;

            const summary = switcher.querySelector('summary');

            if (summary) {
                summary.focus({ preventScroll: true });
            }

            switcher.scrollIntoView({ block: 'center', behavior: 'instant' });
        }
    };

    /** The zero-results beacon: after the pause, once the server results for the same query are in */
    scheduleZeroCheck(query, pageResults) {
        clearTimeout(this.zeroTimer);

        if (pageResults > 0 || query.trim() === '') {
            return;
        }

        let retries = 0;
        const check = () => {
            if (this.inputTarget.value !== query) {
                return;
            }

            const settled = this.serverSettled(query);

            if (settled === null) {
                if (retries < SETTLE_RETRIES) {
                    retries += 1;
                    this.zeroTimer = setTimeout(check, SETTLE_RETRY_MS);
                }

                return;
            }

            if (reportsZero(query, pageResults, settled, reported)) {
                const q = zeroResultQuery(query);
                reported.add(q);
                document.dispatchEvent(new CustomEvent(SEARCH_ZERO_EVENT, { detail: { surface: this.navSurface, q } }));
            }
        };

        this.zeroTimer = setTimeout(check, ZERO_IDLE_MS);
    }

    /** null while the Live results are not those of the query yet; else whether they are empty (no player, no puzzle) */
    serverSettled(query) {
        if (this.element.getAttribute('aria-busy') === 'true') {
            return null;
        }

        try {
            const props = JSON.parse(this.element.getAttribute('data-live-props-value') || '{}');

            if (props.query !== query) {
                return null;
            }
        } catch (e) {
            return null;
        }

        const results = this.element.querySelector('.global-search-results');

        if (!results) {
            return null;
        }

        return results.querySelectorAll('a[href]').length === 0;
    }
}
