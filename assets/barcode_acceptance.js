// When a barcode read is handed over (docs/features/barcode-scanner/README.md §5.3, §5.7). Used by
// controllers/barcode_scanner_controller.js for every camera frame a reader decoded; pinned by
// tests/BarcodeAcceptanceScriptTest.php.
//
// Two readers can run side by side: Google's (the browser's BarcodeDetector on Android) and zbar. They fail
// differently - Google's reads blurrier frames but repeats its misreads (4005555011897 as 0045555011897), zbar's rare
// wrong reads change from frame to frame - so:
// - zbar accepts by its own rule while zbar read no other code twice in the last 2 s (or with it in one frame) - the
//   rule it reads with alone on every iPhone. zbar's misreads are one-offs, and on a hard label (Anatolian boxes, a
//   white label tight around the bars, 2026-10-19) each single one held the right code up for 2 s - accepted after
//   3.6-11.6 s though read from 0.8 s on. A code only Google's reader saw does not stop zbar either (a repeated
//   misread would otherwise block the box forever);
// - Google's reader accepts by its own rule only while neither reader saw another code in the last 2 s - zbar
//   contradicting it stops its misread;
// - both reading the same code twice each, within the last 2 s, accepts at once, unless both saw another code then.

// A read is accepted only while no other code was seen this recently
export const COMPETING_CODE_MS = 2000;
export const AGREEING_READS = 2;
// Only reads this recent count: a misread repeated now and then over a minute must never add up to an acceptance
export const READ_WINDOW_MS = 3000;
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
        this.readers[name] = { buffer: [], seenAt: new Map(), togetherAt: new Map(), soloMs: null };
    }

    /**
     * A frame that reads several different codes is not trusted at all (two boxes in view, or a misread), but
     * each of them blocks the others for a while.
     */
    seenTogether(name, codes, now) {
        const reader = this.readers[name];
        codes.forEach(code => {
            reader.seenAt.set(canonicalCode(code), now);
            reader.togetherAt.set(canonicalCode(code), now);
        });
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
        reader.buffer.push({ code, at: now });
        if (reader.buffer.length > BUFFER) {
            reader.buffer.shift();
        }

        const enough = this._count(reader, code, now) >= readsNeeded(name, code);

        // zbar answers only to itself and only to a code read twice, Google's reader to any read of either (see above)
        const judges = name === 'zbar' ? [reader] : Object.values(this.readers);
        const competitorReads = name === 'zbar' ? 2 : 1;

        // When this reader alone would have accepted - next to the other one, for the reports (README §5.7)
        if (reader.soloMs === null && enough && !this._competing(code, now, [reader], competitorReads)) {
            reader.soloMs = now - this.startedAt;
        }

        let acceptedBy = null;
        if (enough && !this._competing(code, now, judges, competitorReads)) {
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

    _count(reader, code, now) {
        return reader.buffer.filter(read => read.code === code && now - read.at < READ_WINDOW_MS).length;
    }

    /**
     * Another code any of these readers saw in the last 2 s: in one frame with others (two boxes in view) - or read
     * at least `minReads` times (a read counts only with zbar's quality >= 3, as for acceptance)
     */
    _competing(code, now, readers, minReads = 1) {
        return readers.some(reader => {
            for (const [other, seenAt] of reader.seenAt) {
                if (other === code || now - seenAt >= COMPETING_CODE_MS) {
                    continue;
                }
                if (now - (reader.togetherAt.get(other) ?? -Infinity) < COMPETING_CODE_MS) {
                    return true;
                }
                const reads = reader.buffer.filter(read => read.code === other && now - read.at < COMPETING_CODE_MS).length;
                if (reads >= minReads) {
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

        const recent = (reader) => now - (reader.seenAt.get(code) ?? -Infinity) < COMPETING_CODE_MS && this._count(reader, code, now) >= AGREEING_READS;
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

// zbar next to Google's reader gets at most this share of the time
export const HELPER_SHARE = 0.25;
// Google's reader alone for this many frames first: what "not slowed down" means on this phone
export const BASELINE_FRAMES = 5;
const MAX_BACKOFF = 32;
const WINDOW = 5;

function median(values) {
    const sorted = [...values].sort((a, b) => a - b);

    return sorted[Math.floor(sorted.length / 2)];
}

/**
 * How often zbar may decode next to Google's reader (README §5.7). Both work on the phone's one main thread and CPU,
 * and on a mid-range phone zbar running beside it slowed Google's reader from 83 to 418 ms a frame (Galaxy A33,
 * 2026-10-18) - the scan took 8 s instead of about 2. So Google's reader keeps its own pace, zbar is a helper:
 * - it starts only once Google's reader decoded a few frames alone - its time per frame then is the baseline;
 * - after each decode it waits three times as long as the decode took (a quarter of the time at most);
 * - Google's reader is judged on the average of every 5 frames (the average: a frame zbar slowed down is exactly what
 *   counts). Clearly slower than its baseline - zbar waits twice as long right away (up to 32x, which on a slow phone
 *   means practically off); it comes back one step only after 3 good windows in a row, so it does not swing back
 *   into the way.
 * A phone too slow for both therefore scans as before the change, with Google's reader alone.
 */
export class HelperBudget {
    constructor() {
        this.baseline = [];
        this.baselineMs = null;
        this.recent = [];
        this.goodWindows = 0;
        this.backoff = 1;
        this.maxBackoff = 1;
        this.nextAt = 0;
    }

    /** Google's reader decoded a frame in `ms` */
    primaryDecoded(ms) {
        if (this.baselineMs === null) {
            this.baseline.push(ms);
            if (this.baseline.length >= BASELINE_FRAMES) {
                this.baselineMs = median(this.baseline);
            }
            return;
        }

        this.recent.push(ms);
        if (this.recent.length < WINDOW) {
            return;
        }

        const average = this.recent.reduce((a, b) => a + b, 0) / this.recent.length;
        this.recent = [];
        if (average > Math.max(this.baselineMs * 1.5, this.baselineMs + 30)) {
            this.backoff = Math.min(this.backoff * 2, MAX_BACKOFF);
            this.maxBackoff = Math.max(this.maxBackoff, this.backoff);
            this.goodWindows = 0;
        } else if (average <= this.baselineMs * 1.25 && ++this.goodWindows >= 3) {
            this.backoff = Math.max(1, this.backoff / 2);
            this.goodWindows = 0;
        }
    }

    mayRun(now) {
        return this.baselineMs !== null && now >= this.nextAt;
    }

    /** zbar's crop and decode took `cost` ms, finished at `now` */
    helperRan(cost, now) {
        this.nextAt = now + cost * ((1 - HELPER_SHARE) / HELPER_SHARE) * this.backoff;
    }
}
