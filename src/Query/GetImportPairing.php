<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Results\ImportPairingSummary;
use SpeedPuzzling\Web\Value\ImportPuzzleChoice;
use SpeedPuzzling\Web\Value\ImportPuzzleDecidedHow;
use SpeedPuzzling\Web\Value\ImportPuzzleTier;

/**
 * Where the pairing of one import stands - numbers only, one statement each
 * (docs/features/data-import/pairing.md "The summary", "Where the rules end"). The caller has checked whose the
 * import is (GetDataImports::byIdOfPlayer()).
 */
readonly final class GetImportPairing
{
    public function __construct(
        private Connection $database,
    ) {
    }

    public function summary(string $importId): ImportPairingSummary
    {
        /** @var false|array{puzzles: int, found: int, probably: int, choose: int, not_found: int, left_out: int, searching: int, paired: bool} $row */
        $row = $this->database->executeQuery(<<<SQL
SELECT
    COUNT(data_import_puzzle.id) AS puzzles,
    COUNT(*) FILTER (WHERE data_import_puzzle.choice = :catalogue) AS found,
    COUNT(*) FILTER (WHERE data_import_puzzle.choice = :undecided AND data_import_puzzle.tier = :probably) AS probably,
    COUNT(*) FILTER (WHERE data_import_puzzle.choice = :undecided AND data_import_puzzle.tier IN (:choose, :found)) AS choose,
    COUNT(*) FILTER (WHERE data_import_puzzle.choice = :unlisted OR (data_import_puzzle.choice = :undecided AND data_import_puzzle.tier = :none)) AS not_found,
    COUNT(*) FILTER (WHERE data_import_puzzle.choice = :leftOut) AS left_out,
    COUNT(*) FILTER (WHERE data_import_puzzle.choice = :undecided AND data_import_puzzle.tier IS NULL) AS searching,
    data_import.paired_at IS NOT NULL AS paired
FROM data_import
LEFT JOIN data_import_puzzle ON data_import_puzzle.import_id = data_import.id
WHERE data_import.id = :importId
GROUP BY data_import.id
SQL, [
            'importId' => $importId,
            'catalogue' => ImportPuzzleChoice::Catalogue->value,
            'undecided' => ImportPuzzleChoice::Undecided->value,
            'unlisted' => ImportPuzzleChoice::Unlisted->value,
            'leftOut' => ImportPuzzleChoice::LeftOut->value,
            'found' => ImportPuzzleTier::Found->value,
            'probably' => ImportPuzzleTier::Probably->value,
            'choose' => ImportPuzzleTier::Choose->value,
            'none' => ImportPuzzleTier::None->value,
        ])->fetchAssociative();

        if ($row === false) {
            return new ImportPairingSummary(0, 0, 0, 0, 0, 0, 0, false);
        }

        return new ImportPairingSummary($row['puzzles'], $row['found'], $row['probably'], $row['choose'], $row['not_found'], $row['left_out'], $row['searching'], $row['paired']);
    }

    /**
     * "Where the rules end" (README "A model as a helper"): how many puzzles each tier holds as the rules left them -
     * a tier never changes with a decision - and how often the player decided otherwise. Numbers only.
     *
     * @return array{puzzles: int, tiers: array{found: int, probably: int, choose: int, none: int, open: int}, remembered: int, player: array{decided: int, foundOverruled: int, noneOfThese: int, pickedAnother: int, foundByHand: int}}
     */
    public function counters(string $importId): array
    {
        /** @var array<string, int> $row */
        $row = $this->database->executeQuery(<<<SQL
SELECT
    COUNT(*) AS puzzles,
    COUNT(*) FILTER (WHERE tier = :found) AS found,
    COUNT(*) FILTER (WHERE tier = :probably) AS probably,
    COUNT(*) FILTER (WHERE tier = :choose) AS choose,
    COUNT(*) FILTER (WHERE tier = :none) AS none,
    COUNT(*) FILTER (WHERE tier IS NULL AND decided_how IS DISTINCT FROM :remembered) AS open,
    COUNT(*) FILTER (WHERE decided_how = :remembered) AS remembered,
    COUNT(*) FILTER (WHERE decided_how = :player) AS decided,
    COUNT(*) FILTER (WHERE decided_how = :player AND tier = :found AND (choice <> :catalogue OR NOT is_candidate)) AS found_overruled,
    COUNT(*) FILTER (WHERE decided_how = :player AND tier IN (:probably, :choose) AND choice IN (:unlisted, :leftOut)) AS none_of_these,
    COUNT(*) FILTER (WHERE decided_how = :player AND tier IN (:probably, :choose) AND choice = :catalogue AND NOT is_candidate) AS picked_another,
    COUNT(*) FILTER (WHERE decided_how = :player AND tier = :none AND choice = :catalogue) AS found_by_hand
FROM (
    SELECT
        data_import_puzzle.*,
        -- A candidate is kept by its id at the time: one merged since is the puzzle it was merged into
        EXISTS (
            SELECT 1
            FROM jsonb_array_elements(data_import_puzzle.candidates) AS candidate
            LEFT JOIN puzzle_redirect ON CAST(puzzle_redirect.old_puzzle_id AS TEXT) = candidate->>'puzzleId'
            WHERE COALESCE(CAST(puzzle_redirect.survivor_puzzle_id AS TEXT), candidate->>'puzzleId') = CAST(data_import_puzzle.puzzle_id AS TEXT)
        ) AS is_candidate
    FROM data_import_puzzle
    WHERE data_import_puzzle.import_id = :importId
) AS import_puzzle
SQL, [
            'importId' => $importId,
            'catalogue' => ImportPuzzleChoice::Catalogue->value,
            'unlisted' => ImportPuzzleChoice::Unlisted->value,
            'leftOut' => ImportPuzzleChoice::LeftOut->value,
            'found' => ImportPuzzleTier::Found->value,
            'probably' => ImportPuzzleTier::Probably->value,
            'choose' => ImportPuzzleTier::Choose->value,
            'none' => ImportPuzzleTier::None->value,
            'player' => ImportPuzzleDecidedHow::Player->value,
            'remembered' => ImportPuzzleDecidedHow::Remembered->value,
        ])->fetchAssociative() ?: [];

        return [
            'puzzles' => $row['puzzles'] ?? 0,
            'tiers' => [
                'found' => $row['found'] ?? 0,
                'probably' => $row['probably'] ?? 0,
                'choose' => $row['choose'] ?? 0,
                'none' => $row['none'] ?? 0,
                'open' => $row['open'] ?? 0,
            ],
            'remembered' => $row['remembered'] ?? 0,
            'player' => [
                'decided' => $row['decided'] ?? 0,
                'foundOverruled' => $row['found_overruled'] ?? 0,
                'noneOfThese' => $row['none_of_these'] ?? 0,
                'pickedAnother' => $row['picked_another'] ?? 0,
                'foundByHand' => $row['found_by_hand'] ?? 0,
            ],
        ];
    }
}
