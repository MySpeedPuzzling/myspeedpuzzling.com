import { Controller } from '@hotwired/stimulus';
import { chooseStep, readStep } from '../bottom_nav_labels.js';

// The step's custom property, set on the list by the container queries of assets/styles/_nav-label-steps.scss
const CSS_STEP_PROPERTY = '--nav-label-step';

/**
 * The phone bottom bar's labels (docs/features/navigation README D14; implementation plan NAV-02): CSS picks the step
 * (1 = 11 px, 2 = 10 px, 3 = only the current tab's label) before first paint from the measured table; this controller
 * looks again once the bar is on screen, when the fonts are ready and whenever the bar changes width, and raises the
 * step (data-label-step-override) only if a visible label still does not fit - never lower than CSS. It then publishes
 * the step it ends on as data-label-step and dispatches `nav:labels-fitted` {step, cssStep} (GEST-18 reads both).
 * Loaded eagerly: the bar is on every web page and the check must run before anybody looks at a cut label.
 * Measuring and setting the attribute happen in one task (ResizeObserver callbacks run before paint): no flash.
 */
export default class extends Controller {
    connect() {
        this.fit();

        if (typeof ResizeObserver === 'function') {
            this.observer = new ResizeObserver(() => this.fit());
            this.observer.observe(this.element);
        }

        if (document.fonts && document.fonts.ready && typeof document.fonts.ready.then === 'function') {
            document.fonts.ready.then(() => {
                if (this.element.isConnected) {
                    this.fit();
                }
            });
        }
    }

    disconnect() {
        if (this.observer) {
            this.observer.disconnect();
            this.observer = null;
        }
    }

    fit() {
        const bar = this.element;
        const list = bar.querySelector('.nav-places');

        // Hidden (hide_bottom_bar) or display: none (desktop): nothing to measure
        if (!list || bar.hidden || bar.getClientRects().length === 0) {
            return;
        }

        // Measure in the step CSS chose (an override from a narrower width must not hide that the room grew)
        bar.removeAttribute('data-label-step-override');

        const cssStep = readStep(getComputedStyle(list).getPropertyValue(CSS_STEP_PROPERTY));
        const labels = Array.from(list.querySelectorAll('.nav-tab-label'));
        let step = cssStep;

        if (labels.length > 0) {
            step = chooseStep({ cssStep, room: this.room(labels[0].parentElement), widths: labels.map((label) => this.naturalWidth(label)) });
        }

        if (step > cssStep) {
            bar.setAttribute('data-label-step-override', String(step));
        }

        bar.setAttribute('data-label-step', String(step));
        bar.dispatchEvent(new CustomEvent('nav:labels-fitted', { bubbles: true, detail: { step, cssStep } }));
    }

    /** The width a label may use: the tab's content box */
    room(tab) {
        if (!tab) {
            return 0;
        }

        const style = getComputedStyle(tab);

        return tab.getBoundingClientRect().width - (parseFloat(style.paddingLeft) || 0) - (parseFloat(style.paddingRight) || 0);
    }

    /** The label text's own width, fractional - even while its box cuts it (overflow: hidden) */
    naturalWidth(label) {
        const range = document.createRange();
        range.selectNodeContents(label);
        const width = range.getBoundingClientRect().width;
        range.detach();

        return width;
    }
}
