// Runs assets/barcode_acceptance.js (when a barcode read is handed over) for the cases
// tests/BarcodeAcceptanceScriptTest.php hands over on stdin, and prints what each read returned as JSON.

import { readFileSync } from 'node:fs';
import { HelperBudget, ReadAcceptance, canonicalCode } from '../assets/barcode_acceptance.js';

const cases = JSON.parse(readFileSync(0, 'utf8'));

process.stdout.write(JSON.stringify(cases.map((testCase) => {
    if (testCase.budget) {
        // events: ['p', ms] = Google's reader decoded a frame, ['h', cost, now] = zbar ran, ['may', now]
        const budget = new HelperBudget();
        const answers = testCase.budget.map(([kind, a, b]) => {
            if (kind === 'p') { budget.primaryDecoded(a); return null; }
            if (kind === 'h') { budget.helperRan(a, b); return null; }
            return budget.mayRun(a);
        });

        return { answers, baselineMs: budget.baselineMs, backoff: budget.backoff, maxBackoff: budget.maxBackoff };
    }

    const acceptance = new ReadAcceptance(0);
    testCase.readers.forEach(name => acceptance.addReader(name));

    const results = testCase.reads.map((read) => {
        if (read.together) {
            acceptance.seenTogether(read.reader, read.together, read.at);
            return null;
        }

        return acceptance.read(read.reader, read.code, read.quality ?? undefined, read.at);
    });

    return {
        results,
        solo: Object.fromEntries(testCase.readers.map(name => [name, acceptance.soloMs(name)])),
        canonical: (testCase.canonical ?? []).map(canonicalCode),
    };
})));
