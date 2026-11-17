// Analytics Studio (docs/features/competitions-management/analytics-studio.md §7.6) - formatting of what the page shows:
// durations, deltas, counts, places, dates at their precision (R5), the `%name%` placeholders of the labels. Pure, no
// DOM - pinned by tests/analytics-studio-harness.mjs.

function pad(number) {
    return number < 10 ? `0${number}` : String(number);
}

function isNumber(value) {
    return typeof value === 'number' && Number.isFinite(value);
}

/**
 * Seconds as "m:ss" below an hour ("41:07"), else "h:mm:ss" ("1:02:05"); null / not a number → "—".
 */
export function formatDuration(seconds) {
    if (!isNumber(seconds) || seconds < 0) {
        return '—';
    }

    const total = Math.round(seconds);
    const hours = Math.floor(total / 3600);
    const minutes = Math.floor((total % 3600) / 60);
    const rest = total % 60;

    return hours > 0 ? `${hours}:${pad(minutes)}:${pad(rest)}` : `${minutes}:${pad(rest)}`;
}

/**
 * The difference of `seconds` against `reference` - "+2:13", "−0:45", "±0:00"; null when either is unknown.
 */
export function formatDelta(seconds, reference) {
    if (!isNumber(seconds) || !isNumber(reference)) {
        return null;
    }

    const delta = Math.round(seconds) - Math.round(reference);

    if (delta === 0) {
        return '±0:00';
    }

    return `${delta > 0 ? '+' : '−'}${formatDuration(Math.abs(delta))}`;
}

/**
 * A range of two durations - "40:00–47:00"; one side unknown → null.
 */
export function formatRange(low, high) {
    if (!isNumber(low) || !isNumber(high)) {
        return null;
    }

    return `${formatDuration(low)}–${formatDuration(high)}`;
}

/**
 * A count in the page's locale ("5,623"); unknown → "—".
 */
export function formatCount(value, locale = 'en') {
    if (!isNumber(value)) {
        return '—';
    }

    try {
        return new Intl.NumberFormat(locale).format(value);
    } catch {
        return String(value);
    }
}

/**
 * A decimal (difficulty score) with two places in the page's locale.
 */
export function formatDecimal(value, locale = 'en') {
    if (!isNumber(value)) {
        return '—';
    }

    try {
        return new Intl.NumberFormat(locale, { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(value);
    } catch {
        return value.toFixed(2);
    }
}

const PRECISION_OPTIONS = {
    day: { year: 'numeric', month: 'short', day: 'numeric' },
    month: { year: 'numeric', month: 'short' },
    year: { year: 'numeric' },
};

/**
 * A moment at the precision it was logged with (R5): a month- or year-only date never shows a day. In UTC - the
 * server stores such dates as the first moment of their period, and the day of a solve is shown as logged.
 */
export function formatDate(ms, precision = 'day', locale = 'en') {
    if (!isNumber(ms)) {
        return '—';
    }

    const options = PRECISION_OPTIONS[precision] ?? PRECISION_OPTIONS.day;

    try {
        return new Intl.DateTimeFormat(locale, { ...options, timeZone: 'UTC' }).format(new Date(ms));
    } catch {
        return new Date(ms).toISOString().slice(0, precision === 'year' ? 4 : (precision === 'month' ? 7 : 10));
    }
}

/**
 * The round's start - date and time in the page's locale ("Nov 14, 2026, 10:00 AM"), in the viewer's zone unless one is
 * given; unknown → ''.
 */
export function formatStartsAt(ms, locale = 'en', timeZone = undefined) {
    if (!isNumber(ms)) {
        return '';
    }

    try {
        return new Intl.DateTimeFormat(locale, { dateStyle: 'medium', timeStyle: 'short', timeZone }).format(new Date(ms));
    } catch {
        return new Date(ms).toISOString().slice(0, 16).replace('T', ' ');
    }
}

/**
 * A label with its `%name%` placeholders filled. Values are plain text - the renderer sets textContent only.
 */
export function interpolate(template, params = {}) {
    return String(template ?? '').replace(/%([a-z_]+)%/g, (match, name) => (
        Object.prototype.hasOwnProperty.call(params, name) ? String(params[name]) : match
    ));
}

/**
 * The clock of the top bar - "0:41:12" (always with hours, it is a running stopwatch).
 */
export function formatClock(ms) {
    const total = Math.max(0, Math.floor((isNumber(ms) ? ms : 0) / 1000));

    return `${Math.floor(total / 3600)}:${pad(Math.floor((total % 3600) / 60))}:${pad(total % 60)}`;
}

/**
 * A 2-letter country code as its flag emoji (regional indicators) - anything else → ''.
 */
export function flagOf(country) {
    if (typeof country !== 'string' || !/^[a-z]{2}$/i.test(country)) {
        return '';
    }

    return String.fromCodePoint(...[...country.toUpperCase()].map((letter) => 0x1f1e6 + letter.charCodeAt(0) - 65));
}
