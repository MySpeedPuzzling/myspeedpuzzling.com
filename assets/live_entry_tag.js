/**
 * Which entry of a round a scanned competition tag means (competition toolkit C8, docs/features/competitions-management/
 * toolkit/R-review-and-plan.md §1.3, B-qr-competitor-page.md §3.1-3.3) - on the judge's phone, from the round it has,
 * so it works offline as well. Pure - run by tests/live-entry-shell-harness.mjs.
 *
 * A tag is official_results_scan.js parseCompetitionTagUrl(): `{competitionId, kind, id}` -
 * - participant (the printed name tag): the person's entry - their own, or the pair/team they are in;
 * - lineup: the pair/team whose exact member set hashes to the id (CompetitionLineupId in PHP - the same uuid5, pinned
 *   by shared cases with CompetitionLineupIdTest);
 * - table: the entry at that table (a number);
 * - entry: the entry with that id (CompetitionParticipantRound or CompetitionTeam id).
 */

import { entryOfParticipant } from './official_results_live.js';

// SHA-1 (FIPS 180-4) of a byte list - small and synchronous: crypto.subtle is async and missing outside https
function sha1(bytes) {
    const length = bytes.length;
    const words = new Uint32Array((((length + 8) >> 6) + 1) * 16);
    const rotate = (x, n) => (x << n) | (x >>> (32 - n));
    const h = [0x67452301, 0xefcdab89, 0x98badcfe, 0x10325476, 0xc3d2e1f0];
    const w = new Uint32Array(80);

    [...bytes, 0x80].forEach((byte, i) => {
        words[i >> 2] |= byte << (24 - (i % 4) * 8);
    });
    words[words.length - 1] = length * 8;

    for (let block = 0; block < words.length; block += 16) {
        const v = [...h];

        for (let t = 0; t < 80; t += 1) {
            w[t] = t < 16 ? words[block + t] : rotate(w[t - 3] ^ w[t - 8] ^ w[t - 14] ^ w[t - 16], 1);
            const [a, b, c, d, e] = v;
            const f = t < 20 ? (b & c) | (~b & d) : t < 40 || t >= 60 ? b ^ c ^ d : (b & c) | (b & d) | (c & d);
            v.unshift((rotate(a, 5) + f + e + w[t] + [0x5a827999, 0x6ed9eba1, 0x8f1bbcdc, 0xca62c1d6][(t / 20) | 0]) | 0);
            v[2] = rotate(b, 30);
            v.pop();
        }

        v.forEach((value, i) => {
            h[i] = (h[i] + value) | 0;
        });
    }

    return h.flatMap((word) => [24, 16, 8, 0].map((shift) => (word >>> shift) & 255));
}

/**
 * RFC 4122 version 5 (SHA-1) uuid of `name` in the `namespace` uuid - Ramsey's Uuid::uuid5().
 */
export function uuid5(namespace, name) {
    const hex = String(namespace).replace(/-/g, '');
    const bytes = [...hex.match(/../g).map((pair) => parseInt(pair, 16)), ...new TextEncoder().encode(String(name))];
    const hash = sha1(bytes).slice(0, 16);

    hash[6] = (hash[6] & 0x0f) | 0x50;
    hash[8] = (hash[8] & 0x3f) | 0x80;

    const out = hash.map((byte) => byte.toString(16).padStart(2, '0')).join('');

    return `${out.slice(0, 8)}-${out.slice(8, 12)}-${out.slice(12, 16)}-${out.slice(16, 20)}-${out.slice(20)}`;
}

/**
 * CompetitionLineupId::of(): uuid5 in the event's namespace over the sorted, lower-cased member participant ids.
 */
export function lineupIdOf(competitionId, participantIds) {
    const members = [...new Set(participantIds.map((id) => String(id).toLowerCase()))].sort();

    return uuid5(String(competitionId).toLowerCase(), members.join(','));
}

/**
 * The entry among `entries` (one round's) the tag stands for, null when none.
 */
export function entryOfTag(entries, tag) {
    const list = Array.isArray(entries) ? entries : [];
    const id = typeof tag?.id === 'string' ? tag.id.toLowerCase() : tag?.id;

    switch (tag?.kind) {
        case 'participant':
            return entryOfParticipant(list, id);
        case 'table':
            return list.find((entry) => entry.tableNumber === id) ?? null;
        case 'entry':
            return list.find((entry) => String(entry.id ?? String(entry.ref ?? '').split(':')[1]).toLowerCase() === id) ?? null;
        case 'lineup':
            // The round state's own `lineupId` first: the server hashes every member not removed - the waitlist too,
            // like the tag route - while `members` lists only the going ones (toolkit H1). Hashed here only when the
            // state has none (an older snapshot)
            return list.find((entry) => typeof entry.lineupId === 'string'
                ? entry.lineupId.toLowerCase() === id
                : Array.isArray(entry.members) && entry.members.length > 0
                    && lineupIdOf(tag.competitionId, entry.members.map((member) => member.participantId)) === id) ?? null;
        default:
            return null;
    }
}

/**
 * The tag's printed path on this site (`/live/{competitionId}/g|t|e/{id}`) - the server (or, offline, the page's copy)
 * picks the round. Null for the name tag, which has its own route.
 */
export function tagPath(tag) {
    const letter = { lineup: 'g', table: 't', entry: 'e' }[tag?.kind];

    return letter ? `/live/${tag.competitionId}/${letter}/${tag.id}` : null;
}
