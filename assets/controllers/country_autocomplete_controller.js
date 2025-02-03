import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    initialize() {
        this._onPreConnect = this._onPreConnect.bind(this);
    }

    connect() {
        this.element.addEventListener('autocomplete:pre-connect', this._onPreConnect);
    }

    disconnect() {
        this.element.removeEventListener('autocomplete:pre-connect', this._onPreConnect);
    }

    _onPreConnect(event) {
        event.detail.options.render = {
            option: function(data, escape) {
                return `<div>
                            ${data.icon ? `<i class="${escape(data.icon)} shadow-custom me-1"></i> ` : ''}
                            ${escape(data.text)}
                        </div>`;
            },
            item: function(data, escape) {
                return `<div>
                            ${data.icon ? `<i class="${escape(data.icon)} shadow-custom me-1"></i> ` : ''}
                            ${escape(data.text)}
                        </div>`;
            }
        };
    }
}
