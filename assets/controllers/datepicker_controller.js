import { Controller } from '@hotwired/stimulus';
import { labelShownInput, pickerOptions } from '../datepicker_locale.js';
import { BEFORE_CACHE_EVENT } from '../instant_back.js';

// The page language's month and weekday names, loaded only on a page that needs them (English is flatpickr's own)
const L10N = {
    cs: () => import('flatpickr/dist/esm/l10n/cs.js').then((module) => module.Czech),
    de: () => import('flatpickr/dist/esm/l10n/de.js').then((module) => module.German),
    es: () => import('flatpickr/dist/esm/l10n/es.js').then((module) => module.Spanish),
    fr: () => import('flatpickr/dist/esm/l10n/fr.js').then((module) => module.French),
    ja: () => import('flatpickr/dist/esm/l10n/ja.js').then((module) => module.Japanese),
};

/*
 * Every `.date-picker` input gets a flatpickr with its `data-datepicker-options`. The first day of the week is the
 * visitor's and a field with `altInput` shows the day with its weekday ("Mon, 5 Oct 2026") - assets/datepicker_locale.js.
 *
 * The pickers it made are torn down again (their shown-date inputs and calendars gone, the original fields back) when the
 * page goes (disconnect) and when Turbo caches it (GEST-15, `msp:before-cache` of assets/instant_back.js): a restored
 * page gets fresh pickers, never a second shown-date input next to a copied one.
 */
export default class extends Controller {
    instances = [];

    async connect() {
        this.connected = true;
        this.onBeforeCache = () => this.teardown();
        document.addEventListener(BEFORE_CACHE_EVENT, this.onBeforeCache);

        const pickers = document.querySelectorAll('.date-picker');

        if (pickers.length === 0) return;

        const pageLang = document.documentElement.lang;
        const [{ default: flatpickr }, l10n] = await Promise.all([
            import('flatpickr'),
            L10N[pageLang] ? L10N[pageLang]() : Promise.resolve({}),
        ]);

        await import('flatpickr/dist/flatpickr.min.css');

        // A shown-date input of an earlier run carries the field's classes (.date-picker too) - it is no field itself
        const shownInputs = new Set([...pickers].map((picker) => picker._flatpickr?.altInput).filter(Boolean));

        for (const picker of pickers) {
            if (shownInputs.has(picker)) continue;

            const userOptions = picker.dataset.datepickerOptions !== undefined ? JSON.parse(picker.dataset.datepickerOptions) : {};

            const instance = flatpickr(picker, pickerOptions(userOptions, {
                pageLang,
                visitorLocale: navigator.language,
                l10n,
                defaultFormat: flatpickr.formatDate,
            }));

            // The label points at the hidden original - the visible input gets its name
            labelShownInput(picker, instance.altInput);
            this.instances.push(instance);
        }

        // The page went while flatpickr loaded
        if (!this.connected) {
            this.teardown();
        }
    }

    disconnect() {
        this.connected = false;
        document.removeEventListener(BEFORE_CACHE_EVENT, this.onBeforeCache);
        this.teardown();
    }

    teardown() {
        const instances = this.instances;
        this.instances = [];

        for (const instance of instances) {
            try {
                if (instance.input && instance.input._flatpickr === instance) {
                    instance.destroy();
                }
            } catch {
                // already gone with its page
            }
        }
    }
}
