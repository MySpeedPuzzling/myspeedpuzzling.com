import { Controller } from '@hotwired/stimulus';

/*
 * The partner finder page (docs/features/partner-finder.md, "Part A as built"): filters and sorts the rows the server
 * rendered - no request of its own. Each row carries
 *   data-kinds      "pair" / "team" (somebody on their own) or "group", space separated
 *   data-country    lower-case country code
 *   data-strengths  preset values and "c:<hash>" of folded custom chips, space separated
 *   data-order      the server's order (newest first)
 *   data-median500 / data-median1000   median seconds, empty when unknown (or a private player)
 *   data-distance   members only: distance from the viewer's speed, empty when unknown
 * The own listing stands above the list and is never filtered.
 */
export default class extends Controller {
    static targets = ['row', 'list', 'noMatch', 'kind', 'country', 'sort', 'strength'];

    static values = { member: Boolean };

    connect() {
        this.kind = 'all';
        this.strength = null;
        this.apply();
    }

    pickKind(event) {
        this.kind = event.currentTarget.dataset.kind || 'all';
        this.kindTargets.forEach((button) => button.setAttribute('aria-pressed', button === event.currentTarget ? 'true' : 'false'));
        this.apply();
    }

    pickStrength(event) {
        const key = event.currentTarget.dataset.strength || null;
        this.strength = this.strength === key ? null : key;
        this.strengthTargets.forEach((button) => button.setAttribute('aria-pressed', button.dataset.strength === this.strength ? 'true' : 'false'));
        this.apply();
    }

    filter() {
        this.apply();
    }

    sort() {
        this.reorder();
    }

    reset() {
        this.kind = 'all';
        this.strength = null;
        this.kindTargets.forEach((button) => button.setAttribute('aria-pressed', button.dataset.kind === 'all' ? 'true' : 'false'));
        this.strengthTargets.forEach((button) => button.setAttribute('aria-pressed', 'false'));

        if (this.hasCountryTarget) {
            if (this.countryTarget.tomselect) {
                this.countryTarget.tomselect.setValue('', true);
            }
            this.countryTarget.value = '';
        }

        this.apply();
    }

    apply() {
        const country = this.hasCountryTarget ? this.countryTarget.value : '';
        let shown = 0;

        this.rowTargets.forEach((row) => {
            const kinds = (row.dataset.kinds || '').split(' ');
            const strengths = (row.dataset.strengths || '').split(' ');
            const visible = (this.kind === 'all' || kinds.includes(this.kind))
                && (country === '' || row.dataset.country === country)
                && (this.strength === null || strengths.includes(this.strength));

            row.hidden = !visible;

            if (visible) {
                shown++;
            }
        });

        if (this.hasNoMatchTarget) {
            this.noMatchTarget.hidden = shown > 0 || this.rowTargets.length === 0;
        }
    }

    reorder() {
        if (!this.hasListTarget) {
            return;
        }

        const mode = this.hasSortTarget ? this.sortTarget.value : 'newest';
        const rows = [...this.rowTargets];

        rows.sort((a, b) => {
            let difference = 0;

            if (mode === 'fastest') {
                // Median 500 first; somebody with 1000 times only after everybody with 500 times; nothing known last
                difference = compareNumbers(number(a.dataset.median500), number(b.dataset.median500))
                    || compareNumbers(number(a.dataset.median1000), number(b.dataset.median1000));
            } else if (mode === 'closest' && this.memberValue) {
                difference = compareNumbers(number(a.dataset.distance), number(b.dataset.distance));
            }

            return difference || compareNumbers(number(a.dataset.order), number(b.dataset.order));
        });

        rows.forEach((row) => this.listTarget.appendChild(row));
    }
}

function number(value) {
    return value === undefined || value === '' ? null : Number(value);
}

// Unknown values last
function compareNumbers(a, b) {
    if (a === b) {
        return 0;
    }

    if (a === null) {
        return 1;
    }

    if (b === null) {
        return -1;
    }

    return a - b;
}
