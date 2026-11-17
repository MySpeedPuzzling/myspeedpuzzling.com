/**
 * Time proposals on the judge page (competition toolkit C9 - R-review-and-plan.md §1.2, §2.4, C-judge-page.md §3):
 * a competitor's own Stop tap waits as a `pending` proposal until a judge opens the entry and confirms it, changes it
 * or says it is not right. Nothing is confirmed from a list: the "Proposals waiting" rows open the entry's card.
 *
 * - The proposals come with the round state (`proposals`, only while the competition has them on) and the Mercure
 *   message `official_results.proposals` - kept by id. A decided proposal never becomes pending again (a late push or
 *   an older state answer cannot bring back what was decided).
 * - A confirmation is the entry's `result` change carrying `proposalId` (a pick round: with its `puzzles` in one
 *   group), a rejection a `field: "proposal"` change - both through the outbox, offline-safe; the server's outcome
 *   annotates `proposal: {id, status}`.
 * - Late (more than 5 s between the tap and its receipt): both times are shown and the value defaults to the receipt.
 *
 * Pure functions - tests/live-results-harness.mjs runs them under node.
 */

import { sameValue, shownValue } from './official_results_live.js';
import { saveFields } from './live_entry_puzzles.js';

const STATUSES = ['pending', 'confirmed', 'rejected', 'superseded', 'withdrawn'];
export const REJECT_REASONS = ['not_finished', 'wrong_entry', 'other'];

function key(value) {
    return String(value ?? '').toLowerCase();
}

/**
 * A proposal of the state or a push as the page keeps it, or null when it cannot be one.
 */
export function normaliseProposal(raw) {
    if (!raw || typeof raw.id !== 'string' || typeof raw.entry !== 'string' || !STATUSES.includes(raw.status)
        || !Number.isInteger(raw.proposedSeconds) || !Number.isInteger(raw.receiptSeconds)) {
        return null;
    }

    return { ...raw, id: key(raw.id), entry: key(raw.entry) };
}

/**
 * The page's proposals after `incoming` (a push: merged by id). `stateAtMs` set = a state answer, which lists every
 * proposal of the round that is pending or decided lately: one missing from it goes - unless it came (or was decided)
 * after the state was read.
 *
 * @param {Map<string, object>} current
 * @returns {Map<string, object>}
 */
export function mergeProposals(current, incoming, { stateAtMs = null } = {}) {
    const merged = new Map(current);
    const listed = new Set();

    for (const raw of Array.isArray(incoming) ? incoming : []) {
        const proposal = normaliseProposal(raw);

        if (proposal === null) {
            continue;
        }

        listed.add(proposal.id);
        const before = merged.get(proposal.id);

        // Decided is final: an older copy saying "pending" changes nothing
        if (before !== undefined && before.status !== 'pending' && proposal.status === 'pending') {
            continue;
        }

        merged.set(proposal.id, proposal);
    }

    if (typeof stateAtMs === 'number' && Number.isFinite(stateAtMs)) {
        for (const [id, proposal] of merged) {
            const newer = Math.max(proposal.receivedAtMs ?? 0, Date.parse(proposal.decided?.at ?? '') || 0);

            if (!listed.has(id) && newer <= stateAtMs) {
                merged.delete(id);
            }
        }
    }

    return merged;
}

/**
 * The server's word on a proposal after a change (`proposal: {id, status}`): a decision is kept on the page at once.
 * `not_pending` / `unknown` (and `superseded` of a proposal shown as confirmed) say the page's copy is old - the
 * caller asks for the state.
 *
 * @returns {{proposals: Map<string, object>, stale: boolean}}
 */
export function applyOutcome(current, annotation, decidedAt = new Date().toISOString()) {
    const id = key(annotation?.id);
    const status = annotation?.status;
    const before = current.get(id);

    // A confirmation undone (the result cleared or changed afterwards) - the state says why
    if (status === 'not_pending' || status === 'unknown' || (status === 'superseded' && before?.status === 'confirmed')) {
        return { proposals: current, stale: true };
    }

    if (before === undefined || !STATUSES.includes(status) || status === 'pending' || before.status !== 'pending') {
        return { proposals: current, stale: false };
    }

    const proposals = new Map(current);
    proposals.set(id, { ...before, status, decided: before.decided ?? { at: decidedAt, byName: null, seconds: null, reason: null } });

    return { proposals, stale: false };
}

/**
 * This device's change that decides the proposal (a confirmation or a rejection in the outbox), or null.
 */
export function decisionOnDevice(proposalId, outboxItems) {
    const id = key(proposalId);

    return outboxItems.find((item) => (item.field === 'result' && key(item.proposalId) === id)
        || (item.field === 'proposal' && key(item.from?.id) === id)) ?? null;
}

