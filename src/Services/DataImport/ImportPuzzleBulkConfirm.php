<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\DataImport;

use SpeedPuzzling\Web\Entity\DataImportPuzzle;
use SpeedPuzzling\Web\Entity\Puzzle;
use SpeedPuzzling\Web\Query\GetImportPuzzleCandidates;
use SpeedPuzzling\Web\Value\ImportPuzzleChoice;
use SpeedPuzzling\Web\Value\ImportPuzzleTier;

/**
 * Which puzzles of "Looks like a match" may be confirmed **several at once** - "part A" of
 * docs/features/data-import/guided-flow.md §6.1. A wrong match puts a real result on the wrong puzzle's public
 * leaderboard, so the set is narrow, and it is one rule said twice: in SQL for the screen (the picture grid shows
 * exactly these) and in PHP for DecideImportPuzzlesHandler (which refuses a bulk confirm holding anything else -
 * whatever a form sends). `ImportPuzzleBulkConfirmTest` pins that both say the same.
 *
 * Part A - the same puzzle, only less proof than "matched" needs:
 *
 * - the rules said "probably" (ImportPuzzleTier::Probably) and the puzzle still waits;
 * - **exactly one** candidate, and it is the catalogue puzzle confirmed (a candidate merged into another puzzle
 *   since is not part A: its card is confirmed on its own);
 * - found by the whole name with the file's piece count (`name`), by the barcode (`barcode`) or by the brand's own
 *   code (`brand_code`) - never by a name without its brackets, a short name or a result that looks like one;
 * - **the piece count does not differ**: the file's is the puzzle's (one apart at most), or the file has none - and
 *   a match by name always has one;
 * - **the brand does not differ**: the file names no brand at all, or the brand it names is the puzzle's. A brand
 *   text we do not know is not "no brand" - it may be another one;
 * - the puzzle may be offered to the player now (GetImportPuzzleCandidates::sqlCandidate()).
 *
 * Everything else of the pile is part B: shown one by one, confirmed one by one.
 */
final class ImportPuzzleBulkConfirm
{
    /** The lookups that find the same puzzle - what the candidate's `how` must be */
    public const array HOWS = ['name', 'barcode', 'brand_code'];

    /**
     * A condition on one row of `data_import_puzzle` (`$alias`): true for part A, false for anything else - never
     * NULL, so `NOT` of it is part B. Needs the parameters of GetImportPuzzleCandidates::sqlCandidate()
     * (`:now`, `:playerId`).
     */
    public static function sqlPartA(string $alias): string
    {
        $candidate = GetImportPuzzleCandidates::sqlCandidate('part_a_puzzle');

        return <<<SQL
COALESCE((
    {$alias}.tier = 'probably'
    AND {$alias}.choice = 'undecided'
    AND jsonb_typeof({$alias}.candidates) = 'array'
    AND jsonb_array_length({$alias}.candidates) = 1
    AND {$alias}.candidates -> 0 ->> 'how' IN ('name', 'barcode', 'brand_code')
    AND COALESCE({$alias}.candidates -> 0 ->> 'piecesDiffer', 'false') = 'false'
    AND ({$alias}.candidates -> 0 ->> 'how' <> 'name' OR {$alias}.pieces_count IS NOT NULL)
    AND EXISTS (
        SELECT 1
        FROM puzzle part_a_puzzle
        WHERE part_a_puzzle.id = CAST({$alias}.candidates -> 0 ->> 'puzzleId' AS UUID)
            AND {$candidate}
            AND ({$alias}.pieces_count IS NULL OR abs(part_a_puzzle.pieces_count - {$alias}.pieces_count) <= 1)
            AND (
                ({$alias}.manufacturer_id IS NULL AND btrim(COALESCE({$alias}.brand_text, '')) = '')
                OR {$alias}.manufacturer_id = part_a_puzzle.manufacturer_id
            )
    )
), FALSE)
SQL;
    }

    /**
     * The same rule for one puzzle of an import and the catalogue puzzle a bulk confirm names for it. Whether the
     * puzzle may be offered to the player is the handler's own check (`choosable()`).
     */
    public static function isPartA(DataImportPuzzle $importPuzzle, Puzzle $puzzle): bool
    {
        if ($importPuzzle->tier !== ImportPuzzleTier::Probably || $importPuzzle->choice !== ImportPuzzleChoice::Undecided) {
            return false;
        }

        if (count($importPuzzle->candidates) !== 1) {
            return false;
        }

        $candidate = array_values($importPuzzle->candidates)[0];

        if (
            is_array($candidate) === false
            || is_string($candidate['puzzleId'] ?? null) === false
            || strtolower($candidate['puzzleId']) !== $puzzle->id->toString()
            || in_array($candidate['how'] ?? null, self::HOWS, true) === false
            || ($candidate['piecesDiffer'] ?? false) !== false
        ) {
            return false;
        }

        $said = $importPuzzle->piecesCount;

        if ($candidate['how'] === 'name' && $said === null) {
            return false;
        }

        if ($said !== null && abs($puzzle->piecesCount - $said) > 1) {
            return false;
        }

        if ($importPuzzle->manufacturer === null) {
            return trim($importPuzzle->brandText ?? '') === '';
        }

        return $puzzle->manufacturer !== null && $puzzle->manufacturer->id->equals($importPuzzle->manufacturer->id);
    }
}
