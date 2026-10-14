/**
 * Load test of an online competition round (docs/features/online-competitions/spikes.md S5): N participants of one
 * round, each a phone that goes through the round end to end on the public participant API (lib/phone.js) plus its
 * live stream through Mercure (lib/subscriber.js). Run it with run.sh - README.md has every option and how to read
 * the report.
 *
 * Inputs (k6 --env / -e):
 *   BASE_URL       the site, e.g. http://online-load-web:8080 (required)
 *   LINKS          the round's personal links - the links page's CSV, or one link / token per line (/input/links.csv)
 *   N, OFFSET      how many participants, from which link on (all / 0)
 *   DURATION       the whole run (5m); JOIN_SPREAD: the phones open their links within this (30s)
 *   START_AT       the expected start (ISO 8601 or epoch ms) - for the report; the phones learn the real one from the
 *                  state like every phone (the organiser schedules it: "Start in 10 s" / "Start at …")
 *   PROFILE        steady | start_burst | finish_wave (steady)
 *   FINISH_SHARE   share of the participants tapping Finished (steady 0.5, start_burst 0, finish_wave 0.3)
 *   FINISH_AFTER   the first taps this long after GO (steady 60s, finish_wave 120s); FINISH_WINDOW: the wave's length
 *                  (finish_wave 60s; steady: until a minute before the run ends)
 *   UNDO_SHARE     share of the finishers taking their tap back within 10 s, tapping again later (0.1)
 *   SSE            1 = every phone also holds its live stream (1)
 *   PAGE           0 = the phone starts from the state, not from the page's HTML - a run through the bot-blocker (1)
 *   SPOOF_IPS      1 = every phone sends its own X-Forwarded-For - the local stack only (0)
 *   PHOTO, BURST, BURST_MANIFEST   the evidence files (make-media.php: /input/photo.jpg, burst.bin, burst.json)
 *   OUT            where report.md and summary.json go (/out)
 */

import { sleep } from 'k6';
import { SharedArray } from 'k6/data';
import exec from 'k6/execution';
import { open as openFile, SeekMode } from 'k6/experimental/fs';
import { parseLinks } from './lib/links.js';
import * as metrics from './lib/metrics.js';
import { Phone, START_WINDOW_MS } from './lib/phone.js';
import { buildReport } from './lib/report.js';
import { subscribe } from './lib/subscriber.js';

function duration(text, fallbackMs) {
    if (text === undefined || text === '') {
        return fallbackMs;
    }

    const match = String(text).trim().match(/^(\d+(?:\.\d+)?)(ms|s|m|h)?$/);

    if (!match) {
        throw new Error(`Not a duration: ${text}`);
    }

    const factor = { ms: 1, s: 1000, m: 60000, h: 3600000 }[match[2] ?? 's'];

    return Math.round(parseFloat(match[1]) * factor);
}

function moment(text) {
    if (text === undefined || text === '') {
        return null;
    }

    const value = /^\d+$/.test(text) ? Number(text) : Date.parse(text);

    if (!Number.isFinite(value)) {
        throw new Error(`Not a moment: ${text}`);
    }

    return value;
}

function share(text, fallback) {
    const value = text === undefined || text === '' ? fallback : Number(text);

    if (!Number.isFinite(value) || value < 0 || value > 1) {
        throw new Error(`Not a share between 0 and 1: ${text}`);
    }

    return value;
}

// A fixed fraction per participant (the same for both VUs of a phone, the same in every run)
function fraction(index, salt) {
    const x = Math.sin((index + 1) * 12.9898 + salt * 78.233) * 43758.5453;

    return x - Math.floor(x);
}

const env = __ENV;
const PROFILES = {
    steady: { finishShare: 0.5, finishAfterMs: 60000, finishWindowMs: null },
    start_burst: { finishShare: 0, finishAfterMs: 60000, finishWindowMs: null },
    finish_wave: { finishShare: 0.3, finishAfterMs: 120000, finishWindowMs: 60000 },
};
const profile = env.PROFILE || 'steady';

if (!PROFILES[profile]) {
    throw new Error(`PROFILE must be one of ${Object.keys(PROFILES).join(', ')}`);
}

if (!env.BASE_URL) {
    throw new Error('BASE_URL is required, e.g. -e BASE_URL=http://online-load-web:8080');
}

const tokens = new SharedArray('tokens', () => parseLinks(open(env.LINKS || '/input/links.csv')));
const offset = Number(env.OFFSET || 0);
const participants = Math.min(Number(env.N || tokens.length - offset), tokens.length - offset);

if (!(participants > 0)) {
    throw new Error(`No links to use: ${tokens.length} links, OFFSET ${offset}, N ${env.N}`);
}

const burstFrames = new SharedArray('burstFrames', () => JSON.parse(open(env.BURST_MANIFEST || '/input/burst.json')));
// Shared by every VU; a phone reads them into memory when it first uploads (the stream VUs never do)
const photoFile = await openFile(env.PHOTO || '/input/photo.jpg');
const burstFile = await openFile(env.BURST || '/input/burst.bin');