/**
 * The value a Confirm saves: the proposed time - the receipt's when the tap came late.
 */
export function proposalValue(proposal) {
    return { seconds: proposal.late === true ? proposal.receiptSeconds : proposal.proposedSeconds };
}

/**
 * Who proposed, shown on the card ("by <name>") only for a pair/team entry: on a solo entry the proposer is the card's
 * own person, so the name would only repeat the card's title (toolkit H1). Null = say nothing.
 */
export function proposerName(proposal, entry) {
    if (proposal === null || entry === null || entry.kind !== 'team') {
        return null;
    }

    const name = proposal.proposedBy?.name;

    return typeof name === 'string' && name !== '' ? name : null;
}

/**
 * The entry's proposal to show on its card: the pending one, else the one decided last.
 */
export function proposalOfEntry(proposals, ref) {
    const entry = key(ref);
    let shown = null;

    for (const proposal of proposals.values()) {
        if (proposal.entry !== entry) {
            continue;
        }

        if (proposal.status === 'pending') {
            return proposal;
        }

        if (shown === null || (Date.parse(proposal.decided?.at ?? '') || 0) > (Date.parse(shown.decided?.at ?? '') || 0)) {
            shown = proposal;
        }
    }

    return shown;
}

/**
 * "Proposals waiting": pending, of an entry the page knows, not decided on this device yet - oldest receipt first.
 *
 * @param {function(string): object|null} entryOf
 * @returns {{proposal: object, entry: object}[]}
 */
export function waitingProposals(proposals, entryOf, outboxItems) {
    const rows = [];

    for (const proposal of proposals.values()) {
        const entry = proposal.status === 'pending' ? entryOf(proposal.entry) : null;

        if (entry !== null && decisionOnDevice(proposal.id, outboxItems) === null) {
            rows.push({ proposal, entry });
        }
    }

    return rows.sort((a, b) => (a.proposal.receivedAtMs ?? 0) - (b.proposal.receivedAtMs ?? 0));
}

/**
 * What the card's proposal panel shows:
 * - `confirm`: pending, nothing saved - one Confirm;
 * - `differs`: pending, another result is saved (or waits on this device) - "Replace with";
 * - `matches`: pending, the same time is saved already - no button (the server settles it);
 * - `sending`: this device decided it, the change waits in the outbox;
 * - `decided`: confirmed / rejected / superseded / withdrawn (lately) - no button;
 * - null: nothing to show (a decision of this device the outbox could not send - its problem is shown instead).
 */
export function proposalPanel(proposal, entry, outboxItems) {
    if (proposal === null || entry === null) {
        return null;
    }

    const value = proposalValue(proposal);
    const decision = decisionOnDevice(proposal.id, outboxItems);

    if (decision !== null) {
        return decision.state === 'pending' ? { state: 'sending', value, rejecting: decision.field === 'proposal' } : null;
    }

    if (proposal.status !== 'pending') {
        return { state: 'decided', value };
    }

    const saved = shownValue(entry, 'result', outboxItems).value;

    if (saved === null || saved === undefined) {
        return { state: 'confirm', value, saved: null };
    }

    return { state: sameValue(saved, value) ? 'matches' : 'differs', value, saved };
}

/**
 * The fields of a Save that decides the proposal: the result carries `proposalId` (always sent - a value equal to the
 * saved one still confirms); a pick round's puzzle record goes with it as one group, and is required - the judge's
 * puzzle choice is part of WHO (`needsChoice` until it is complete).
 */
export function confirmFields(format, choice, value, entry, outboxItems, proposalId) {
    const { fields, record, refusal } = saveFields(format, choice, value, entry, outboxItems);
    const finished = value !== null && Number.isInteger(value?.seconds);

    if (format !== null && finished && (record === null || refusal !== null)) {
        return { fields: [], record, needsChoice: true };
    }

    return {
        fields: [...fields.filter((field) => field.field !== 'result'), { field: 'result', to: value, proposalId: key(proposalId) }],
        record,
        needsChoice: false,
    };
}

/**
 * A judge's "Not right" as an outbox change.
 */
export function rejectionChange(proposal, reason) {
    return {
        field: 'proposal',
        from: { id: proposal.id },
        to: { status: 'rejected', reason: REJECT_REASONS.includes(reason) ? reason : 'other' },
    };
}

/**
 * How long ago (server clock) - for "tapped 3 min ago" and the waiting list.
 *
 * @returns {{unit: 'seconds'|'minutes', count: number}}
 */
export function ageOf(atMs, nowMs) {
    const seconds = Math.max(0, Math.floor((nowMs - atMs) / 1000));

    return seconds < 60 ? { unit: 'seconds', count: seconds } : { unit: 'minutes', count: Math.floor(seconds / 60) };
}
