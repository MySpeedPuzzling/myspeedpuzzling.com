// When a barcode read is handed over (docs/features/barcode-scanner/README.md §5.3, §5.7). Used by
// controllers/barcode_scanner_controller.js for every camera frame a reader decoded; pinned by
// tests/BarcodeAcceptanceScriptTest.php.
//
// Two readers can run side by side: Google's (the browser's BarcodeDetector on Android) and zbar. They fail
// differently - Google's reads blurrier frames but repeats its misreads (4005555011897 as 0045555011897), zbar's rare
// wrong reads change from frame to frame - so:
// - zbar accepts by its own rule while zbar saw no other code in the last 2 s - the rule it reads with alone on every
//   iPhone; a code only Google's reader saw does not stop it (a repeated misread would otherwise block the box forever);
// - Google's reader accepts by its own rule only while neither reader saw another code in the last 2 s - zbar
//   contradicting it stops its misread;
// - both reading the same code twice each, within the last 2 s, accepts at once, unless both saw another code then.

// A read is accepted only while no other code was seen this recently
export const COMPETING_CODE_MS = 2000;
export const AGREEING_READS = 2;
const BUFFER = 30;

/**
 * Google's reader reports a code starting with 0 as its 12 UPC-A digits, zbar as 13 EAN-13 digits: one form, so the
 * two readers neither count the same box as two codes nor block each other on it. The lookups tolerate the zero.
 */
export function canonicalCode(code) {
    return /^\d{12}$/.test(code) ? `0${code}` : code;
}

/**
 * zbar's wrong reads are rare and change from frame to frame, so a few agreeing frames filter them; codes starting
 * with 0 (where wrong reads cluster) and EAN-8 need more. Google's reader keeps its 10 - its misreads repeat, more
 * frames would not catch them (zbar next to it and the lookups' misread correction do).
 */
export function readsNeeded(reader, code) {
    if (reader !== 'zbar') {
        return 10;
    }

    return code.charAt(0) === '0' || code.length === 8 ? 8 : 4;
}

export class ReadAcceptance {
    /**
     * @param {number} startedAt when the camera session started (ms, the same clock as `now`)
     */
    constructor(startedAt) {
        this.startedAt = startedAt;
        this.readers = {};
    }

    addReader(name) {
        this.readers[name] = { buffer: [], seenAt: new Map(), soloMs: null };
    }

    /**
     * A frame that reads several different codes is not trusted at all (two boxes in view, or a misread), but
     * each of them blocks the others for a while.
     */
    seenTogether(name, codes, now) {
        const reader = this.readers[name];
        codes.forEach(code => reader.seenAt.set(canonicalCode(code), now));
    }

    /**
     * @param {number|undefined} quality zbar's: how many scanlines read the code; Google's reader reports none
     * @returns {'native'|'zbar'|'both'|null} who decided, null = not (yet) accepted
     */
    read(name, rawCode, quality, now) {
        const reader = this.readers[name];
        const code = canonicalCode(rawCode);

        if (name === 'zbar' && !(quality >= 3)) {
            return null;
        }

        reader.seenAt.set(code, now);
        reader.buffer.push(code);
        if (reader.buffer.length > BUFFER) {
            reader.buffer.shift();
        }

        const enough = this._count(reader, code) >= readsNeeded(name, code);

        // When this reader alone would have accepted - next to the other one, for the reports (README §5.7)
        if (reader.soloMs === null && enough && !this._competing(code, now, [reader])) {
            reader.soloMs = now - this.startedAt;
        }

        // zbar answers only to itself, Google's reader to both (see above)
        const judges = name === 'zbar' ? [reader] : Object.values(this.readers);

        let acceptedBy = null;
        if (enough && !this._competing(code, now, judges)) {
            acceptedBy = name;
        } else if (this._agreed(code, now)) {
            acceptedBy = 'both';
        }

        if (acceptedBy !== null) {
            Object.values(this.readers).forEach(r => { r.buffer = []; });
        }

        return acceptedBy;
    }

    soloMs(name) {
        return this.readers[name] ? this.readers[name].soloMs : null;
    }

    _count(reader, code) {
        return reader.buffer.filter(c => c === code).length;
    }

    // Another code seen by any of these readers in the last 2 s
    _competing(code, now, readers) {
        return readers.some(reader => {
            for (const [other, seenAt] of reader.seenAt) {
                if (other !== code && now - seenAt < COMPETING_CODE_MS) {
                    return true;
                }
            }

            return false;
        });
    }

    /**
     * Both readers read the code twice, each within the last 2 s. They do not agree on a wrong code; only another
     * code both of them saw (a second box in view) stops it - one reader's stray misread does not hold it up.
     */
    _agreed(code, now) {
        const native = this.readers.native;
        const zbar = this.readers.zbar;
        if (!native || !zbar) {
            return false;
        }

        const recent = (reader) => now - (reader.seenAt.get(code) ?? -Infinity) < COMPETING_CODE_MS && this._count(reader, code) >= AGREEING_READS;
        if (!recent(native) || !recent(zbar)) {
            return false;
        }

        for (const [other, seenAt] of native.seenAt) {
            if (other !== code && now - seenAt < COMPETING_CODE_MS && now - (zbar.seenAt.get(other) ?? -Infinity) < COMPETING_CODE_MS) {
                return false;
            }
        }

        return true;
    }
}
