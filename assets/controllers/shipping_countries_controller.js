import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['checkbox'];

    selectAll() {
        this.checkboxTargets.forEach(checkbox => checkbox.checked = true);
    }

    deselectAll() {
        this.checkboxTargets.forEach(checkbox => checkbox.checked = false);
    }

    selectEu() {
        const euCodes = [
            'at', 'be', 'bg', 'hr', 'cy', 'cz', 'dk', 'ee', 'fi', 'fr',
            'de', 'gr', 'hu', 'ie', 'it', 'lv', 'lt', 'lu', 'mt', 'nl',
            'pl', 'pt', 'ro', 'sk', 'si', 'es', 'se',
        ];
        this._selectCodes(euCodes);
    }

    selectEurope() {
        const europeCodes = [
            'al', 'ad', 'at', 'by', 'be', 'ba', 'bg', 'hr', 'cy', 'cz',
            'dk', 'ee', 'fi', 'fr', 'de', 'gr', 'hu', 'is', 'ie', 'it',
            'lv', 'li', 'lt', 'lu', 'mk', 'mt', 'md', 'mc', 'me', 'nl',
            'no', 'pl', 'pt', 'ro', 'ru', 'rs', 'sk', 'si', 'es', 'se',
            'ch', 'ua', 'gb',
        ];
        this._selectCodes(europeCodes);
    }

    _selectCodes(codes) {
        this.checkboxTargets.forEach(checkbox => {
            checkbox.checked = codes.includes(checkbox.value);
        });
    }
}
