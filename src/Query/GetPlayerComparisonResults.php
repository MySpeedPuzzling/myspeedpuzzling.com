<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Results\ComparisonResultRow;
use SpeedPuzzling\Web\Value\ComparisonMode;
use SpeedPuzzling\Web\Value\ComparisonSubject;

readonly final class GetPlayerComparisonResults
{
    public function __construct(
        private Connection $database,
        private ClockInterface $clock,
    ) {
    }

    /**
     * Fetch the fastest time and the first-try time (each with date) per puzzle
     * for a single comparison subject in the given mode.
     *
     * For solo mode it matches the player directly; for pairs/teams it matches
     * solving times where the base player AND every required co-solver appear
     * together in the team JSON (uses the custom_pst_team_puzzlers_gin index).
     *
     * @return array<string, ComparisonResultRow> keyed by puzzle id
     */
    public function forSubject(ComparisonSubject $subject, ComparisonMode $mode): array
    {
        if (Uuid::isValid($subject->playerId) === false) {
            return [];
        }

        $params = ['now' => $this->clock->now()->format('Y-m-d H:i:s')];
        $params['puzzlingType'] = $mode->puzzlingType()->value;

        if ($mode === ComparisonMode::Solo) {
            $membershipPredicate = 'pst.player_id = :playerId AND pst.puzzling_type = :puzzlingType';
            $params['playerId'] = $subject->playerId;
        } else {
            $playerIds = array_merge([$subject->playerId], $subject->coSolverIds);
            $objects = [];

            foreach ($playerIds as $i => $playerId) {
                if (Uuid::isValid($playerId) === false) {
                    return [];
                }

                $objects[] = "jsonb_build_object('player_id', CAST(:p{$i} AS UUID))";
                $params["p{$i}"] = $playerId;
            }

            $containment = "(pst.team::jsonb -> 'puzzlers') @> jsonb_build_array(" . implode(', ', $objects) . ')';
            $membershipPredicate = "pst.puzzling_type = :puzzlingType AND {$containment}";
        }

        $query = <<<SQL
SELECT
    pst.id AS time_id,
    pst.seconds_to_solve AS time,
    pst.first_attempt,
    COALESCE(pst.finished_at, pst.tracked_at) AS solved_date,
    puzzle.id AS puzzle_id,
    puzzle.name AS puzzle_name,
    puzzle.alternative_name AS puzzle_alternative_name,
    puzzle.pieces_count,
    CASE WHEN puzzle.hide_image_until IS NOT NULL AND puzzle.hide_image_until > :now::timestamp THEN NULL ELSE puzzle.image END AS puzzle_image,
    manufacturer.id AS manufacturer_id,
    manufacturer.name AS manufacturer_name
FROM puzzle_solving_time pst
INNER JOIN puzzle ON puzzle.id = pst.puzzle_id
INNER JOIN manufacturer ON manufacturer.id = puzzle.manufacturer_id
WHERE
    pst.suspicious = false
    AND pst.seconds_to_solve IS NOT NULL
    AND {$membershipPredicate}
SQL;

        $rows = $this->database->executeQuery($query, $params)->fetchAllAssociative();

        /** @var array<string, list<array{time_id: string, time: int|string, first_attempt: bool, solved_date: string, puzzle_id: string, puzzle_name: string, puzzle_alternative_name: null|string, pieces_count: int|string, puzzle_image: null|string, manufacturer_id: string, manufacturer_name: string}>> $grouped */
        $grouped = [];

        foreach ($rows as $row) {
            /** @var array{time_id: string, time: int|string, first_attempt: bool, solved_date: string, puzzle_id: string, puzzle_name: string, puzzle_alternative_name: null|string, pieces_count: int|string, puzzle_image: null|string, manufacturer_id: string, manufacturer_name: string} $row */
            $grouped[$row['puzzle_id']][] = $row;
        }

        $results = [];

        foreach ($grouped as $puzzleId => $puzzleRows) {
            $fastest = null;
            $firstTry = null;

            foreach ($puzzleRows as $row) {
                if ($fastest === null || (int) $row['time'] < (int) $fastest['time']) {
                    $fastest = $row;
                }

                if ($row['first_attempt'] === true) {
                    if ($firstTry === null || $row['solved_date'] < $firstTry['solved_date']) {
                        $firstTry = $row;
                    }
                }
            }

            if ($fastest === null) {
                continue;
            }

            $results[$puzzleId] = new ComparisonResultRow(
                puzzleId: $puzzleId,
                puzzleName: $fastest['puzzle_name'],
                puzzleAlternativeName: $fastest['puzzle_alternative_name'],
                manufacturerId: $fastest['manufacturer_id'],
                manufacturerName: $fastest['manufacturer_name'],
                piecesCount: (int) $fastest['pieces_count'],
                puzzleImage: $fastest['puzzle_image'],
                fastestTimeId: $fastest['time_id'],
                fastestTime: (int) $fastest['time'],
                fastestDate: new DateTimeImmutable($fastest['solved_date']),
                firstTryTimeId: $firstTry['time_id'] ?? null,
                firstTryTime: $firstTry !== null ? (int) $firstTry['time'] : null,
                firstTryDate: $firstTry !== null ? new DateTimeImmutable($firstTry['solved_date']) : null,
            );
        }

        return $results;
    }
}
