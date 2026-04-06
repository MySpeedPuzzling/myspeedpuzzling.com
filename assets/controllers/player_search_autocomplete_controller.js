import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static values = {
        url: String,
    };

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
        const url = this.urlValue;

        event.detail.options.shouldLoad = (query) => query.length >= 2;

        event.detail.options.score = () => () => 1;

        event.detail.options.render = {
            ...event.detail.options.render,
            option: (item) => `<div>${item.text}</div>`,
            item: (item) => `<div>${item.text}</div>`,
        };

        event.detail.options.load = function (query, callback) {
            fetch(`${url}?query=${encodeURIComponent(query)}`)
                .then(response => response.json())
                .then(data => callback(data))
                .catch(() => callback());
        };
    }
}
