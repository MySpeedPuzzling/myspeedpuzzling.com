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
//
// Puzzle choice (competition toolkit v2 C3): on a `pick` round the "Propose?" sheet carries optional chips (labels; a
// name only once the puzzle is out - the state never has more); Propose is never held back by them and they never move
// the tap's moment. While the tap waits (sending, offline, pending) the slot says the choice ("Puzzles: B → A") and
// offers "Change puzzles" / "Tell the judge which puzzle" - the same chips with Save. Places are keyed by their
// position as a string (the chip key); the wire is [{position, label}].

import { roundView } from './competitor_page_live.js';
import { togglePick } from './round_puzzle_pick.js';

export { togglePick };

// Refusals after which another tap cannot help in this round
export const FINAL_REFUSALS = ['linked_after_start', 'not_your_entry', 'proposals_off', 'result_recorded', 'entry_not_in_round', 'round_not_found', 'times_up', 'too_late'];

export const NONE = Object.freeze({ key: 'none', mode: 'none', button: false, undo: false, status: null, confirm: null, choice: null });

/**
 * A pick round's places as the competitor's state lists them, or null (not a pick round). K = `toSolve` (1 if unknown).
 *
 * @returns {null|{toSolve: number, slots: {key: string, position: number, label: string, name: null|string}[]}}
 */
export function pickOf(round) {
    const format = round?.puzzleFormat;

    if (!format || format.mode !== 'pick' || !Array.isArray(round.puzzles)) {
        return null;
    }

    const slots = round.puzzles
        .filter((place) => place && Number.isInteger(place.position) && typeof place.label === 'string' && place.label !== '')
        .map((place) => ({
            key: String(place.position),
            position: place.position,
            label: place.label,
            name: typeof place.puzzle?.name === 'string' && place.puzzle.name !== '' ? place.puzzle.name : null,
        }));

    return slots.length === 0 ? null : { toSolve: Number.isInteger(format.toSolve) && format.toSolve > 0 ? format.toSolve : 1, slots };
}

/**
 * The chips: every place with its solving order in `choice` (0 = not chosen).
 */
export function choiceChips(pick, choice) {
    return pick.slots.map((slot) => ({ ...slot, order: choice.indexOf(slot.key) + 1 }));
}

/**
 * The chosen places as sent - [{position, label}] in solving order - or null (none).
 */
export function choiceWire(pick, choice) {
    const places = choice.map((key) => pick.slots.find((slot) => slot.key === key)).filter(Boolean).map((slot) => ({ position: slot.position, label: slot.label }));

    return places.length === 0 ? null : places;
}

/**
 * The chip keys of a choice sent before ([{position, label}]) - only places whose label is still theirs, at most K.
 */
export function choiceOfPlaces(pick, places) {
    return (Array.isArray(places) ? places : [])
        .filter((place) => pick.slots.some((slot) => slot.position === place?.position && slot.label === place?.label))
        .map((place) => String(place.position))
        .filter((key, index, all) => all.indexOf(key) === index)
        .slice(0, pick.toSolve);
}

/**
 * "B → A" - or null for no choice.
 */
export function choiceLabels(places) {
    const labels = (Array.isArray(places) ? places : []).map((place) => place?.label).filter((label) => typeof label === 'string' && label !== '');

    return labels.length === 0 ? null : labels.join(' → ');
}

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

function panel(mode, { button = false, undo = false, status = null, confirm = null, choice = null } = {}) {
    return { key: JSON.stringify([mode, button, undo, status, confirm, choice]), mode, button, undo, status, confirm, choice };
}

/**
 * The choice part of a waiting tap's slot (v2 C3): the link, or the chips being edited.
 */
function choicePart(pick, proposalId, shown, choosing) {
    const places = Array.isArray(shown) && shown.length > 0 ? shown : null;

    if (pick === null) {
        return null;
    }

    if (choosing !== null && choosing.proposalId === proposalId) {
        return { proposalId, places, link: null, edit: { toSolve: pick.toSolve, chips: choiceChips(pick, choosing.choice ?? []), message: choosing.message ?? null } };
    }

    return { proposalId, places, link: choiceLabels(places) === null ? 'puzzles_tell' : 'puzzles_change', edit: null };
}

/**
 * @param {object} input
 * @param {object} input.round        the state's round
 * @param {number} input.nowMs        server time
 * @param {null|object} [input.item]  the round's outbox item (ProposalOutbox.itemOf())
 * @param {null|string} [input.blocked] 'auth' | 'csrf' - the outbox stopped sending
 * @param {null|object} [input.answer]  the last answer for the round {kind, reason, message, proposal}
 * @param {null|object} [input.confirming] {tappedAtMs, choice?} - Stop tapped, "Propose?" open
 * @param {null|object} [input.choiceItem] v2 C3: the proposal's `puzzles` item still on the phone
 * @param {null|object} [input.choiceAnswer] v2 C3: the last answer to a puzzle change {kind, reason, message, proposal, atMs}
 * @param {null|object} [input.choosing] v2 C3: "Change puzzles" open {proposalId, choice, message}
 * @param {null|number} [input.stateAtMs] the state's serverNowMs - a `chosen` answer newer than it shows its choice
 * @returns {{key: string, mode: string, button: boolean, undo: boolean, status: null|{label: string, time?: number, proposed?: number, reason?: string, message?: string, puzzles?: null|string, notSent?: null|string}, confirm: null|{seconds: number, pick: null|object}, choice: null|object}}
 */
