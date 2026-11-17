// What a round's "propose my time" slot shows on the competitor's own page (competition toolkit B8,
// toolkit/B-qr-competitor-page.md §5.1, §5.7) - DOM-free: the page's controller draws the descriptor, the harness
// (tests/competitor-proposals-harness.mjs) pins every state.
//
// Sources, newest first: the tap waiting for "Propose?" (confirming), the item still on the phone (outbox: sending /
// offline / signed out / stale page), a refusal the server just gave, then the proposal as the server knows it (its
// last answer or the state's `proposal`, whichever is newer - currentProposal()).
//
// The Stop button shows only on the viewer's own entry (`canPropose` - the server puts it only there, only with time
// proposals on), while the round runs, without a result and with nothing waiting for the judge.

import { roundView } from './competitor_page_live.js';

// Refusals after which another tap cannot help in this round
export const FINAL_REFUSALS = ['linked_after_start', 'not_your_entry', 'proposals_off', 'result_recorded', 'entry_not_in_round', 'round_not_found', 'times_up', 'too_late'];

export const NONE = Object.freeze({ key: 'none', mode: 'none', button: false, undo: false, status: null, confirm: null });

/**
 * The newer of two views of the competitor's proposal: the same proposal - a decided answer beats a stale pending
 * state; else the state; two proposals - the later tap.
 */
export function currentProposal(fromState, fromAnswer) {
    const a = fromState && typeof fromState === 'object' ? fromState : null;
    const b = fromAnswer && typeof fromAnswer === 'object' ? fromAnswer : null;

    if (a === null || b === null) {
        return a ?? b;
    }

    if (a.id === b.id) {
        return a.status === 'pending' && b.status !== 'pending' ? b : a;
    }

    return (b.tappedAtMs ?? 0) > (a.tappedAtMs ?? 0) ? b : a;
}

/**
 * The whole seconds the round's stopwatch showed at `atMs` (server time) - what the server computes from the tap.
 */
export function secondsAt(round, atMs) {
    return Math.floor(roundView(round, atMs).elapsedMs / 1000);
}

function hasResult(round) {
    return round.entry?.result !== null && round.entry?.result !== undefined;
}

function panel(mode, { button = false, undo = false, status = null, confirm = null } = {}) {
    return { key: JSON.stringify([mode, button, undo, status, confirm]), mode, button, undo, status, confirm };
}

/**
 * @param {object} input
 * @param {object} input.round        the state's round
 * @param {number} input.nowMs        server time
 * @param {null|object} [input.item]  the round's outbox item (ProposalOutbox.itemOf())
 * @param {null|string} [input.blocked] 'auth' | 'csrf' - the outbox stopped sending
 * @param {null|object} [input.answer]  the last answer for the round {kind, reason, message, proposal}
 * @param {null|object} [input.confirming] {tappedAtMs} - Stop tapped, "Propose?" open
 * @returns {{key: string, mode: string, button: boolean, undo: boolean, status: null|{label: string, time?: number, proposed?: number, reason?: string, message?: string}, confirm: null|{seconds: number}}}
 */
export function proposalPanel({ round, nowMs, item = null, blocked = null, answer = null, confirming = null }) {
    if (!round || round.canPropose !== true || !round.entry || round.entry.unpaired === true || round.masked === true) {
        return NONE;
    }

    const running = roundView(round, nowMs).phase === 'running';
    const open = running && !hasResult(round);

    // A tap still on the phone
    if (item !== null && item.action === 'propose') {
        const label = blocked === 'auth' ? 'sign_in_again' : (blocked === 'csrf' ? 'reload' : (item.offline ? 'offline' : 'sending'));

        return panel(label, { undo: true, status: { label, time: secondsAt(round, item.tappedAtMs) } });
    }

    if (item !== null && item.action === 'withdraw') {
        return panel('withdrawing', { status: { label: 'withdrawing' } });
    }

    if (confirming !== null && !hasResult(round)) {
        return panel('confirm', { confirm: { seconds: secondsAt(round, confirming.tappedAtMs) } });
    }

    const proposal = currentProposal(round.proposal ?? null, answer?.proposal ?? null);

    if (answer?.kind === 'refused' && answer.reason !== 'already_proposed' && !(proposal?.status === 'pending')) {
        return panel('refused', {
            button: open && !FINAL_REFUSALS.includes(answer.reason),
            status: { label: 'refused', message: answer.message ?? '', reason: answer.reason },
        });
    }

    if (proposal === null) {
        return open ? panel('stop', { button: true }) : NONE;
    }

    const proposed = proposal.proposedSeconds;
    const decided = proposal.decided?.seconds ?? null;

    switch (proposal.status) {
        case 'pending':
            return panel('pending', { undo: true, status: { label: 'pending', time: proposed } });
        case 'confirmed':
            if (round.published) {
                return NONE;
            }

            return decided !== null && decided !== proposed
                ? panel('changed', { status: { label: 'changed', time: decided, proposed } })
                : panel('confirmed', { status: { label: 'confirmed', time: decided ?? proposed } });
        case 'rejected':
            return panel('rejected', { button: open, status: { label: 'rejected', time: proposed, reason: proposal.decided?.reason ?? 'other' } });
        case 'withdrawn':
            return panel('withdrawn', { button: open, status: { label: 'withdrawn', time: proposed } });
        case 'superseded':
            if (proposal.decided?.reason === 'stopwatch_reset') {
                return panel('reset', { button: open, status: { label: 'reset', time: proposed } });
            }

            // The judge undid the confirmation (the result was cleared): the competitor may propose again while it runs
            if (proposal.decided?.reason === 'result_cleared' && !hasResult(round)) {
                return round.published ? NONE : panel('cleared', { button: open, status: { label: 'cleared', time: decided ?? proposed } });
            }

            return round.published ? NONE : panel('superseded', { status: { label: 'superseded' } });
        default:
            return open ? panel('stop', { button: true }) : NONE;
    }
}

/**
 * Whether the page should ask the server every 5 s: something of the competitor's waits for the judge or is still on
 * the phone.
 */
export function waitsForJudge(panels) {
    return panels.some((p) => ['pending', 'sending', 'offline', 'withdrawing'].includes(p.mode));
}
