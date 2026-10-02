import { Controller } from '@hotwired/stimulus';

/**
 * Shares a link (the player header's Share button and "Share profile" menu items): the system share sheet where the
 * browser has one, otherwise the link is copied and the button says so for a moment. Texts come from data attributes.
 */
export default class extends Controller {
    static targets = ['label', 'icon'];

    static values = {
        url: String,
        title: String,
        copiedLabel: String,
    };

    disconnect() {
        clearTimeout(this.restoreTimeout);
        clearTimeout(this.closeMenuTimeout);
    }

    async share(event) {
        if (typeof navigator.share === 'function') {
            try {
                await navigator.share({ title: this.titleValue, url: this.urlValue });

                return;
            } catch (error) {
                // The visitor closed the sheet
                if (error.name === 'AbortError') {
                    return;
                }
            }
        } else {
            // A menu item: the menu stays open long enough to read "Link copied"
            event.stopPropagation();
        }

        try {
            await navigator.clipboard.writeText(this.urlValue);
        } catch (error) {
            console.error('Copying the link failed', error);

            return;
        }

        this.showCopied();
    }

    showCopied() {
        if (this.originalLabel === undefined) {
            this.originalLabel = this.labelTarget.textContent;
            this.originalIcon = this.hasIconTarget ? this.iconTarget.className : null;
        }

        this.labelTarget.textContent = this.copiedLabelValue;

        if (this.hasIconTarget) {
            this.iconTarget.className = this.originalIcon.replace(/\bbi-[\w-]+/, 'bi-check2');
        }

        clearTimeout(this.restoreTimeout);
        this.restoreTimeout = setTimeout(() => {
            this.labelTarget.textContent = this.originalLabel;

            if (this.hasIconTarget) {
                this.iconTarget.className = this.originalIcon;
            }
        }, 2000);

        const menu = this.element.closest('.dropdown');

        if (menu !== null) {
            clearTimeout(this.closeMenuTimeout);
            this.closeMenuTimeout = setTimeout(() => {
                const toggle = menu.querySelector('[data-bs-toggle="dropdown"]');
                window.bootstrap?.Dropdown.getInstance(toggle)?.hide();
            }, 1200);
        }
    }
}
