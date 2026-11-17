/**
 * A UUIDv7 made in the browser (RFC 9562: 48 bits of Unix milliseconds, version 7, the RFC variant, 74 random bits
 * from crypto.getRandomValues - which, unlike the browser's built-in version 4 UUID, needs no secure context). Every
 * id the browser makes for the server is one: a participant phone's events (online/event_outbox.js), the official
 * results tools' client change ids, change sets and the entries typed in at the venue (official_results_api.js
 * newClientId()). They become primary keys, and a time-ordered id keeps one day's rows together in the primary key
 * index instead of on a random page (.claude/rules/database.md "Ids created in SQL"; tests/SqlCreatedIdsCoverageTest.php
 * forbids the version 4 generators). Pinned by tests/uuid-v7-harness.mjs (UuidV7ScriptTest).
 *
 * @param {number} [ms]      the moment (default now; the participant phone passes the server's time from clock_sync.js)
 * @param {function(Uint8Array): void} [fillRandom]
 */
export function uuidv7(ms = Date.now(), fillRandom = (bytes) => globalThis.crypto.getRandomValues(bytes)) {
    const bytes = new Uint8Array(16);
    fillRandom(bytes);
    let time = Math.max(0, Math.floor(Number.isFinite(ms) ? ms : Date.now()));

    for (let index = 5; index >= 0; index--) {
        bytes[index] = time % 256;
        time = Math.floor(time / 256);
    }

    bytes[6] = (bytes[6] & 0x0f) | 0x70;
    bytes[8] = (bytes[8] & 0x3f) | 0x80;
    const hex = Array.from(bytes, (byte) => byte.toString(16).padStart(2, '0')).join('');

    return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`;
}
