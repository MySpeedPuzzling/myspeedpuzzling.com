/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus';
import { foldSearchText } from '../search_fold.js';

/**
 * The series page's filter bar (docs/features/events-page/high-frequency-series.md "Series page for 200+ editions",
 * templates/series/_filters.html.twig) - only on a series with many sessions. Everything is in the HTML the server
 * rendered: the upcoming rows and the past lines carry `data-categories` (RoundCategory values of their rounds),
 * `data-search` (folded with SearchText::fold(): edition name, session label, revealed round puzzle names and the date
 * in the page's language) and `data-month` (Y-m). Without JavaScript the bar stays hidden and every row shows.
 *
 * - Category chips (All / Solo / Pairs / Teams - only the categories that occur): a row matches when one of its rounds
 *   is of that category; a session without rounds shows under All only.
 * - The search field: every typed word (folded like the server folds, foldSearchText()) must be in `data-search`.
 * - While a chip or a word filters: every year shows (the year chips step aside), past months holding a match open,
 *   groups and sections without one hide, and "No date matches - Show all" appears when nothing is left. Clearing
 *   brings back the server's state (the months it had open, the chosen year).
 * - The month select scrolls to that month's header or section (`data-series-jump`: `upcoming-Y-m` / `past-Y-m` - a
 *   month may hold both) and opens it, choosing its year first; it clears a filter that hides that month.
 *
 * Hidden by the filter = the `data-filtered-out` attribute (_series-page.scss), never `hidden` - series_archive_controller
 * owns `hidden` on the years and lines. Nothing is fetched, nothing is stored.
 */
export default class extends Controller {
    static targets = ['bar', 'chip', 'search', 'jump', 'none'];

    connect() {
        this.category = '';
        this.tokens = [];
        // <details> the filter opened - closed again when it is cleared
        this.openedByFilter = new Set();
        this.barTarget.hidden = false;
    }

    disconnect() {
        // Back to the server's markup, so a Turbo snapshot or a reconnect starts from the same state
        this.category = '';
        this.tokens = [];
        this.apply();
        this.barTarget.hidden = true;
    }

    chooseCategory(event) {
        this.category = event.currentTarget.dataset.category ?? '';
        this.apply();
    }

    search() {
        this.tokens = foldSearchText(this.searchTarget.value).split(' ').filter((token) => token !== '');
        this.apply();
    }

    // Enter in the search field submits nothing (there is no form) - keep the focus where it is
    keepEnter(event) {
        event.preventDefault();
    }

    reset() {
        this.category = '';
        this.tokens = [];

        if (this.hasSearchTarget) {
            this.searchTarget.value = '';
        }

        this.apply();
        this.searchTarget?.focus();
    }

    jump() {
        const month = this.jumpTarget.value;

        if (!month) {
            return;
        }

        let target = this.monthTarget(month);

        if (target && this.isFilteredOut(target)) {
            this.reset();
            target = this.monthTarget(month);
        }

        if (!target) {
            return;
        }

        // A past month of another year: choose that year first (series_archive_controller switches it in place)
        const year = target.closest('[data-series-archive-target~="year"]');

        if (year && year.hidden) {
            const chip = this.element.querySelector(`[data-series-archive-target~="chip"][data-year="${CSS.escape(year.dataset.year ?? '')}"]`);
            chip?.click();
        }

        if (target.tagName === 'DETAILS') {
            target.open = true;
        }

        // Only scrolls - the focus stays on the select: browsers fire `change` on every arrow key of a closed select,
        // moving the focus away would end the keyboard user's choosing after one step (WCAG 3.2.2)
        const reduced = window.matchMedia?.('(prefers-reduced-motion: reduce)').matches;
        target.scrollIntoView({ behavior: reduced ? 'auto' : 'smooth', block: 'start' });
    }

    // ---- the one place the page follows the filter --------------------------------------------------------------------

    apply() {
        const active = this.category !== '' || this.tokens.length > 0;
        let shown = 0;

        this.element.classList.toggle('is-filtering', active);

        this.chipTargets.forEach((chip) => {
            chip.setAttribute('aria-pressed', (chip.dataset.category ?? '') === this.category ? 'true' : 'false');
        });

        this.element.querySelectorAll('[data-search]').forEach((item) => {
            const match = !active || this.matches(item);
            item.toggleAttribute('data-filtered-out', !match);

            if (match) {
                shown++;
            }
        });

        // Upcoming month headers, Ongoing and Date not set: a header with its list
        this.element.querySelectorAll('[data-series-filter-group]').forEach((header) => {
            const list = header.nextElementSibling;
            const any = !active || (list !== null && this.anyShown(list));
            header.toggleAttribute('data-filtered-out', !any);
            list?.toggleAttribute('data-filtered-out', !any);
        });

        // Past months: open the ones holding a match while filtering, close them again afterwards
        this.element.querySelectorAll('details[data-series-past-month]').forEach((month) => {
            const any = !active || this.anyShown(month);
            month.toggleAttribute('data-filtered-out', !any);

            if (active && any && !month.open) {
                month.open = true;
                this.openedByFilter.add(month);
            }
        });

        if (!active) {
            this.openedByFilter.forEach((month) => { month.open = false; });
            this.openedByFilter.clear();
        }

        // Years and sections without a match step aside while filtering
        this.element.querySelectorAll('[data-series-archive-target~="year"], [data-series-upcoming], [data-series-past]').forEach((section) => {
            section.toggleAttribute('data-filtered-out', active && !this.anyShown(section));
        });

        if (this.hasNoneTarget) {
            this.noneTarget.hidden = !active || shown > 0;
        }
    }

    matches(item) {
        const categories = (item.dataset.categories ?? '').split(' ');
        const search = item.dataset.search ?? '';

        return (this.category === '' || categories.includes(this.category))
            && this.tokens.every((token) => search.includes(token));
    }

    anyShown(container) {
        return [...container.querySelectorAll('[data-search]')].some((item) => !item.hasAttribute('data-filtered-out'));
    }

    monthTarget(month) {
        return this.element.querySelector(`[data-series-jump="${CSS.escape(month)}"]`);
    }

    isFilteredOut(element) {
        return element.closest('[data-filtered-out]') !== null;
    }
}
