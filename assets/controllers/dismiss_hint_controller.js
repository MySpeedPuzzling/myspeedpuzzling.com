import { Controller } from '@hotwired/stimulus';

/**
 * A dismissable hint (docs/features/hint-dismissing.md): × posts its type once and removes the hint - every copy of it
 * on the page (the navigation launch's pointer is on the Hub and at the top of the Menu at once, docs/features/navigation
 * decision 52). When the focus was inside the hint (its ×), it moves on before the hint goes, so it never falls to
 * <body> behind an open Menu layer (WCAG 2.4.3, LAUNCH review F5): in the Menu its first row, elsewhere the page's
 * heading.
 */
export default class extends Controller {
    static values = {
        url: String,
        type: String,
    };

    dismiss() {
        fetch(this.urlValue, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: `type=${this.typeValue}`,
        });

        if (this.element.contains(document.activeElement)) {
            this.moveFocus();
        }

        document.querySelectorAll('[data-controller~="dismiss-hint"]').forEach((element) => {
            if (element !== this.element && element.getAttribute('data-dismiss-hint-type-value') === this.typeValue) {
                element.remove();
            }
        });

        this.element.remove();
    }

    moveFocus() {
        const menu = this.element.closest('#site-menu');
        const target = menu !== null ? this.firstMenuRow(menu) : this.pageHeading();

        if (target === null) {
            return;
        }

        if (!target.matches('a[href], button, input, select, textarea, [tabindex]')) {
            target.setAttribute('tabindex', '-1');
        }

        target.focus();
    }

    /** The Menu's first row link that is shown (a row of the other surface is display: none there) */
    firstMenuRow(menu) {
        const rows = Array.from(menu.querySelectorAll('li a[href]')).filter((link) => !this.element.contains(link));

        return rows.find((link) => link.getClientRects().length > 0) ?? rows[0] ?? null;
    }

    pageHeading() {
        return document.querySelector('main h1') ?? document.querySelector('main');
    }
}
