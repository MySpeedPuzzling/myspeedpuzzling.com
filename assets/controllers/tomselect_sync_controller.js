import { Controller } from '@hotwired/stimulus';
import TomSelect from 'tom-select';

/**
 * Enhances a <select> bound to a LiveComponent model with Tom Select.
 * Tom Select syncs its value back to the original select and fires a native
 * change event, which the on(change) model modifier picks up.
 *
 * The <option value=""> placeholder MUST stay in the select: LiveComponent
 * writes model values back onto the field after re-renders, and a single
 * select without an empty option cannot represent "no selection" - the
 * browser falls back to the first option, silently activating a bogus
 * filter. Tom Select uses the empty option as placeholder text and does
 * not offer it in the dropdown; clearing happens via the clear button.
 *
 * Usage:
 * <div data-live-ignore>
 *     <select data-controller="tomselect-sync" data-model="on(change)|brandId">
 *         <option value="">Placeholder</option>
 *         ...
 *     </select>
 * </div>
 */
export default class extends Controller {
    connect() {
        this.tomSelect = new TomSelect(this.element, {
            // No sortField override: options keep the order the server produced
            // (brands are intentionally ordered by popularity).
            plugins: {
                dropdown_input: {},
                clear_button: { title: '' },
            },
        });

        this.tomSelect.on('change', () => {
            this.tomSelect.blur();
        });
    }

    disconnect() {
        if (this.tomSelect) {
            this.tomSelect.destroy();
        }
    }
}
