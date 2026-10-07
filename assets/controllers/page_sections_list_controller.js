/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus';

/**
 * Page editor (manage_page_sections.html.twig): orders the page's own sections by drag (SortableJS, loaded only here)
 * or by the Move up / Move down buttons, which work by keyboard and on phones where a long-press drag is awkward.
 * Every change posts the whole order (ReorderPageSectionsController); saves go out one after another, each with the
 * order at the moment it is sent. A refused or failed save is said in the page - a reload shows the stored order.
 */
export default class extends Controller {
    static targets = ['list', 'error'];

    static values = {
        url: String,
        ownerField: String,
        ownerId: String,
        token: String,
        failedMessage: String,
    };

    async connect() {
        this.saving = Promise.resolve();

        if (!this.hasListTarget) {
            return;
        }

        this.updateButtons();

        const { default: Sortable } = await import('sortablejs');

        if (!this.element.isConnected || !this.hasListTarget) {
            return;
        }

        this.sortable = Sortable.create(this.listTarget, {
            handle: '[data-drag-handle]',
            animation: 150,
            onEnd: (event) => {
                if (event.oldIndex !== event.newIndex) {
                    this.changed();
                }
            },
        });
    }

    disconnect() {
        this.sortable?.destroy();
        this.sortable = null;
    }

    moveUp(event) {
        this.move(event.currentTarget, 'up');
    }

    moveDown(event) {
        this.move(event.currentTarget, 'down');
    }

    move(button, direction) {
        const item = button.closest('[data-section-id]');
        const sibling = direction === 'up' ? item?.previousElementSibling : item?.nextElementSibling;

        if (!item || !sibling) {
            return;
        }

        if (direction === 'up') {
            sibling.before(item);
        } else {
            sibling.after(item);
        }

        this.changed();

        // The focus stays with the moved section - on the other button once this one is disabled at the end of the list
        const same = item.querySelector(`[data-move="${direction}"]`);
        const other = item.querySelector(`[data-move="${direction === 'up' ? 'down' : 'up'}"]`);
        (same && !same.disabled ? same : other)?.focus();
    }

    changed() {
        this.updateButtons();
        this.saving = this.saving.then(() => this.save());
    }

    items() {
        return [...this.listTarget.querySelectorAll(':scope > [data-section-id]')];
    }

    updateButtons() {
        const items = this.items();

        items.forEach((item, index) => {
            const up = item.querySelector('[data-move="up"]');
            const down = item.querySelector('[data-move="down"]');

            if (up) {
                up.disabled = index === 0;
            }

            if (down) {
                down.disabled = index === items.length - 1;
            }
        });
    }

    async save() {
        const body = new FormData();
        body.append(this.ownerFieldValue, this.ownerIdValue);
        body.append('_token', this.tokenValue);
        this.items().forEach((item) => body.append('sections[]', item.dataset.sectionId));

        try {
            // A redirect means the session is gone (sign-in page) - never a success
            const response = await fetch(this.urlValue, {
                method: 'POST',
                body,
                headers: { Accept: 'application/json' },
                redirect: 'manual',
            });

            if (response.ok) {
                this.showError(null);

                return;
            }

            const data = await response.json().catch(() => ({}));
            this.showError(typeof data.error === 'string' && data.error !== '' ? data.error : this.failedMessageValue);
        } catch (error) {
            this.showError(this.failedMessageValue);
        }
    }

    showError(message) {
        if (!this.hasErrorTarget) {
            return;
        }

        this.errorTarget.textContent = message ?? '';
        this.errorTarget.classList.toggle('d-none', message === null);
    }
}
