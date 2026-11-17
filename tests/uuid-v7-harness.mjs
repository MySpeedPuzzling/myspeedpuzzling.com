// Runs the browser's id generator - assets/uuid_v7.js, behind official_results_api.js newClientId(), the referees'
// outbox (official_results_outbox.js) and the participant phone's events (online/event_outbox.js) - through its
// scenarios and prints {"scenarios": {scenario: "ok" | error}, "samples": [{ms, id}]} as JSON.
// tests/UuidV7ScriptTest.php asserts every scenario is "ok" and reads the samples back with ramsey/uuid.

import assert from 'node:assert/strict';
import { uuidv7 } from '../assets/uuid_v7.js';
import { newClientId } from '../assets/official_results_api.js';
import { createMemoryStorage, Outbox } from '../assets/official_results_outbox.js';
import { uuidv7 as eventId } from '../assets/online/event_outbox.js';

const V7 = /^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/;
const MS = 1_794_873_600_123; // 2026-11-17T00:00:00.123Z = 0x01a1e6cda87b

function prefixMs(id) {
    return parseInt(id.replace(/-/g, '').slice(0, 12), 16);
}

/** Runs `task` with crypto.randomUUID throwing - the generator must never need it (no secure context needed). */
function withoutRandomUuid(task) {
    const descriptor = Object.getOwnPropertyDescriptor(globalThis.crypto, 'randomUUID');
    Object.defineProperty(globalThis.crypto, 'randomUUID', {
        configurable: true,
        value: () => {
            throw new Error('the version 4 generator was called');
        },
    });

    try {
        return task();
    } finally {
        if (descriptor === undefined) {
            delete globalThis.crypto.randomUUID;
        } else {
            Object.defineProperty(globalThis.crypto, 'randomUUID', descriptor);
        }
    }
}

const scenarios = {
    'version 7 and the RFC variant, whatever the random bytes'() {
        for (const fill of [0x00, 0xff, 0xab, 0x5c]) {
            const id = uuidv7(MS, (bytes) => bytes.fill(fill));
            const hex = id.replace(/-/g, '');

            assert.match(id, V7, `fill ${fill}`);
            assert.equal(parseInt(hex[12], 16), 7, 'version nibble');
            assert.equal(parseInt(hex[16], 16) >> 2, 0b10, 'variant bits 10');
        }
    },

    'the first 48 bits are the Unix milliseconds, big-endian'() {
        assert.equal(uuidv7(MS, (bytes) => bytes.fill(0)), '01a1e6cd-a87b-7000-8000-000000000000');
        assert.equal(prefixMs(uuidv7(MS)), MS);
        assert.equal(uuidv7(0, (bytes) => bytes.fill(0)), '00000000-0000-7000-8000-000000000000');
        assert.equal(uuidv7(2 ** 48 - 1, (bytes) => bytes.fill(0)), 'ffffffff-ffff-7000-8000-000000000000');
        assert.equal(prefixMs(uuidv7(MS + 0.9)), MS, 'a fraction of a millisecond is cut off');
        assert.equal(prefixMs(uuidv7(-5)), 0, 'never below zero');
    },

    'the other 74 bits come from the random source'() {
        // 12 bits after the version, 62 after the variant: everything else of the random bytes is kept
        assert.equal(uuidv7(MS, (bytes) => bytes.fill(0xff)), '01a1e6cd-a87b-7fff-bfff-ffffffffffff');
        assert.equal(uuidv7(MS, (bytes) => bytes.fill(0xab)), '01a1e6cd-a87b-7bab-abab-abababababab');
    },

    'ids sort by their moment (as strings and as bytes), across byte boundaries'() {
        const moments = [MS - 1, MS, MS + 1, 0xff, 0x100, 0xffff, 0x10000, 0xffffffff, 0x100000000, 2 ** 48 - 2];
        moments.sort((a, b) => a - b);
        const ids = moments.map((ms) => uuidv7(ms));

        assert.deepEqual([...ids].sort(), ids);

        // A day of a sheet's work: later ids never sort before earlier ones from another millisecond
        let ms = MS;
        let previous = uuidv7(ms);

        for (let i = 0; i < 5000; i++) {
            ms += 1 + Math.floor(Math.random() * 50_000);
            const next = uuidv7(ms);
            assert.ok(previous < next, `${previous} < ${next}`);
            previous = next;
        }
    },

    'unique within one millisecond (a paste makes many at once)'() {
        const seen = new Set();

        for (let i = 0; i < 100_000; i++) {
            const id = uuidv7(MS);
            assert.match(id, V7);
            seen.add(id);
        }

        assert.equal(seen.size, 100_000);
    },

    'newClientId() is a UUIDv7 of now, without the secure-context-only generator'() {
        const before = Date.now();
        const ids = withoutRandomUuid(() => Array.from({ length: 1000 }, () => newClientId()));
        const after = Date.now();

        assert.equal(new Set(ids).size, ids.length);

        for (const id of ids) {
            assert.match(id, V7);
            assert.ok(prefixMs(id) >= before && prefixMs(id) <= after, 'the moment it was made');
        }
    },

    'the referees\' outbox makes UUIDv7 ids by default'() {
        const outbox = new Outbox({ storage: createMemoryStorage(), send: async () => ({ kind: 'ok' }), userId: 'u1' });
        const id = withoutRandomUuid(() => outbox.newId());

        assert.match(id, V7);
    },

    'the participant phone\'s events use the same generator'() {
        assert.equal(eventId, uuidv7);
    },
};

const results = {};

for (const [name, run] of Object.entries(scenarios)) {
    try {
        await run();
        results[name] = 'ok';
    } catch (error) {
        results[name] = String(error?.stack ?? error);
    }
}

const samples = [MS, 0, 2 ** 48 - 1, Date.now()].map((ms) => ({ ms, id: uuidv7(ms) }));
samples.push({ ms: null, id: newClientId() });

process.stdout.write(JSON.stringify({ scenarios: results, samples }));
