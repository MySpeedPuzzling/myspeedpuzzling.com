<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Value\RoundPuzzleFormat;
use SpeedPuzzling\Web\Value\RoundPuzzleLabel;

/**
 * What the display editor offers for a results element's view (real events L2b, displays.md "Division and challenge
 * views"), for the organiser only (the editor page):
 * - `divisions`: the event's divisions (shared-puzzles-and-divisions.md D1) in the event's order - none = no Division
 *   field;
 * - `challenges`: the puzzle positions 1..N of the event's solve-all blocks (`pick` rounds of K ≥ 2, world-series-
 *   rules.md W4) with the labels the rounds give them (RoundPuzzleLabel - neutral, never a puzzle name) - none = no
 *   Challenge field.
 *
 * Two statements.
 */
readonly final class GetDisplayEditorResultsOptions
{
    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * @return array{divisions: list<array{id: string, name: string, shortName: null|string}>, challenges: list<array{position: int, labels: list<string>}>}
     */
    public function forCompetition(string $competitionId): array
    {
        /** @var list<array{id: string, name: string, short_name: null|string}> $divisionRows */
        $divisionRows = $this->database->fetchAllAssociative(<<<SQL
SELECT d.id, d.name, d.short_name
FROM competition_division d
WHERE d.competition_id = :competitionId
ORDER BY d.position, d.name, d.id
SQL, ['competitionId' => $competitionId]);

        /** @var list<array{round_id: string, round_puzzle_id: string, position: null|int, label: null|string}> $puzzleRows */
        $puzzleRows = $this->database->fetchAllAssociative(<<<SQL
SELECT crp.round_id, crp.id AS round_puzzle_id, crp.position, crp.label
FROM competition_round_puzzle crp
INNER JOIN competition_round cr ON cr.id = crp.round_id
WHERE cr.competition_id = :competitionId
    AND cr.puzzle_format = :pick
    AND cr.puzzles_to_solve >= 2
ORDER BY cr.starts_at, cr.id
SQL, ['competitionId' => $competitionId, 'pick' => RoundPuzzleFormat::Pick->value]);

        $byRound = [];
        foreach ($puzzleRows as $row) {
            $byRound[$row['round_id']][] = $row;
        }

        /** @var array<int, list<string>> $labels */
        $labels = [];
        foreach ($byRound as $rows) {
            // The round's own order - the slots' positions (RoundPuzzlesVisibleNow)
            $sorted = RoundPuzzleLabel::sort($rows, static fn (array $row): array => [$row['position'], $row['round_puzzle_id']]);

            foreach ($sorted as $index => $row) {
                $label = RoundPuzzleLabel::effective($row['label'], $index + 1);
                $labels[$index + 1] ??= [];

                if (!in_array($label, $labels[$index + 1], true)) {
                    $labels[$index + 1][] = $label;
                }
            }
        }

        ksort($labels);
        $challenges = [];
        foreach ($labels as $position => $positionLabels) {
            $challenges[] = ['position' => $position, 'labels' => $positionLabels];
        }

        return [
            'divisions' => array_map(static fn (array $row): array => [
                'id' => strtolower($row['id']),
                'name' => $row['name'],
                'shortName' => $row['short_name'],
            ], $divisionRows),
            'challenges' => $challenges,
        ];
    }
}
