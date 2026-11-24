/**
 * A competition time typed by a judge or an organiser (judge card, block per-challenge times, results desk) -
 * docs/features/competitions-management/toolkit/fast-time-entry.md.
 *
 * One numeric field, no colons to type: digits fill from the RIGHT and the field shows the time as it grows
 * (`3` → 0:03, `302` → 3:02, `3025` → 30:25, `13025` → 1:30:25); leading zeros never shift anything (`0013025` = 1:30:25).
 * Tapping a field that holds a time selects the part under the finger (hours, minutes or seconds) - digits then change
 * that part only, after 2 digits the next part is selected, a part never spills into its neighbour.
 * Pure functions below; bindTimeEntry() wires them to every `input[data-time-entry]` under a root (delegated, so rows
 * rebuilt with innerHTML need nothing).
 */
import { MAX_SECONDS, parseResultTime } from './official_results_time.js';

/** More significant digits than h h m m s s are refused, never shifted */
const MAX_DIGITS = 6;

/** Digits typed in one go → the field's text: '' | 0:ss | m:ss | mm:ss | h:mm:ss | hh:mm:ss (more = refused by the reading) */
export function formatDigits(text) {
    const digits = String(text ?? '').replace(/\D/g, '').replace(/^0+/, '');

    if (digits === '') {
        return '';
    }

    const seconds = digits.slice(-2).padStart(2, '0');
    const rest = digits.slice(0, -2);

    if (rest === '') {
        return `0:${seconds}`;
    }

    const minutes = rest.slice(-2);
    const hours = rest.slice(0, -2);

    return hours === '' ? `${minutes}:${seconds}` : `${hours}:${minutes.padStart(2, '0')}:${seconds}`;
}

/** A known time as the field shows it: 0:45, 58:12, 1:23:45 */
export function formatTimeEntry(totalSeconds) {
    const total = Math.max(0, Math.floor(totalSeconds));
    const hours = Math.floor(total / 3600);
    const minutes = Math.floor((total % 3600) / 60);
    const seconds = String(total % 60).padStart(2, '0');

    return hours > 0 ? `${hours}:${String(minutes).padStart(2, '0')}:${seconds}` : `${minutes}:${seconds}`;
}

/**
 * The field's text → a time. Blind digits read exactly like parseResultTime(digits) (pinned by the harness); a field's
 * parts say which one is wrong.
 *
 * @returns {{empty: true} | {seconds: number} | {error: 'minutes' | 'seconds' | 'too_long' | 'out_of_range' | 'invalid'}}
 */
export function readTimeEntry(input) {
    const text = String(input ?? '').trim();

    if (text === '') {
        return { empty: true };
    }

    if (/^\d+$/.test(text)) {
        const shown = formatDigits(text);

        return shown === '' ? { error: 'out_of_range' } : readParts(shown.split(':'), text.replace(/^0+/, '').length);
    }

    const parts = text.split(':');

    if (parts.length < 2 || parts.length > 3 || parts.some((part) => !/^\d{0,3}$/.test(part)) || parts.every((part) => part === '')) {
        // Anything else typed on a desk keyboard ("1.23.45", "83:45") - the shared parser decides
        const parsed = parseResultTime(text);

        return parsed.error === 'invalid' ? { error: 'invalid' } : parsed;
    }

    return readParts(parts, parts.join('').replace(/^0+/, '').length);
}

function readParts(parts, significant) {
    if (significant > MAX_DIGITS) {
        return { error: 'too_long' };
    }

    const [hours, minutes, seconds] = (parts.length === 3 ? parts : ['', ...parts]).map((part) => (part === '' ? 0 : parseInt(part, 10)));

    if (seconds > 59) {
        return { error: 'seconds' };
    }

    if (minutes > 59) {
        return { error: 'minutes' };
    }

    const total = hours * 3600 + minutes * 60 + seconds;

    return total < 1 || total > MAX_SECONDS ? { error: 'out_of_range' } : { seconds: total };
}

