<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use DateTimeImmutable;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use SpeedPuzzling\Web\Results\VisibleRoundPuzzle;
use SpeedPuzzling\Web\Value\DatePrecision;

/**
 * The studio members' solo history on puzzles like the round's (analytics-studio.md §2.7, §3.3, R1, R5) - public raw
 * times only (no Insights data): summary() per member and visible puzzle (same piece count; same piece count and
 * brand), and for the entrant detail recentSimilar() (the latest solves at a visible puzzle's piece count) and
 * recentForm() (the latest solo times at each visible piece count).
 *
 * Every statement: solo, not suspicious, with a time (an unfinished result has none), before the round's history
 * cut-off (a month/year date only when that whole period ended before it - GetAnalyticsStudioEntrantHistory::sqlBefore(),
 * finished_at_precision), never this round's own logged times, never a puzzle under the site-wide embargo
 * (`hide_until` after now). The round's own puzzles are taken out of the similar lists by the VISIBLE ids only - the
 * hidden ones stay like any other puzzle, so nothing is told by subtraction (R1). Analysable (public) members only.
 *
 * @phpstan-type Summary array{sizeCount: int, sizeMedian: null|int, sizeBest: null|int, brandCount: int, brandMedian: null|int, brandBest: null|int}
 */
readonly final class GetAnalyticsStudioSimilarHistory
{
    public const int RECENT_SIMILAR = 8;
    public const int RECENT_FORM = 10;

    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * @param list<string> $memberIds analysable players only
     * @param array<string, VisibleRoundPuzzle> $visible slot key => visible puzzle (AnalyticsStudioVisibility::visibleBySlot())
     * @return array<string, array<string, Summary>> member id => slot key => summary
     */
    public function summary(array $memberIds, array $visible, DateTimeImmutable $cutOff, string $roundId, DateTimeImmutable $now): array
    {
        if ($memberIds === [] || $visible === []) {
            return [];
        }

        $columns = [];
        $parameters = [];
        $types = [];
        $index = 0;
        $slotByIndex = [];

        foreach ($visible as $slotKey => $puzzle) {
            $slotByIndex[$index] = $slotKey;
            $size = "p.pieces_count = :pc{$index}";
            $brand = "p.pieces_count = :pc{$index} AND p.manufacturer_id = (SELECT own.manufacturer_id FROM puzzle own WHERE own.id = :puzzle{$index})";
            $columns[] = <<<SQL
    COUNT(*) FILTER (WHERE {$size}) AS size_count_{$index},
    MIN(pst.seconds_to_solve) FILTER (WHERE {$size}) AS size_best_{$index},
    percentile_cont(0.5) WITHIN GROUP (ORDER BY pst.seconds_to_solve) FILTER (WHERE {$size}) AS size_median_{$index},
    COUNT(*) FILTER (WHERE {$brand}) AS brand_count_{$index},
    MIN(pst.seconds_to_solve) FILTER (WHERE {$brand}) AS brand_best_{$index},
    percentile_cont(0.5) WITHIN GROUP (ORDER BY pst.seconds_to_solve) FILTER (WHERE {$brand}) AS brand_median_{$index}
SQL;
            $parameters["pc{$index}"] = $puzzle->piecesCount;
            $types["pc{$index}"] = ParameterType::INTEGER;
            $parameters["puzzle{$index}"] = $puzzle->puzzleId;
            $index++;
        }

        $selected = implode(",\n", $columns);
        $before = GetAnalyticsStudioEntrantHistory::sqlBefore('pst');
        $piecesCounts = array_values(array_unique(array_map(static fn (VisibleRoundPuzzle $puzzle): int => $puzzle->piecesCount, $visible)));
        $visiblePuzzleIds = array_values(array_unique(array_map(static fn (VisibleRoundPuzzle $puzzle): string => $puzzle->puzzleId, $visible)));

        $rows = $this->database->executeQuery(
            <<<SQL
SELECT
    pst.player_id,
{$selected}
FROM puzzle_solving_time pst
INNER JOIN puzzle p ON p.id = pst.puzzle_id
WHERE pst.player_id IN (:memberIds)
    AND pst.player_id IS NOT NULL
    AND pst.puzzling_type = 'solo'
    AND pst.suspicious = false
    AND pst.seconds_to_solve IS NOT NULL
    AND p.pieces_count IN (:piecesCounts)
    AND pst.puzzle_id NOT IN (:visiblePuzzleIds)
    AND (p.hide_until IS NULL OR p.hide_until <= CAST(:now AS timestamp))
    AND {$before}
    AND pst.competition_round_id IS DISTINCT FROM CAST(:roundId AS uuid)
GROUP BY pst.player_id
SQL,
            [
                ...$parameters,
                'memberIds' => $memberIds,
                'piecesCounts' => $piecesCounts,
                'visiblePuzzleIds' => $visiblePuzzleIds,
                'now' => $now->format('Y-m-d H:i:s'),
                'cutOff' => $cutOff->format('Y-m-d H:i:s'),
                'roundId' => $roundId,
            ],
            [
                ...$types,
                'memberIds' => ArrayParameterType::STRING,
                'piecesCounts' => ArrayParameterType::INTEGER,
                'visiblePuzzleIds' => ArrayParameterType::STRING,
            ],
        )->fetchAllAssociative();

        $result = [];

        foreach ($rows as $row) {
            /** @var array<string, mixed>&array{player_id: string} $row */
            $playerId = strtolower($row['player_id']);

            foreach ($slotByIndex as $i => $slotKey) {
                $result[$playerId][$slotKey] = [
                    'sizeCount' => self::int($row["size_count_{$i}"]) ?? 0,
                    'sizeMedian' => self::int($row["size_median_{$i}"]),
                    'sizeBest' => self::int($row["size_best_{$i}"]),
                    'brandCount' => self::int($row["brand_count_{$i}"]) ?? 0,
                    'brandMedian' => self::int($row["brand_median_{$i}"]),
                    'brandBest' => self::int($row["brand_best_{$i}"]),
                ];
            }
        }

        return $result;
    }

    /**
     * The latest solo solves of each member at each visible puzzle's piece count (other puzzles than the visible round
     * puzzles) - newest first, RECENT_SIMILAR per member and slot.
     *
     * @param list<string> $memberIds analysable players only
     * @param array<string, VisibleRoundPuzzle> $visible slot key => visible puzzle
     * @return array<string, array<string, list<array{puzzleName: string, brand: null|string, pieces: int, seconds: int, solvedAt: DateTimeImmutable, precision: DatePrecision}>>> member id => slot key => solves
     */
    public function recentSimilar(array $memberIds, array $visible, DateTimeImmutable $cutOff, string $roundId, DateTimeImmutable $now): array
    {
        if ($memberIds === [] || $visible === []) {
            return [];
        }

        [$values, $parameters, $types] = self::targets($memberIds, $visible);
        $before = GetAnalyticsStudioEntrantHistory::sqlBefore('pst');
        $visiblePuzzleIds = array_values(array_unique(array_map(static fn (VisibleRoundPuzzle $puzzle): string => $puzzle->puzzleId, $visible)));

        $rows = $this->database->executeQuery(
            <<<SQL
SELECT target.member_id, target.slot_key, recent.*
FROM (VALUES {$values}) AS target(member_id, slot_key, pieces_count)
CROSS JOIN LATERAL (
    SELECT
        p.name AS puzzle_name,
        m.name AS brand,
        p.pieces_count AS pieces,
        pst.seconds_to_solve AS seconds,
        COALESCE(pst.finished_at, pst.tracked_at) AS solved_at,
        pst.finished_at_precision
    FROM puzzle_solving_time pst
    INNER JOIN puzzle p ON p.id = pst.puzzle_id
    LEFT JOIN manufacturer m ON m.id = p.manufacturer_id
    WHERE pst.player_id = CAST(target.member_id AS uuid)
        AND pst.player_id IS NOT NULL
        AND pst.puzzling_type = 'solo'
        AND pst.suspicious = false
        AND pst.seconds_to_solve IS NOT NULL
        AND p.pieces_count = target.pieces_count
        AND pst.puzzle_id NOT IN (:visiblePuzzleIds)
        AND (p.hide_until IS NULL OR p.hide_until <= CAST(:now AS timestamp))
        AND {$before}
        AND pst.competition_round_id IS DISTINCT FROM CAST(:roundId AS uuid)
    ORDER BY COALESCE(pst.finished_at, pst.tracked_at) DESC, pst.id DESC
    LIMIT :limit
) AS recent
SQL,
            [
                ...$parameters,
                'visiblePuzzleIds' => $visiblePuzzleIds,
                'now' => $now->format('Y-m-d H:i:s'),
                'cutOff' => $cutOff->format('Y-m-d H:i:s'),
                'roundId' => $roundId,
                'limit' => self::RECENT_SIMILAR,
            ],
            [
                ...$types,
                'visiblePuzzleIds' => ArrayParameterType::STRING,
                'limit' => ParameterType::INTEGER,
            ],
        )->fetchAllAssociative();

        $result = [];

        foreach ($rows as $row) {
            /** @var array{member_id: string, slot_key: string, puzzle_name: string, brand: null|string, pieces: int|string, seconds: int|string, solved_at: string, finished_at_precision: null|string} $row */
            $result[$row['member_id']][$row['slot_key']][] = [
                'puzzleName' => $row['puzzle_name'],
                'brand' => $row['brand'],
                'pieces' => (int) $row['pieces'],
                'seconds' => (int) $row['seconds'],
                'solvedAt' => new DateTimeImmutable($row['solved_at']),
                'precision' => DatePrecision::ofStored($row['finished_at_precision']),
            ];
        }

        return $result;
    }

    /**
     * The latest solo times of each member at each visible piece count (any puzzle not under the embargo, the visible
     * round puzzles included) - newest first, RECENT_FORM per member and piece count.
     *
     * @param list<string> $memberIds analysable players only
     * @param list<int> $piecesCounts the visible puzzles' piece counts
     * @return array<string, array<int, list<array{seconds: int, solvedAt: DateTimeImmutable, precision: DatePrecision}>>> member id => piece count => times
     */
    public function recentForm(array $memberIds, array $piecesCounts, DateTimeImmutable $cutOff, string $roundId, DateTimeImmutable $now): array
    {
        if ($memberIds === [] || $piecesCounts === []) {
            return [];
        }

        $before = GetAnalyticsStudioEntrantHistory::sqlBefore('pst');

        $rows = $this->database->executeQuery(
            <<<SQL
SELECT target_member.member_id, target_pieces.pieces_count, recent.*
FROM unnest(CAST(:memberIds AS uuid[])) AS target_member(member_id)
CROSS JOIN unnest(CAST(:piecesCounts AS int[])) AS target_pieces(pieces_count)
CROSS JOIN LATERAL (
    SELECT
        pst.seconds_to_solve AS seconds,
        COALESCE(pst.finished_at, pst.tracked_at) AS solved_at,
        pst.finished_at_precision
    FROM puzzle_solving_time pst
    INNER JOIN puzzle p ON p.id = pst.puzzle_id
    WHERE pst.player_id = target_member.member_id
        AND pst.player_id IS NOT NULL
        AND pst.puzzling_type = 'solo'
        AND pst.suspicious = false
        AND pst.seconds_to_solve IS NOT NULL
        AND p.pieces_count = target_pieces.pieces_count
        AND (p.hide_until IS NULL OR p.hide_until <= CAST(:now AS timestamp))
        AND {$before}
        AND pst.competition_round_id IS DISTINCT FROM CAST(:roundId AS uuid)
    ORDER BY COALESCE(pst.finished_at, pst.tracked_at) DESC, pst.id DESC
    LIMIT :limit
) AS recent
SQL,
            [
                'memberIds' => '{' . implode(',', $memberIds) . '}',
                'piecesCounts' => '{' . implode(',', array_map('intval', $piecesCounts)) . '}',
                'now' => $now->format('Y-m-d H:i:s'),
                'cutOff' => $cutOff->format('Y-m-d H:i:s'),
                'roundId' => $roundId,
                'limit' => self::RECENT_FORM,
            ],
            ['limit' => ParameterType::INTEGER],
        )->fetchAllAssociative();

        $result = [];

        foreach ($rows as $row) {
            /** @var array{member_id: string, pieces_count: int|string, seconds: int|string, solved_at: string, finished_at_precision: null|string} $row */
            $result[strtolower($row['member_id'])][(int) $row['pieces_count']][] = [
                'seconds' => (int) $row['seconds'],
                'solvedAt' => new DateTimeImmutable($row['solved_at']),
                'precision' => DatePrecision::ofStored($row['finished_at_precision']),
            ];
        }

        return $result;
    }

    /**
     * VALUES rows (member, slot, piece count) as bound parameters.
     *
     * @param list<string> $memberIds
     * @param array<string, VisibleRoundPuzzle> $visible
     * @return array{0: string, 1: array<string, int|string>, 2: array<string, ParameterType>}
     */
    private static function targets(array $memberIds, array $visible): array
    {
        $values = [];
        $parameters = [];
        $types = [];
        $index = 0;

        foreach ($memberIds as $memberId) {
            foreach ($visible as $slotKey => $puzzle) {
                $values[] = "(CAST(:m{$index} AS text), CAST(:s{$index} AS text), CAST(:p{$index} AS int))";
                $parameters["m{$index}"] = $memberId;
                $parameters["s{$index}"] = $slotKey;
                $parameters["p{$index}"] = $puzzle->piecesCount;
                $types["p{$index}"] = ParameterType::INTEGER;
                $index++;
            }
        }

        return [implode(', ', $values), $parameters, $types];
    }

    private static function int(mixed $value): null|int
    {
        if ($value === null) {
            return null;
        }

        return (int) round((float) (is_scalar($value) ? $value : 0));
    }
}
