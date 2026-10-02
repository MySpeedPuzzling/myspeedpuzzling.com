import { Controller } from '@hotwired/stimulus';

/**
 * A row of tabs that scrolls sideways when it does not fit (the player header's page tabs): the current tab is
 * scrolled into view on load, and `can-scroll-start` / `can-scroll-end` on the element let the CSS fade the edge that
 * hides more tabs.
 */
export default class extends Controller {
    static targets = ['scroller'];

    connect() {
        this.update = () => this.updateEdges();
        this.scrollerTarget.addEventListener('scroll', this.update, { passive: true });

        if (typeof ResizeObserver !== 'undefined') {
            this.resizeObserver = new ResizeObserver(this.update);
            this.resizeObserver.observe(this.scrollerTarget);
        }

        this.revealCurrent();
        this.updateEdges();
    }

    disconnect() {
        this.scrollerTarget.removeEventListener('scroll', this.update);
        this.resizeObserver?.disconnect();
    }

    // scrollLeft, not scrollIntoView(): that can scroll the page itself as well
    revealCurrent() {
        const scroller = this.scrollerTarget;
        const current = scroller.querySelector('[aria-current]');

        if (current === null || scroller.scrollWidth <= scroller.clientWidth) {
            return;
        }

        const offset = current.getBoundingClientRect().left - scroller.getBoundingClientRect().left;
        scroller.scrollLeft += offset - (scroller.clientWidth - current.offsetWidth) / 2;
    }

    updateEdges() {
        const scroller = this.scrollerTarget;
        const hidden = scroller.scrollWidth - scroller.clientWidth;

        this.element.classList.toggle('can-scroll-start', hidden > 1 && scroller.scrollLeft > 1);
        this.element.classList.toggle('can-scroll-end', hidden > 1 && scroller.scrollLeft < hidden - 1);
    }
}