/** [start, end] of the part (hours / minutes / seconds) the caret is in */
export function partAt(text, caret) {
    let start = 0;

    for (const part of String(text).split(':')) {
        const end = start + part.length;

        if (caret <= end) {
            return [start, end];
        }
        start = end + 1;
    }

    return [Math.max(0, String(text).length), String(text).length];
}

/**
 * What the field becomes after one `input` event.
 *
 * @param {{value: string, caret: number, part: boolean, previous: string, inserting: boolean, paste: boolean}} state
 *   value + caret after the browser's edit; part = a part was tapped (part mode); previous = the text before the edit
 * @returns {{value: string, start: number, end: number, part: boolean}}
 */
export function maskTimeEntry({ value, caret, part = false, previous = '', inserting = true, paste = false }) {
    const text = String(value ?? '');

    // A pasted time with separators is read as written ("83:45" = 1:23:45)
    if (paste && /\d\D+\d/.test(text)) {
        const parsed = parseResultTime(text);

        if (parsed.seconds !== undefined) {
            const shown = formatTimeEntry(parsed.seconds);

            return { value: shown, start: shown.length, end: shown.length, part: false };
        }
    }

    const cleaned = text.replace(/[^\d:]/g, '');
    const parts = cleaned.split(':');

    if (part && parts.length >= 2 && parts.length <= 3 && parts.length === String(previous).split(':').length && /\d/.test(cleaned)) {
        return editPart(parts, caret - (text.slice(0, caret).length - text.slice(0, caret).replace(/[^\d:]/g, '').length), inserting);
    }

    // Blind: every digit, refilled from the right. A backspace that took only a colon takes the digit before it too
    // (the colon would come straight back - a dead key)
    let digits = text.replace(/\D/g, '');

    if (!part && !inserting && digits === String(previous).replace(/\D/g, '') && text !== String(previous)) {
        const before = text.slice(0, caret).replace(/\D/g, '').length;
        digits = before > 0 ? digits.slice(0, before - 1) + digits.slice(before) : digits;
    }

    const shown = formatDigits(digits);

    return { value: shown, start: shown.length, end: shown.length, part: false };
}

function editPart(parts, caret, inserting) {
    let start = 0;
    let index = parts.length - 1;

    for (let i = 0; i < parts.length; i++) {
        if (caret <= start + parts[i].length) {
            index = i;
            break;
        }
        start += parts[i].length + 1;
    }

    const typed = parts[index];
    const at = Math.min(caret - start, typed.length);
    // Never more than 2 digits: the 2 before the caret (typing at its end), else the first 2
    const kept = typed.length <= 2 ? typed : (at >= 2 ? typed.slice(at - 2, at) : typed.slice(0, 2));
    parts[index] = kept;
    const value = parts.join(':');
    const bounds = (i) => {
        const from = parts.slice(0, i).reduce((sum, item) => sum + item.length + 1, 0);

        return [from, from + parts[i].length];
    };

    if (inserting && kept.length === 2) {
        // Jan's jump: the next part is selected (the last one stays selected - typing replaces it, never spills)
        const [from, to] = bounds(Math.min(index + 1, parts.length - 1));

        return { value, start: from, end: to, part: true };
    }

    const position = Math.min(start + Math.min(at, kept.length), start + kept.length);

    return { value, start: position, end: position, part: true };
}

/**
 * A time in words for the line under a field - `words` with %m% %s% (below an hour), `wordsHours` with %h% %m% %s%:
 * "30 min 25 s", "1 h 30 min 25 s" - so 58:12 is never read as hours.
 */
export function timeInWords(totalSeconds, words, wordsHours) {
    const hours = Math.floor(totalSeconds / 3600);
    const values = { '%h%': hours, '%m%': Math.floor((totalSeconds % 3600) / 60), '%s%': totalSeconds % 60 };

    return Object.entries(values).reduce((text, [name, value]) => text.split(name).join(String(value)), hours > 0 ? wordsHours : words);
}