const runMs = duration(env.DURATION, 5 * 60000);
const joinSpreadMs = duration(env.JOIN_SPREAD, 30000);
const finishShare = share(env.FINISH_SHARE, PROFILES[profile].finishShare);
const finishAfterMs = duration(env.FINISH_AFTER, PROFILES[profile].finishAfterMs);
const finishWindowMs = env.FINISH_WINDOW ? duration(env.FINISH_WINDOW) : PROFILES[profile].finishWindowMs;
const undoShare = share(env.UNDO_SHARE, 0.1);
const withSse = (env.SSE ?? '1') !== '0';
const withPage = (env.PAGE ?? '1') !== '0';
const spoofIps = env.SPOOF_IPS === '1';
const out = env.OUT || '/out';

// The phones end by themselves at the run's end; a stream is held by its VU until k6 interrupts it there (Mercure's
// keep-alive comments carry no event, so a quiet stream gives the script no moment to close it)
const scenarios = {
    participants: {
        executor: 'per-vu-iterations',
        exec: 'participant',
        vus: participants,
        iterations: 1,
        maxDuration: `${Math.ceil(runMs / 1000) + 60}s`,
        gracefulStop: '30s',
    },
};

if (withSse) {
    scenarios.streams = {
        executor: 'per-vu-iterations',
        exec: 'stream',
        vus: participants,
        iterations: 1,
        maxDuration: `${Math.ceil(runMs / 1000)}s`,
        gracefulStop: '0s',
    };
}

export const options = {
    scenarios,
    thresholds: metrics.thresholds(),
    summaryTrendStats: ['min', 'med', 'avg', 'p(95)', 'p(99)', 'max', 'count'],
    userAgent: 'msp-online-load/1 (k6)',
    // A phone opens a few connections and keeps them (browsers do): VUs reuse theirs
    noVUConnectionReuse: false,
    setupTimeout: '60s',
};

export function setup() {
    const startedAtMs = Date.now();

    return { startedAtMs, endAtMs: startedAtMs + runMs };
}

function config(data) {
    return {
        baseUrl: env.BASE_URL.replace(/\/+$/, ''),
        page: withPage,
        spoofIps,
        endAtMs: data.endAtMs,
        // Who taps Finished, and whether they take it back
        finishPlan(index) {
            if (fraction(index, 1) >= finishShare) {
                return null;
            }

            return { at: fraction(index, 2), undo: fraction(index, 3) < undoShare };
        },
        finishAt(plan, startedAtMs) {
            const from = startedAtMs + finishAfterMs;
            const window = finishWindowMs ?? Math.max(0, data.endAtMs - 60000 - from);

            return from + plan.at * window;
        },
        inFinishWave(view, serverNowMs) {
            if (profile !== 'finish_wave' || view.startedAtMs === null) {
                return false;
            }

            const from = view.startedAtMs + finishAfterMs;

            // The wave and the uploads trailing it
            return serverNowMs >= from && serverNowMs <= from + finishWindowMs + 30000;
        },
    };
}

async function readAll(file) {
    const info = await file.stat();
    const buffer = new Uint8Array(info.size);
    await file.seek(0, SeekMode.Start);
    let read = 0;

    while (read < info.size) {
        const n = await file.read(buffer.subarray(read));

        if (n === null || n === 0) {
            break;
        }

        read += n;
    }

    return buffer.buffer;
}

function joinDelayMs(index) {
    return fraction(index, 4) * joinSpreadMs;
}

export async function participant(data) {
    const index = exec.scenario.iterationInTest;
    const media = { photo: await readAll(photoFile), burst: await readAll(burstFile), burstFrames };
    const phone = new Phone({ index, token: tokens[offset + index], config: config(data), media });

    sleepUntil(data.startedAtMs + joinDelayMs(index));
    phone.run(data.endAtMs);
}

export function stream(data) {
    const index = exec.scenario.iterationInTest;

    // The stream opens with its page
    sleepUntil(data.startedAtMs + joinDelayMs(index) + 500);
    subscribe({ index, token: tokens[offset + index], config: config(data), endAt: data.endAtMs });
}

function sleepUntil(epochMs) {
    const wait = epochMs - Date.now();

    if (wait > 0) {
        sleep(wait / 1000);
    }
}

export function handleSummary(data) {
    const report = buildReport(data, {
        baseUrl: env.BASE_URL,
        profile,
        participants,
        offset,
        sse: withSse,
        spoofIps,
        joinSpreadMs,
        startedAtMs: data.setup_data?.startedAtMs ?? null,
        startAtMs: moment(env.START_AT),
        finishShare,
        finishAfterMs,
        finishWindowMs,
        undoShare,
        startWindowMs: START_WINDOW_MS,
    });

    return {
        stdout: `\n${report}\n`,
        [`${out}/report.md`]: `${report}\n`,
        [`${out}/summary.json`]: JSON.stringify(data, null, 2),
    };
}
