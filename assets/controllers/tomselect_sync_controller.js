import { Controller } from '@hotwired/stimulus';
import TomSelect from 'tom-select';

/**
 * Bridges Tom Select with LiveComponent.
 *
 * Usage:
 * <div data-live-ignore>
 *   <input type="hidden" data-model="brandId" data-tomselect-sync-target="hidden">
 *   <select data-controller="tomselect-sync" data-tomselect-sync-target="select">
 *     ...
 *   </select>
 * </div>
 */
export default class extends Controller {
    static targets = ['hidden', 'select'];

    tomSelect = null;

    connect() {
        this.tomSelect = new TomSelect(this.selectTarget, {
            create: false,
            sortField: { field: 'text', direction: 'asc' },
            plugins: ['dropdown_input'],
        });

        this.tomSelect.on('change', (value) => {
            this.hiddenTarget.value = value || '';
            this.hiddenTarget.dispatchEvent(new Event('input', { bubbles: true }));
        });
    }

    disconnect() {
        if (this.tomSelect) {
            this.tomSelect.destroy();
        }
    }
}
