import { Controller } from '@hotwired/stimulus';
import TomSelect from 'tom-select';

export default class extends Controller {
    connect() {
        this.tomSelect = new TomSelect(this.element, {
            allowEmptyOption: true,
            render: {
                option: function(data, escape) {
                    const icon = data.$option ? data.$option.getAttribute('data-icon') : null;
                    return `<div>${icon ? `<i class="${escape(icon)} shadow-custom me-1"></i> ` : ''}${escape(data.text)}</div>`;
                },
                item: function(data, escape) {
                    const icon = data.$option ? data.$option.getAttribute('data-icon') : null;
                    return `<div>${icon ? `<i class="${escape(icon)} shadow-custom me-1 flex-shrink-0"></i> ` : ''}${escape(data.text)}</div>`;
                }
            }
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
