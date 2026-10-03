/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus';

/**
 * Table view of the compare page (docs/features/player-comparison.md): keeps the subjects' header row on screen while
 * the page scrolls.
 *
 * The table scrolls sideways inside its own box (.cmp-table-wrap, overflow-x), and such a box clips a sticky <thead> to
 * itself - the header could never stick to the page. So the real header row is copied into a pinned strip right above
 * the box (sticky under the site header, zero height, its content hanging over the first rows), shown only while the
 * real header is scrolled away, with the table's column widths and its sideways offset. One page scroll, no nested
 * vertical scroller; the copy's first column is sticky like the table's.
 *
 * The strip is data-live-ignore: the server renders it empty and only this controller fills it. After a re-render of
 * the component the header is copied again (MutationObserver on the real <thead>) and the columns re-measured
 * (ResizeObserver on the table: "Show more" rows, another screen width, late fonts and avatars).
 */
export default class extends Controller {
    static targets = ['float', 'wrap', 'table'];

    connect() {
        this.frame = null;
        this.needsCopy = true;
        this.needsMeasure = true;

        this.scroller = document.createElement('div');
        this.scroller.className = 'cmp-table-float__scroller';
        this.copy = document.createElement('table');
        this.copy.className = 'cmp-table';
        this.scroller.appendChild(this.copy);
        this.floatTarget.replaceChildren(this.scroller);

        this.onPageChange = () => this.schedule(false);
        window.addEventListener('scroll', this.onPageChange, { passive: true });
        window.addEventListener('resize', this.onPageChange, { passive: true });

        this.resizeObserver = new ResizeObserver(() => this.schedule(false, true));
        this.mutationObserver = new MutationObserver(() => this.schedule(true));
        this.observeTable();
        this.schedule(true);
    }

    disconnect() {
        window.removeEventListener('scroll', this.onPageChange);
        window.removeEventListener('resize', this.onPageChange);
        this.resizeObserver.disconnect();
        this.mutationObserver.disconnect();

        if (this.frame !== null) {
            cancelAnimationFrame(this.frame);
            this.frame = null;
        }
    }

    // A re-render may hand over another <table> element (called before connect() for the first one - nothing to do yet)
    tableTargetConnected() {
        if (this.resizeObserver) {
            this.observeTable();
            this.schedule(true);
        }
    }

    syncScroll() {
        this.scroller.scrollLeft = this.wrapTarget.scrollLeft;
    }

    observeTable() {
        this.resizeObserver.disconnect();
        this.mutationObserver.disconnect();

        if (!this.hasTableTarget) {
            return;
        }

        this.resizeObserver.observe(this.tableTarget);

        if (this.tableTarget.tHead) {
            this.mutationObserver.observe(this.tableTarget.tHead, { subtree: true, childList: true, characterData: true, attributes: true });
        }
    }

    schedule(copy, measure = false) {
        this.needsCopy = this.needsCopy || copy;
        this.needsMeasure = this.needsMeasure || measure || copy;

        if (this.frame !== null) {
            return;
        }

        this.frame = requestAnimationFrame(() => {
            this.frame = null;

            if (this.needsCopy) {
                this.copyHead();
            }

            if (this.needsMeasure) {
                this.measure();
            }

            this.needsCopy = false;
            this.needsMeasure = false;
            this.place();
        });
    }

    copyHead() {
        const head = this.hasTableTarget ? this.tableTarget.tHead : null;

        if (!head) {
            this.copy.replaceChildren();

            return;
        }

        const clone = head.cloneNode(true);
        // Decoration only (the strip is aria-hidden): no duplicate ids
        clone.querySelectorAll('[id]').forEach((element) => element.removeAttribute('id'));

        this.copy.replaceChildren(document.createElement('colgroup'), clone);
    }

    // The copy's columns as wide as the table's: a fixed layout taking its widths from <col>s
    measure() {
        const row = this.hasTableTarget && this.tableTarget.tHead ? this.tableTarget.tHead.rows[0] : null;
        const colgroup = this.copy.querySelector('colgroup');

        if (!row || !colgroup) {
            return;
        }

        const columns = Array.from(row.cells).map((cell) => {
            const column = document.createElement('col');
            column.style.width = `${cell.getBoundingClientRect().width}px`;

            return column;
        });

        colgroup.replaceChildren(...columns);
        this.copy.style.width = `${this.tableTarget.getBoundingClientRect().width}px`;
        this.syncScroll();
    }

    // Shown while the real header is under the site header and enough of the table is still on screen
    place() {
        if (!this.hasTableTarget || !this.tableTarget.tHead) {
            this.floatTarget.classList.remove('is-pinned');

            return;
        }

        const top = parseFloat(getComputedStyle(this.floatTarget).top) || 0;
        const head = this.tableTarget.tHead.getBoundingClientRect();
        const table = this.tableTarget.getBoundingClientRect();
        const pinned = head.top < top && table.bottom > top + head.height * 2;

        this.floatTarget.classList.toggle('is-pinned', pinned);
    }
}
