<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Exceptions\CompetitionRoundNotFound;
use SpeedPuzzling\Web\Results\RoundResultsOverview;
use SpeedPuzzling\Web\Services\SeatingReadiness;

/**
 * The rounds of an event with their official results progress - entries, table numbers, results, qualified marks
 * (docs/features/competitions-management/official-results.md). One statement for all rounds of the event; the
 * counts follow GetRoundResultEntries (solo round: its people; pair/team round: its teams; removed people left out).
 */
readonly final class GetRoundResultsOverview
{
    private const string SELECT = <<<SQL
SELECT
    cr.id,
    cr.name,
    cr.category,
    cr.starts_at,
    cr.minutes_limit,
    cr.slug,
    cr.stopwatch_status,
    cr.stopwatch_started_at,
    cr.stopwatch_stopped_at,
    cr.results_published_at,
    cr.results_first_published_at,
    cr.table_numbers_off,
    c.is_online,
    puzzles.puzzles_count,
    puzzles.pieces_count,
    entries.total,
    entries.with_table_number,
    entries.with_result,
    entries.qualified
FROM competition_round cr
INNER JOIN competition c ON c.id = cr.competition_id
LEFT JOIN LATERAL (
    SELECT
        COUNT(*) AS puzzles_count,
        CASE WHEN COUNT(*) = 1 THEN MAX(puzzle.pieces_count) END AS pieces_count
    FROM competition_round_puzzle crp
    INNER JOIN puzzle ON puzzle.id = crp.puzzle_id
    WHERE crp.round_id = cr.id
) puzzles ON true
LEFT JOIN LATERAL (
    SELECT
        COUNT(*) AS total,
        COUNT(entry.table_number) AS with_table_number,
        COUNT(*) FILTER (WHERE entry.has_result) AS with_result,
        COUNT(entry.qualified_at) AS qualified
    FROM (
        SELECT
            cpr.table_number,
            cpr.qualified_at,
            (cpr.result_seconds IS NOT NULL OR cpr.result_pieces_placed IS NOT NULL OR cpr.result_did_not_start) AS has_result
        FROM competition_participant_round cpr
        INNER JOIN competition_participant cp ON cp.id = cpr.participant_id AND cp.deleted_at IS NULL
        WHERE cpr.round_id = cr.id
            AND cr.category = 'solo'
        UNION ALL
        SELECT
            ct.table_number,
            ct.qualified_at,
            (ct.result_seconds IS NOT NULL OR ct.result_pieces_placed IS NOT NULL OR ct.result_did_not_start) AS has_result
        FROM competition_team ct
        WHERE ct.round_id = cr.id
            AND cr.category <> 'solo'
    ) entry
) entries ON true
SQL;

    public function __construct(
        private Connection $database,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @return list<RoundResultsOverview> by start, then name
     */
    public function forCompetition(string $competitionId): array
    {
        $rows = $this->database->fetchAllAssociative(
            self::SELECT . "\nWHERE cr.competition_id = :competitionId\nORDER BY cr.starts_at, cr.name, cr.id",
            ['competitionId' => $competitionId],
        );

        return array_map($this->hydrate(...), $rows);
    }

    /**
     * @throws CompetitionRoundNotFound
     */
    public function forRound(string $roundId): RoundResultsOverview
    {
        $row = $this->database->fetchAssociative(self::SELECT . "\nWHERE cr.id = :roundId", ['roundId' => $roundId]);

        if ($row === false) {
            throw new CompetitionRoundNotFound();
        }

        return $this->hydrate($row);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): RoundResultsOverview
    {
        /** @var array{id: string, name: string, category: string, starts_at: string, minutes_limit: int, slug: null|string, stopwatch_status: null|string, stopwatch_started_at: null|string, stopwatch_stopped_at: null|string, results_published_at: null|string, results_first_published_at: null|string, table_numbers_off: bool, is_online: bool, puzzles_count: int, pieces_count: null|int, total: int, with_table_number: int, with_result: int, qualified: int} $row */
        $date = static fn (null|string $value): null|DateTimeImmutable => $value !== null ? new DateTimeImmutable($value) : null;
        $startsAt = new DateTimeImmutable($row['starts_at']);

        return new RoundResultsOverview(
            roundId: $row['id'],
            name: $row['name'],
            category: $row['category'],
            startsAt: $startsAt,
            minutesLimit: $row['minutes_limit'],
            slug: $row['slug'],
            stopwatchStatus: $row['stopwatch_status'],
            stopwatchStartedAt: $date($row['stopwatch_started_at']),
            stopwatchStoppedAt: $date($row['stopwatch_stopped_at']),
            resultsPublishedAt: $date($row['results_published_at']),
            resultsFirstPublishedAt: $date($row['results_first_published_at']),
            tableNumbersOff: $row['table_numbers_off'],
            puzzlesCount: $row['puzzles_count'],
            piecesCount: $row['pieces_count'],
            entriesTotal: $row['total'],
            entriesWithTableNumber: $row['with_table_number'],
            entriesWithResult: $row['with_result'],
            entriesQualified: $row['qualified'],
            competitionIsOnline: $row['is_online'],
            showsTablesReadiness: SeatingReadiness::isShown(
                $row['is_online'],
                $row['table_numbers_off'],
                $row['total'],
                $row['stopwatch_status'],
                $startsAt,
                $this->clock->now(),
            ),
        );
    }
}