/** The field's text for a value set by code (a proposal, a restored draft, a refused change): '' or the shown time */
export function timeEntryText(text) {
    const parsed = parseResultTime(text);

    if (parsed.seconds !== undefined) {
        return formatTimeEntry(parsed.seconds);
    }

    return /^[\d\s]*$/.test(String(text ?? '')) ? formatDigits(text) : String(text ?? '');
}

/** Sets a field from code - typing then starts over (blind) */
export function setTimeEntry(input, text) {
    input.value = timeEntryText(text);
    input.dataset.timePart = '';
}

/**
 * Wires every `input[data-time-entry]` under `root`: the mask runs in the capture phase, so a Stimulus `input->` action
 * on the field already reads the formatted text. Returns the unbinding function.
 */
export function bindTimeEntry(root) {
    const field = (event) => (event.target instanceof HTMLInputElement && event.target.dataset.timeEntry !== undefined ? event.target : null);
    let tappedWhileFocused = false;

    const mask = (input, type) => {
        const out = maskTimeEntry({
            value: input.value,
            caret: input.selectionStart ?? input.value.length,
            part: input.dataset.timePart === '1',
            previous: input.dataset.timePrev ?? '',
            inserting: type.startsWith('insert'),
            paste: type === 'insertFromPaste' || type === 'insertFromDrop',
        });

        if (out.value !== input.value) {
            input.value = out.value;
        }
        input.dataset.timePart = out.part ? '1' : '';
        input.dataset.timePrev = out.value;
        select(input, out.start, out.end);
    };

    const listeners = {
        beforeinput(event) {
            const input = field(event);

            if (input !== null && !event.isComposing) {
                input.dataset.timePrev = input.value;
            }
        },
        input(event) {
            const input = field(event);

            // A keyboard still composing (some Android ones) gets the value once it is done - compositionend
            if (input !== null && !event.isComposing) {
                mask(input, typeof event.inputType === 'string' && event.inputType !== '' ? event.inputType : 'insertText');
            }
        },
        compositionend(event) {
            const input = field(event);

            if (input !== null) {
                mask(input, 'insertText');
                input.dispatchEvent(new Event('input', { bubbles: true }));
            }
        },
        pointerdown(event) {
            const input = field(event);
            tappedWhileFocused = input !== null && input === document.activeElement;
        },
        // The first tap selects everything (typing starts over, blind); a tap on the field being typed in selects the
        // part under the finger. After the browser placed its caret (iOS places it after the click)
        click(event) {
            const input = field(event);

            if (input === null || input.value === '' || input.selectionStart !== input.selectionEnd) {
                return;
            }

            const whole = !tappedWhileFocused || !/^\d*(:\d*){1,2}$/.test(input.value);
            const [start, end] = whole ? [0, input.value.length] : partAt(input.value, input.selectionStart ?? input.value.length);
            input.dataset.timePart = whole ? '' : '1';
            input.dataset.timePrev = input.value;
            requestAnimationFrame(() => select(input, start, end));
        },
        // Left: the parts tidied (1:5:25 → 1:05:25); empty or not a time stays as typed - its error says why
        focusout(event) {
            const input = field(event);

            if (input === null) {
                return;
            }

            const read = readTimeEntry(input.value);
            input.dataset.timePart = '';

            if (read.seconds !== undefined && formatTimeEntry(read.seconds) !== input.value) {
                input.value = formatTimeEntry(read.seconds);
            }
        },
    };

    for (const [name, listener] of Object.entries(listeners)) {
        root.addEventListener(name, listener, true);
    }

    return () => {
        for (const [name, listener] of Object.entries(listeners)) {
            root.removeEventListener(name, listener, true);
        }
    };
}

function select(input, start, end) {
    try {
        input.setSelectionRange(start, end);
    } catch {
        // not focusable right now - nothing to select
    }
}
