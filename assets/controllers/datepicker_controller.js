import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    async connect() {
        let picker = document.querySelectorAll('.date-picker');

        if (picker.length === 0) return;

        const [{ default: flatpickr }, rangePluginModule, l10nModule] = await Promise.all([
            import('flatpickr'),
            import('flatpickr/dist/plugins/rangePlugin'),
            import('flatpickr/dist/l10n'),
        ]);

        await import('flatpickr/dist/flatpickr.min.css');

        const rangePlugin = rangePluginModule.default;
        const lang = document.documentElement.lang;

        if (lang === 'cs') {
            flatpickr.localize(flatpickr.l10ns.cs);
        } else {
            flatpickr.localize(flatpickr.l10ns.en);
            flatpickr.l10ns.default.firstDayOfWeek = 1;
        }

        for (let i = 0; i < picker.length; i++) {

            let defaults = {
                disableMobile: 'true'
            }

            let userOptions;
            if(picker[i].dataset.datepickerOptions != undefined) userOptions = JSON.parse(picker[i].dataset.datepickerOptions);
            let linkedInput = picker[i].classList.contains('date-range') ? {"plugins": [new rangePlugin({ input: picker[i].dataset.linkedInput })]} : '{}';
            let options = {...defaults, ...linkedInput, ...userOptions}

            flatpickr(picker[i], options);
        }

    }
}
