<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * What one change of RecordRoundResults sets on a round entry. Wire values of `from` / `to`:
 * - result: RoundEntryResult's wire format (null, {"seconds": n}, {"piecesPlaced": n}, {"didNotStart": true})
 * - table_number: null or 1..9999
 * - qualified: true / false
 * - puzzles: RoundEntryPuzzles' wire format (null or a list) - `pick` rounds only (competition toolkit A3)
 * - proposal: a judge rejects a competitor's time proposal (competition toolkit P1) - `from` {"id": "<proposalId>"},
 *   `to` {"status": "rejected", "reason": "not_finished" | "wrong_entry" | "other"}; nothing of the entry changes
 */
enum RoundResultField: string
{
    case Result = 'result';
    case TableNumber = 'table_number';
    case Qualified = 'qualified';
    case Puzzles = 'puzzles';
    case Proposal = 'proposal';
}
