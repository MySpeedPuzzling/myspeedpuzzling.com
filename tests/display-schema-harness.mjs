// Runs assets/displays/schema.js over the shared cases of tests/fixtures/display-design-cases.json - the same file
// DisplayDesignSanitizerTest runs the PHP sanitizer over, so the two cannot drift. Key order counts: the browser must
// produce the very JSON the server stores. Also normalises the presets DisplayDesignSanitizerTest hands over on stdin
// (the server's normalised presets) - each must come back unchanged.
// Prints {"<case>": "ok" | "<what differs>"} as JSON (DisplaySchemaScriptTest).

import { readFileSync } from 'node:fs';
import { normaliseDesign, usesLiveResults, usesResults } from '../assets/displays/schema.js';

const file = JSON.parse(readFileSync(new URL('./fixtures/display-design-cases.json', import.meta.url), 'utf8'));
const stdin = readFileSync(0, 'utf8');
const presets = stdin.trim() === '' ? {} : JSON.parse(stdin).presets;
const results = {};

function inputOf(testCase) {
    const input = testCase.input;

    if (testCase.repeat) {
        const template = JSON.stringify(testCase.repeat.element);
        input.elements = Array.from({ length: testCase.repeat.count }, (_, index) => JSON.parse(template
            .replaceAll('{i8}', String(index).padStart(8, '0'))
            .replaceAll('{i12}', String(index).padStart(12, '0'))));
    }

    return input;
}

for (const testCase of file.cases) {
    const { design, errors } = normaliseDesign(inputOf(testCase), { competitionId: file.competitionId });
    let result = 'ok';

    if (testCase.errors) {
        if (JSON.stringify(errors) !== JSON.stringify(testCase.errors)) {
            result = `errors ${JSON.stringify(errors)}`;
        }
    } else if (errors.length > 0) {
        result = `refused ${JSON.stringify(errors)}`;
    } else if (JSON.stringify(design) !== JSON.stringify(testCase.expect)) {
        result = `normalised ${JSON.stringify(design)}`;
    } else if (testCase.uses && (usesResults(design) !== testCase.uses.results || usesLiveResults(design) !== testCase.uses.live)) {
        result = `uses ${usesResults(design)} ${usesLiveResults(design)}`;
    } else {
        // Normalising a normalised design changes nothing
        const again = normaliseDesign(JSON.parse(JSON.stringify(design)), { competitionId: file.competitionId });

        if (JSON.stringify(again.design) !== JSON.stringify(design)) {
            result = 'not idempotent';
        }
    }

    results[testCase.name] = result;
}

for (const [key, preset] of Object.entries(presets)) {
    const { design, errors } = normaliseDesign(preset.design, { competitionId: preset.competitionId });
    results[`preset ${key}`] = errors.length === 0 && JSON.stringify(design) === JSON.stringify(preset.design)
        ? 'ok'
        : `preset differs ${JSON.stringify(errors)} ${JSON.stringify(design)}`;
}

process.stdout.write(JSON.stringify(results));
