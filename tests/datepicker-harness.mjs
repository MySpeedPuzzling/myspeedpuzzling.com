// Runs assets/datepicker_locale.js (the date picker's first day of the week and weekday display) for what
// tests/DatePickerScriptTest.php hands over on stdin, and prints the results as JSON. `pickers` builds the real
// flatpickr (the project's node_modules) in jsdom with the options datepicker_controller.js hands it. The test runs
// this under several TZ values: flatpickr works on the browser's own wall time.

import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import { firstDayOfWeek, formatShown, pickerOptions, regionFirstDay } from '../assets/datepicker_locale.js';

const require = createRequire(import.meta.url);
const input = JSON.parse(readFileSync(0, 'utf8'));

// A browser without week info on Intl.Locale (Firefox): both ways of asking are taken away while `run` runs
function withoutWeekInfo(run) {
    const proto = Intl.Locale.prototype;
    const saved = ['getWeekInfo', 'weekInfo'].map((name) => [name, Object.getOwnPropertyDescriptor(proto, name)]);

    for (const [name, descriptor] of saved) {
        if (descriptor) {
            delete proto[name];
        }
    }

    try {
        return run();
    } finally {
        for (const [name, descriptor] of saved) {
            if (descriptor) {
                Object.defineProperty(proto, name, descriptor);
            }
        }
    }
}

const firstDay = ({ visitor, page, noWeekInfo }) => (noWeekInfo
    ? withoutWeekInfo(() => firstDayOfWeek(visitor, page))
    : firstDayOfWeek(visitor, page));

// Every region the engine knows: its own CLDR first day against the table used without week info
function regionMismatches() {
    const letters = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
    const mismatches = [];

    for (const a of letters) {
        for (const b of letters) {
            const region = a + b;
            let engine;

            try {
                engine = new Intl.Locale(`und-${region}`).getWeekInfo().firstDay % 7;
            } catch {
                continue;
            }

            const table = withoutWeekInfo(() => firstDayOfWeek(`und-${region}`, 'en'));

            if (engine !== table) {
                mismatches.push(`${region}: CLDR ${engine}, table ${table}`);
            }
        }
    }

    return mismatches;
}

// "2026-10-05" (+ "18:45") as flatpickr holds it: a Date at that wall time in the process's zone
function wallTime(day, time) {
    const [year, month, date] = day.split('-').map(Number);
    const [hours, minutes] = (time || '00:00').split(':').map(Number);

    return new Date(year, month - 1, date, hours, minutes);
}

function setupDom(lang) {
    const { JSDOM } = require('jsdom');
    const dom = new JSDOM(`<!doctype html><html lang="${lang}"><body><input type="text" class="date-picker"></body></html>`, { pretendToBeVisual: true });

    for (const name of ['window', 'document', 'HTMLElement', 'Node', 'Event', 'MouseEvent', 'KeyboardEvent', 'FocusEvent', 'CustomEvent']) {
        Object.defineProperty(globalThis, name, { value: name === 'window' ? dom.window : (name === 'document' ? dom.window.document : dom.window[name]), configurable: true, writable: true });
    }

    return dom;
}

const L10N = { cs: 'Czech', de: 'German', es: 'Spanish', fr: 'French', ja: 'Japanese' };

// One .date-picker input as the page renders it (value = what the server put in, e.g. on a 422 re-render), its
// picker built like datepicker_controller.js does, then optionally a click on a day of the open month
function picker({ lang, visitor, options, value, click }) {
    const dom = setupDom(lang);
    const flatpickr = require('flatpickr');
    const l10n = L10N[lang] ? require(`flatpickr/dist/l10n/${lang}.js`)[L10N[lang]] : {};
    const element = dom.window.document.querySelector('input');
    element.value = value;

    const instance = flatpickr(element, pickerOptions(options, { pageLang: lang, visitorLocale: visitor, l10n, defaultFormat: flatpickr.formatDate }));
    const days = () => [...instance.daysContainer.querySelectorAll('.flatpickr-day')];
    const result = {
        weekdays: instance.weekdayContainer ? [...instance.weekdayContainer.querySelectorAll('.flatpickr-weekday')].map((day) => day.textContent.trim()) : [],
        value: element.value,
        shown: instance.altInput ? instance.altInput.value : null,
        shownClass: instance.altInput ? instance.altInput.className : null,
        // The column (0 = first) of every day of the open month, by its aria label ("October 5, 2026")
        columns: instance.daysContainer ? Object.fromEntries(days().filter((day) => !day.classList.contains('prevMonthDay') && !day.classList.contains('nextMonthDay')).map((day) => [day.getAttribute('aria-label'), days().indexOf(day) % 7])) : {},
    };

    if (click) {
        const day = days().find((cell) => cell.getAttribute('aria-label') === click);
        day.dispatchEvent(new dom.window.MouseEvent('click', { bubbles: true }));
        result.clickedValue = element.value;
        result.clickedShown = instance.altInput ? instance.altInput.value : null;
    }

    instance.destroy();
    dom.window.close();

    return result;
}

process.stdout.write(JSON.stringify({
    firstDays: (input.firstDays ?? []).map(firstDay),
    regions: (input.regions ?? []).map((region) => regionFirstDay(region)),
    mismatches: input.parity ? regionMismatches() : [],
    shown: (input.shown ?? []).map(({ day, time, lang }) => formatShown(wallTime(day, time), lang, Boolean(time))),
    pickers: (input.pickers ?? []).map(picker),
}));
