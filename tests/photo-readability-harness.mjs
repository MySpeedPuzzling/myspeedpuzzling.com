// Runs assets/photo_readability.js (is a picked photo empty or unreadable, which message a lost save gets, what the
// telemetry may say about an error) for the cases tests/PhotoReadabilityScriptTest.php hands over on stdin, and prints
// each answer as JSON. A rejection of any function is itself an answer ({rejected: …}) - none of them may reject.

import { readFileSync } from 'node:fs';
import { describeError, isEmptyPhoto, probePhoto, refusalOf, saveFailureKind } from '../assets/photo_readability.js';

const cases = JSON.parse(readFileSync(0, 'utf8'));

function errorOf(description) {
    const error = new Error(description.message ?? '');
    error.name = description.name ?? 'Error';

    return error;
}

// A picked file as the browser hands it over: `read` says what asking for its first byte does
function fakeFile(description) {
    if (description === null) {
        return null;
    }

    if ('blob' in description) {
        return new Blob([Buffer.from(description.blob, 'base64')]);
    }

    const file = { size: description.size, name: description.name ?? 'IMG_0001.jpg' };

    if (description.read === 'no-slice') {
        return file;
    }

    file.slice = () => {
        switch (description.read) {
            case 'slice-throws':
                throw errorOf({ name: 'TypeError', message: 'slice is broken' });
            case 'no-array-buffer':
                return {};
            case 'throws':
                return { arrayBuffer: () => { throw errorOf({ name: 'TypeError', message: 'arrayBuffer is broken' }); } };
            case 'not-a-promise':
                return { arrayBuffer: () => new ArrayBuffer(1) };
            case 'rejects':
                return { arrayBuffer: () => Promise.reject(errorOf(description.error ?? { name: 'NotReadableError', message: 'The requested file could not be read' })) };
            case 'hangs':
                return { arrayBuffer: () => new Promise(() => {}) };
            case 'slow':
                return { arrayBuffer: () => new Promise((resolve) => setTimeout(() => resolve(new ArrayBuffer(1)), description.afterMs)) };
            case 'no-bytes':
                return { arrayBuffer: () => Promise.resolve(new ArrayBuffer(0)) };
            case 'garbage':
                return { arrayBuffer: () => Promise.resolve(undefined) };
            default:
                return { arrayBuffer: () => Promise.resolve(new ArrayBuffer(1)) };
        }
    };

    return file;
}

function fakeResponse(description) {
    return {
        json: () => {
            switch (description.body) {
                case 'hangs':
                    return new Promise(() => {});
                case 'rejects':
                    return Promise.reject(new SyntaxError('Unexpected token'));
                case 'throws':
                    throw new TypeError('body used already');
                default:
                    return Promise.resolve(description.body);
            }
        },
    };
}

async function answer(testCase) {
    switch (testCase.fn) {
        case 'empty':
            return isEmptyPhoto(fakeFile(testCase.file));
        case 'probe': {
            const startedAt = Date.now();
            const result = await probePhoto(fakeFile(testCase.file), testCase.timeoutMs ?? 50);

            return { ...result, tookMs: Date.now() - startedAt };
        }
        case 'describe':
            return describeError(testCase.raw !== undefined ? testCase.raw : errorOf(testCase.error), testCase.hide ?? []);
        case 'failure':
            return saveFailureKind(testCase.save);
        case 'refusal':
            return refusalOf(fakeResponse(testCase.response), testCase.timeoutMs ?? 50);
        default:
            throw new Error('Unknown case ' + testCase.fn);
    }
}

const answers = [];

for (const testCase of cases) {
    try {
        answers.push(await answer(testCase));
    } catch (error) {
        answers.push({ rejected: String(error) });
    }
}

process.stdout.write(JSON.stringify(answers));
// Promises that never settle (a read that hangs) must not keep the run alive
process.exit(0);
