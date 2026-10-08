/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus';

/**
 * The series page's past by year (docs/features/events-page/detail-pages.md "Series page", templates/series/_past.html.twig).
 * The server renders every year under its own heading with all its lines - that is the page without JavaScript. On
 * connect this shows the year chips (two years or more), keeps one year open (the newest), shows its first `preview`
 * lines and its "Show all 2026 (14)" button. A chip switches the year in place; "Show all" reveals the rest of that
 * year and moves the focus to the first line it revealed. Nothing is fetched, nothing is stored.
 */
export default class extends Controller {
    static targets = ['chips', 'chip', 'year', 'heading', 'more'];
    static values = { preview: { type: Number, default: 5 } };

    connect() {
        this.expanded = new Set();
        const withChips = this.hasChipsTarget && this.chipTargets.length > 1;

        if (withChips) {
            this.chipsTarget.hidden = false;
            // The chips name the year now; the headings stay for screen readers and the outline
            this.headingTargets.forEach((heading) => heading.classList.add('visually-hidden'));
        }

        const pressed = this.chipTargets.find((chip) => chip.getAttribute('aria-pressed') === 'true');
        const first = this.yearTargets[0];

        this.show(pressed?.dataset.year ?? first?.dataset.year ?? null, withChips);
    }

    disconnect() {
        // Back to the server's markup, so a Turbo snapshot or a reconnect starts from the same state
        if (this.hasChipsTarget) {
            this.chipsTarget.hidden = true;
        }

        this.headingTargets.forEach((heading) => heading.classList.remove('visually-hidden'));
        this.yearTargets.forEach((year) => {
            year.hidden = false;
            this.linesOf(year).forEach((line) => { line.hidden = false; });
        });
        this.moreTargets.forEach((more) => { more.hidden = true; });
    }

    choose(event) {
        const year = event.currentTarget.dataset.year;

        if (year) {
            this.show(year, true);
        }
    }

    showAll(event) {
        const section = event.currentTarget.closest('[data-series-archive-target~="year"]');

        if (!section) {
            return;
        }

        this.expanded.add(section.dataset.year);
        const firstHidden = this.linesOf(section).find((line) => line.hidden);
        this.showYear(section);

        firstHidden?.querySelector('a')?.focus();
    }

    show(year, onlyThisYear) {
        this.chipTargets.forEach((chip) => {
            chip.setAttribute('aria-pressed', chip.dataset.year === year ? 'true' : 'false');
        });

        this.yearTargets.forEach((section) => {
            section.hidden = onlyThisYear && section.dataset.year !== year;
            this.showYear(section);
        });
    }

    showYear(section) {
        const all = this.expanded.has(section.dataset.year);
        const lines = this.linesOf(section);

        lines.forEach((line, index) => {
            line.hidden = !all && index >= this.previewValue;
        });

        const more = this.moreTargets.find((button) => section.contains(button));

        if (more) {
            more.hidden = all || lines.length <= this.previewValue;
        }
    }

    linesOf(section) {
        return Array.from(section.querySelectorAll('.ev-lines > li'));
    }
}
