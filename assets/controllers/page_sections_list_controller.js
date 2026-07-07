import { Controller } from '@hotwired/stimulus';

/**
 * Drag-to-reorder + show/hide for competition page sections. Every change posts
 * the full ordered layout (system keys + custom:<uuid> entries with visibility).
 */
export default class extends Controller {
    static targets = ['list'];
    static values = {
        reorderUrl: String,
        ownerType: String,
        ownerId: String,
    };

    async connect() {
        const { default: Sortable } = await import('sortablejs');

        this.sortable = Sortable.create(this.listTarget, {
            handle: '[data-drag-handle]',
            animation: 150,
            filter: '[data-fixed]',
            onMove: (event) => !event.related.hasAttribute('data-fixed') || event.willInsertAfter,
            onEnd: () => this.persistLayout(),
        });
    }

    disconnect() {
        if (this.sortable) {
            this.sortable.destroy();
        }
    }

    toggleVisibility(event) {
        const item = event.target.closest('[data-section-key]');
        if (!item) return;

        const visible = item.dataset.visible !== 'true';
        item.dataset.visible = visible ? 'true' : 'false';
        item.classList.toggle('opacity-50', !visible);

        const icon = event.target.closest('button').querySelector('i');
        if (icon) {
            icon.className = visible ? 'bi bi-eye' : 'bi bi-eye-slash';
        }

        this.persistLayout();
    }

    async persistLayout() {
        const layout = [...this.listTarget.querySelectorAll('[data-section-key]')].map((item) => ({
            section: item.dataset.sectionKey,
            visible: item.dataset.visible === 'true',
        }));

        const payload = { layout };
        if (this.ownerTypeValue === 'competition') {
            payload.competitionId = this.ownerIdValue;
        } else {
            payload.seriesId = this.ownerIdValue;
        }

        try {
            await fetch(this.reorderUrlValue, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
                body: JSON.stringify(payload),
            });
        } catch (e) {
            // Next successful change re-sends the full layout, so a lost request self-heals
        }
    }
}
