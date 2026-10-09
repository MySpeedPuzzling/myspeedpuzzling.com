// How the date picker (controllers/datepicker_controller.js) fits the visitor - pure functions, pinned by
// tests/DatePickerScriptTest.php under node (with the real flatpickr in jsdom).
//
// - The calendar starts the week on the VISITOR's first day, from their browser's locale: an American organiser
//   reads Sunday first, and on a Monday-first grid clicked one column too far right - a Monday bar night was saved as
//   Tuesday. Czech pages keep Monday.
// - The field shows the picked day with its weekday in the page's language, like the events pages ("Mon, 5 Oct 2026",
//   "po 5. 10. 2026", "2026年10月5日(月)"; ICU skeleton yMMMEd, English as en-GB). Only the shown text changes: the
//   submitted value keeps the form's own format (`dateFormat`), so the server reads it exactly as before.

// CLDR weekData `firstDay` (ICU 78 / CLDR 48) for browsers without week info on Intl.Locale (Firefox): the regions
// whose week starts on Sunday, Saturday or Friday - every other region starts it on Monday
const SUNDAY_FIRST = new Set([
    'AG', 'AS', 'BD', 'BR', 'BS', 'BT', 'BW', 'BZ', 'CA', 'CO', 'DM', 'DO', 'ET', 'GT', 'GU', 'HK', 'HN', 'ID', 'IL',
    'IN', 'IS', 'JM', 'JP', 'KE', 'KH', 'KR', 'LA', 'MH', 'MM', 'MO', 'MT', 'MX', 'MZ', 'NI', 'NP', 'PA', 'PE', 'PH',
    'PK', 'PR', 'PT', 'PY', 'SA', 'SG', 'SV', 'TH', 'TT', 'TW', 'UM', 'US', 'VE', 'VI', 'WS', 'YE', 'ZA', 'ZW',
]);
const SATURDAY_FIRST = new Set(['AF', 'BH', 'DJ', 'DZ', 'EG', 'IQ', 'IR', 'JO', 'KW', 'LY', 'OM', 'QA', 'SD', 'SY']);
const FRIDAY_FIRST = new Set(['MV']);

const MONDAY = 1;

/**
 * The region's first day of the week as flatpickr counts it (0 = Sunday … 6 = Saturday), from the CLDR table above.
 */
export function regionFirstDay(region) {
    const code = String(region || '').toUpperCase();

    if (SUNDAY_FIRST.has(code)) {
        return 0;
    }

    if (SATURDAY_FIRST.has(code)) {
        return 6;
    }

    return FRIDAY_FIRST.has(code) ? 5 : MONDAY;
}

/**
 * flatpickr's `firstDayOfWeek` (0 = Sunday … 6 = Saturday) for a visitor whose browser speaks `visitorLocale`
 * (navigator.language: "en-US", "en", "cs-CZ") on a page in `pageLang` (<html lang>). Czech pages keep Monday.
 * The browser's own week info first (Intl.Locale getWeekInfo(), older engines the `weekInfo` getter; firstDay
 * 1 = Monday … 7 = Sunday), else the locale's region - written or likely ("en" = US) - in the CLDR table; Monday when
 * nothing answers.
 */
export function firstDayOfWeek(visitorLocale, pageLang) {
    if (String(pageLang || '').toLowerCase() === 'cs') {
        return MONDAY;
    }

    try {
        const locale = new Intl.Locale(visitorLocale || 'en');
        const info = typeof locale.getWeekInfo === 'function' ? locale.getWeekInfo() : locale.weekInfo;
        const firstDay = info?.firstDay;

        if (Number.isInteger(firstDay) && firstDay >= 1 && firstDay <= 7) {
            return firstDay % 7;
        }

        return regionFirstDay(locale.region ?? locale.maximize().region);
    } catch {
        return MONDAY;
    }
}

/**
 * The locale the field shows dates in (EventsPageDates::dateLocale(), dateLocale() of events_index.js): the page's,
 * English as en-GB.
 */
export function displayLocale(pageLang) {
    const locale = String(pageLang || '').replace('_', '-');

    return locale === 'en' ? 'en-GB' : (locale || undefined);
}

const DAY = { weekday: 'short', day: 'numeric', month: 'short', year: 'numeric' };
// 24 hours, two-digit, like the pickers' `time_24hr` and the events pages' times
const TIME = { hour: '2-digit', minute: '2-digit', hourCycle: 'h23' };

/**
 * The text the field shows for a picked `date` (a Date at the browser's own wall time, as flatpickr makes them, so no
 * zone is applied): "Mon, 5 Oct 2026", with `withTime` "Mon, 5 Oct 2026, 18:45".
 */
export function formatShown(date, pageLang, withTime = false) {
    return new Intl.DateTimeFormat(displayLocale(pageLang), withTime ? { ...DAY, ...TIME } : DAY).format(date);
}

/**
 * The visible input of a field with `altInput` gets the field's accessible name: its <label for> points at the hidden
 * original, so the shown input is labelled by that label (given an id when it has none) and described like the
 * original (help, errors). Nothing changes for an input without a label or without a second input.
 */
export function labelShownInput(original, shownInput) {
    if (!original || !shownInput || !original.id) {
        return;
    }

    const doc = original.ownerDocument;
    const label = [...doc.querySelectorAll('label[for]')].find((candidate) => candidate.htmlFor === original.id);

    if (label) {
        if (!label.id) {
            label.id = `${original.id}-label`;
        }

        shownInput.setAttribute('aria-labelledby', label.id);
    }

    const describedBy = original.getAttribute('aria-describedby');

    if (describedBy) {
        shownInput.setAttribute('aria-describedby', describedBy);
    }
}

// The alt input's "formats": not flatpickr tokens, only recognised by the formatDate hook below
export const SHOWN_DAY = '[shown-day]';
export const SHOWN_DAY_TIME = '[shown-day-time]';

/**
 * flatpickr options of one `.date-picker` input: its `data-datepicker-options` (`userOptions`) with the visitor's
 * first day of the week on the page language's names (`l10n`: a flatpickr locale object, empty for English), and -
 * for a field with a calendar shown in a second input (`altInput`) - the weekday display. `defaultFormat` is
 * flatpickr.formatDate: every other format (the submitted value, aria labels) stays flatpickr's own.
 */
export function pickerOptions(userOptions, { pageLang, visitorLocale, l10n = {}, defaultFormat }) {
    const options = {
        disableMobile: true,
        ...userOptions,
        locale: { ...l10n, firstDayOfWeek: firstDayOfWeek(visitorLocale, pageLang) },
    };
    // flatpickr takes its flags as booleans or "true"
    const on = (flag) => flag === true || flag === 'true';

    if (on(options.altInput) && !on(options.noCalendar)) {
        options.altFormat = on(options.enableTime) ? SHOWN_DAY_TIME : SHOWN_DAY;
        options.formatDate = (date, format, locale) => {
            if (format === SHOWN_DAY || format === SHOWN_DAY_TIME) {
                try {
                    return formatShown(date, pageLang, format === SHOWN_DAY_TIME);
                } catch {
                    return defaultFormat(date, format === SHOWN_DAY_TIME ? 'd.m.Y H:i' : 'd.m.Y', locale);
                }
            }

            return defaultFormat(date, format, locale);
        };
    }

    return options;
}