export function proposalPanel({ round, nowMs, item = null, blocked = null, answer = null, confirming = null, choiceItem = null, choiceAnswer = null, choosing = null, stateAtMs = null }) {
    if (!round || round.canPropose !== true || !round.entry || round.entry.unpaired === true || round.masked === true) {
        return NONE;
    }

    const running = roundView(round, nowMs).phase === 'running';
    const open = running && !hasResult(round);
    const pick = pickOf(round);

    // A tap still on the phone
    if (item !== null && item.action === 'propose') {
        const label = blocked === 'auth' ? 'sign_in_again' : (blocked === 'csrf' ? 'reload' : (item.offline ? 'offline' : 'sending'));
        const status = { label, time: secondsAt(round, item.tappedAtMs) };

        if (pick === null) {
            return panel(label, { undo: true, status });
        }

        // v2 C3: its choice rides along - changeable while it waits (not while nothing can be sent); a change made while
        // it was on its way waits behind it
        const later = choiceItem !== null && choiceItem.proposalId === item.id;
        const places = later ? choiceItem.puzzles : item.puzzles;
        const choice = blocked === null ? choicePart(pick, item.id, places, choosing) : null;

        return panel(label, { undo: choice === null || choice.edit === null, status: { ...status, puzzles: choiceLabels(places), notSent: null, sending: later }, choice });
    }

    if (item !== null && item.action === 'withdraw') {
        return panel('withdrawing', { status: { label: 'withdrawing' } });
    }

    if (confirming !== null && !hasResult(round)) {
        const seconds = secondsAt(round, confirming.tappedAtMs);

        // v2 C3: optional chips under the question - the tap's moment stays the one frozen at Stop
        return panel('confirm', {
            confirm: pick === null ? { seconds } : { seconds, pick: { toSolve: pick.toSolve, chips: choiceChips(pick, confirming.choice ?? []) } },
        });
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
            return pick === null
                ? panel('pending', { undo: true, status: { label: 'pending', time: proposed } })
                : pendingWithChoice(pick, proposal, { answer, choiceItem, choiceAnswer, choosing, stateAtMs });
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
            // "You took back …" is for the moment after the Undo, while the competitor may still tap again - once the
            // entry has a result or the round no longer runs it is past, nothing to say (spws-demo-ux-leftovers.md §7)
            return open ? panel('withdrawn', { button: true, status: { label: 'withdrawn', time: proposed } }) : NONE;
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
 * A pending proposal of a pick round (v2 C3): its choice - the change still on the phone, else a `chosen` answer newer
 * than the state, else the state's - and why a choice was not taken.
 */
function pendingWithChoice(pick, proposal, { answer, choiceItem, choiceAnswer, choosing, stateAtMs }) {
    const own = choiceAnswer !== null && choiceAnswer.proposalId === proposal.id ? choiceAnswer : null;
    let places = proposal.puzzles ?? null;

    if (choiceItem !== null && choiceItem.proposalId === proposal.id) {
        places = choiceItem.puzzles ?? null;
    } else if (own?.kind === 'chosen' && own.proposal?.id === proposal.id && (own.atMs ?? 0) > (stateAtMs ?? 0)) {
        places = own.proposal.puzzles ?? null;
    }

    let notSent = null;

    if (choiceItem === null || choiceItem.proposalId !== proposal.id) {
        // A changed round's refusal reopens the chips with its message instead (the page)
        if (own?.kind === 'refused' && own.reason !== 'puzzles_changed') {
            notSent = own.message ?? '';
        } else if (own === null && answer?.proposalId === proposal.id && typeof answer.puzzlesRefused === 'string' && choiceLabels(places) === null) {
            notSent = answer.puzzlesMessage ?? '';
        }
    }

    const choice = choicePart(pick, proposal.id, places, choosing);

    return panel('pending', {
        undo: choice.edit === null,
        status: { label: 'pending', time: proposal.proposedSeconds, puzzles: choiceLabels(places), notSent, sending: choiceItem !== null && choiceItem.proposalId === proposal.id },
        choice,
    });
}

/**
 * Whether the page should ask the server every 5 s: something of the competitor's waits for the judge or is still on
 * the phone.
 */
export function waitsForJudge(panels) {
    return panels.some((p) => ['pending', 'sending', 'offline', 'withdrawing'].includes(p.mode));
}
