/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus';

/**
 * Light dismiss for a <details> that opens over the page (docs/features/navigation NAV review F6): the sign-in /
 * sign-up header's language switch (templates/navigation/_auth_header.html.twig). The <details> works before any script
 * (implementation plan §3.2); this only closes it the way the old Bootstrap dropdown did:
 * - Esc anywhere inside it closes it and puts the focus on its <summary>;
 * - a tap or a click outside it closes it (the focus goes where the tap went). pointerdown, not click: iOS sends no
 *   click for a tap on something that is not clickable.
 * Nothing else changes: the summary still toggles it, the links inside still navigate.
 */
export default class extends Controller {
    connect() {
        this.element.addEventListener('keydown', this.handleKeydown);
        document.addEventListener('pointerdown', this.handleOutside, true);
    }

    disconnect() {
        this.element.removeEventListener('keydown', this.handleKeydown);
        document.removeEventListener('pointerdown', this.handleOutside, true);
    }

    handleKeydown = (event) => {
        if (event.key !== 'Escape' || event.defaultPrevented || !this.element.open) {
            return;
        }

        event.preventDefault();
        this.element.open = false;

        const summary = this.element.querySelector(':scope > summary');

        if (summary) {
            summary.focus({ preventScroll: true });
        }
    };

    handleOutside = (event) => {
        if (!this.element.open || (event.target instanceof Node && this.element.contains(event.target))) {
            return;
        }

        this.element.open = false;
    };
}
