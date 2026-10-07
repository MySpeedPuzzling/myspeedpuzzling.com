/**
 * Typed result times of the official results tools (live entry, results desk) - one parser for every input,
 * docs/features/competitions-management/official-results.md.
 *
 * - "1:23:45" = h:mm:ss, "58:12" or "83:45" = mm:ss (minutes may pass 59 here, seconds may not);
 *   ".", "," and spaces work as separators too.
 * - Digits only are read right-aligned as h:mm:ss: "45" = 0:00:45, "5812" = 0:58:12, "12345" = 1:23:45.
 * - Minutes or seconds of 60 or more in an h:mm:ss form are an error, never carried silently.
 * - A result is 1 second to 23:59:59 (RoundEntryResult::MAX_SECONDS on the server).
 */

export const MAX_SECONDS = 86399;

/**
 * @param {string} input
 * @returns {{empty: true} | {seconds: number} | {error: 'invalid' | 'out_of_range'}}
 */
export function parseResultTime(input) {
    const text = String(input ?? '').trim();

    if (text === '') {
        return { empty: true };
    }

    let hours;
    let minutes;
    let seconds;

    if (/^\d+$/.test(text)) {
        if (text.length > 6) {
            return { error: 'invalid' };
        }

        const padded = text.padStart(6, '0');
        hours = parseInt(padded.slice(0, 2), 10);
        minutes = parseInt(padded.slice(2, 4), 10);
        seconds = parseInt(padded.slice(4, 6), 10);

        if (minutes > 59 || seconds > 59) {
            return { error: 'invalid' };
        }
    } else {
        const parts = text.split(/\s*[:.,\s]\s*/);

        if (parts.some((part) => !/^\d{1,3}$/.test(part))) {
            return { error: 'invalid' };
        }

        if (parts.length === 2) {
            hours = 0;
            minutes = parseInt(parts[0], 10);
            seconds = parseInt(parts[1], 10);

            if (seconds > 59 || parts[1].length !== 2) {
                return { error: 'invalid' };
            }
        } else if (parts.length === 3) {
            hours = parseInt(parts[0], 10);
            minutes = parseInt(parts[1], 10);
            seconds = parseInt(parts[2], 10);

            if (minutes > 59 || seconds > 59 || parts[1].length !== 2 || parts[2].length !== 2) {
                return { error: 'invalid' };
            }
        } else {
            return { error: 'invalid' };
        }
    }

    const total = hours * 3600 + minutes * 60 + seconds;

    if (total < 1 || total > MAX_SECONDS) {
        return { error: 'out_of_range' };
    }

    return { seconds: total };
}

/**
 * @param {number} totalSeconds
 * @returns {string} "1:23:45" / "0:58:12"
 */
export function formatResultTime(totalSeconds) {
    const total = Math.max(0, Math.floor(totalSeconds));
    const hours = Math.floor(total / 3600);
    const minutes = Math.floor((total % 3600) / 60);
    const seconds = total % 60;

    return `${hours}:${String(minutes).padStart(2, '0')}:${String(seconds).padStart(2, '0')}`;
}
