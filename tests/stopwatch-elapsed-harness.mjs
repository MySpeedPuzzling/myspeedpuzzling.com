// Runs assets/stopwatch_elapsed.js (the browser half of the running-stopwatch pill, ADD-03) for the cases
// tests/StopwatchElapsedFormatTest.php hands over on stdin, and prints the results as JSON.

import { readFileSync } from 'node:fs';
import { elapsedSeconds, format, nextChangeAt, parseLastState, stoppedAfterRender } from '../assets/stopwatch_elapsed.js';

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
                stopped: stoppedAfterRender(parseLastState(testCase.stored), testCase.stopwatchId, testCase.rendered, testCase.offsetMs),
            };
        default:
            throw new Error(`Unknown case ${testCase.kind}`);
    }
})));
