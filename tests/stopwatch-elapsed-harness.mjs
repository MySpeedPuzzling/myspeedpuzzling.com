// Runs assets/stopwatch_elapsed.js and assets/document_receipt.js (the browser half of the running-stopwatch pill,
// ADD-03) for the cases tests/StopwatchElapsedFormatTest.php hands over on stdin, and prints the results as JSON.

import { readFileSync } from 'node:fs';
import { navigationReceipt } from '../assets/document_receipt.js';
import {
    clockOffsetMs,
    elapsedSeconds,
    format,
    nextChangeAt,
    parseLastStates,
    resumedAfterRender,
    stoppedAfterRender,
    withState,
} from '../assets/stopwatch_elapsed.js';

const cases = JSON.parse(readFileSync(0, 'utf8'));

process.stdout.write(JSON.stringify(cases.map((testCase) => {
    switch (testCase.kind) {
        case 'format':
            return { full: format(testCase.seconds, false), compact: format(testCase.seconds, true) };
        case 'elapsed':
            return {
                seconds: elapsedSeconds(testCase.lapStarted, testCase.closedSeconds, testCase.serverNow),
                nextChangeAt: nextChangeAt(testCase.lapStarted, testCase.serverNow),
            };
        case 'stopped':
            return {
                stopped: stoppedAfterRender(parseLastStates(testCase.stored), testCase.stopwatchId, testCase.rendered, testCase.offsetMs),
            };
        case 'remember': {
            // Writes one after another, as stopwatch_controller.js does: [stopwatch id, status, at, elapsed ms or null]
            let raw = testCase.stored;
            for (const [stopwatchId, status, at, elapsed] of testCase.writes) {
                raw = JSON.stringify(withState(parseLastStates(raw), stopwatchId, status, at, elapsed));
            }
            return { states: parseLastStates(raw) };
        }
        case 'pill': {
            // What a pill rendered at `rendered` shows after reading the stored states: hidden, or the seconds at `now`
            const states = parseLastStates(testCase.stored);
            if (stoppedAfterRender(states, testCase.stopwatchId, testCase.rendered, testCase.offsetMs)) {
                return { hidden: true, seconds: null };
            }
            const resumed = resumedAfterRender(states, testCase.stopwatchId, testCase.rendered, testCase.offsetMs);
            const lapStarted = resumed === null ? testCase.lapStarted : resumed.lapStarted;
            const closedSeconds = resumed === null ? testCase.closedSeconds : resumed.closedSeconds;
            return { hidden: false, seconds: elapsedSeconds(lapStarted, closedSeconds, (testCase.now + testCase.offsetMs) / 1000) };
        }
        case 'offset': {
            // The pill's offset from the page's receipt, and the seconds it shows the moment it connects
            const offsetMs = clockOffsetMs(testCase.rendered, testCase.receipt, testCase.connectedAt);
            return {
                offsetMs,
                secondsAtConnect: elapsedSeconds(testCase.lapStarted, testCase.closedSeconds, (testCase.connectedAt + offsetMs) / 1000),
            };
        }
        case 'navigationReceipt':
            return { receipt: navigationReceipt(testCase.entry, testCase.timeOrigin) };
        default:
            throw new Error(`Unknown case ${testCase.kind}`);
    }
})));
