<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Exceptions\CompetitionRoundNotFound;
use SpeedPuzzling\Web\Results\RoundResultsOverview;
use SpeedPuzzling\Web\Services\CompetitionToolkit\RoundPuzzleFormats;
use SpeedPuzzling\Web\Services\SeatingReadiness;
use SpeedPuzzling\Web\Value\RegistrationStatus;
use SpeedPuzzling\Web\Value\RoundPuzzleReveal;
use SpeedPuzzling\Web\Value\RoundStopwatchStatus;

/**
 * The rounds of an event with their official results progress - entries, table numbers, results, qualified marks
 * (docs/features/competitions-management/official-results.md). One statement for all rounds of the event; the
 * counts follow GetRoundResultEntries (solo round: its people; pair/team round: its teams; only people going to the
 * event - CompetitionParticipantGoing), and say how many people of the round wait on the event's waitlist (left out
 * until the organiser gives them a spot). A `pick` round (competition toolkit A2) comes with its format and puzzles
 * (RoundPuzzleFormats - by label only while hidden, the public rule for every viewer), so every answer and update that
 * carries a round (the round state, Mercure `official_results.round` / `.entries`, the desk's actions) has them.
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
    cr.puzzle_format,
    cr.puzzles_to_solve,
    c.is_online,
    puzzles.puzzles_count,
    puzzles.pieces_count,
    puzzles.stopwatch_reveal_left,
    entries.total,
    entries.with_table_number,
    entries.with_result,
    entries.qualified,
    waitlisted.on_waitlist
FROM competition_round cr
INNER JOIN competition c ON c.id = cr.competition_id
LEFT JOIN LATERAL (
    SELECT
        COUNT(*) AS puzzles_count,
        CASE WHEN COUNT(*) = 1 THEN MAX(puzzle.pieces_count) END AS pieces_count,
        COUNT(*) FILTER (WHERE crp.hide_until_round_starts AND crp.reveal_mode = '%stopwatch%' AND crp.reveal_at IS NULL) AS stopwatch_reveal_left
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
        INNER JOIN competition_participant cp ON cp.id = cpr.participant_id AND %going%
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
LEFT JOIN LATERAL (
    SELECT COUNT(*) AS on_waitlist
    FROM competition_participant_round cpr
    INNER JOIN competition_participant cp ON cp.id = cpr.participant_id AND cp.deleted_at IS NULL
    WHERE cpr.round_id = cr.id
        AND cp.registration_status = '%waitlisted%'
) waitlisted ON true
SQL;

    public function __construct(
        private Connection $database,
        private ClockInterface $clock,
        private RoundPuzzleFormats $roundPuzzleFormats,
    ) {
    }

    /**
     * @return list<RoundResultsOverview> by start, then name
     */
    public function forCompetition(string $competitionId): array
    {
        $rows = $this->database->fetchAllAssociative(
            self::select() . "\nWHERE cr.competition_id = :competitionId\nORDER BY cr.starts_at, cr.name, cr.id",
            ['competitionId' => $competitionId],
        );
        $now = $this->clock->now();

        // `pick` rounds with their format and puzzles (competition toolkit A2) - no statement for an event without one
        return $this->roundPuzzleFormats->attachTo(array_map(fn (array $row): RoundResultsOverview => $this->hydrate($row, $now), $rows));
    }

    /**
     * @throws CompetitionRoundNotFound
     */
    public function forRound(string $roundId): RoundResultsOverview
    {
        $row = $this->database->fetchAssociative(self::select() . "\nWHERE cr.id = :roundId", ['roundId' => $roundId]);

        if ($row === false) {
            throw new CompetitionRoundNotFound();
        }

        return $this->roundPuzzleFormats->attachTo([$this->hydrate($row, $this->clock->now())])[0];
    }

    private static function select(): string
    {
        return strtr(self::SELECT, [
            '%going%' => CompetitionParticipantGoing::sql('cp'),
            '%waitlisted%' => RegistrationStatus::Waitlisted->value,
            '%stopwatch%' => RoundPuzzleReveal::Stopwatch->value,
        ]);
    }

    /**
     * Secret puzzles waiting for a stopwatch start with "Reveal" (no moment yet) once the round's time is over - the
     * stopwatch ran or runs without the tick (the round puzzles page's "use Reveal now" notion), or it was never started
     * and the round's planned start + limit (its start, without a limit) has passed (A-round-formats.md §4.4). A countdown
     * still ahead is not over. Never an automatic reveal - only what the desk tells the organiser.
     */
    private static function stopwatchRevealMissed(
        int $left,
        null|string $stopwatchStatus,
        null|DateTimeImmutable $stopwatchStartedAt,
        DateTimeImmutable $startsAt,
        null|int $minutesLimit,
        DateTimeImmutable $now,
    ): int {
        if ($left === 0) {
            return 0;
        }

        if (RoundStopwatchStatus::hasStarted($stopwatchStatus, $stopwatchStartedAt, $now)) {
            return $left;
        }

        $plannedTimeOver = $stopwatchStatus === null
            && $now->getTimestamp() - $startsAt->getTimestamp() >= ($minutesLimit ?? 0) * 60;

        return $plannedTimeOver ? $left : 0;
    }

    /**
     * The stopwatch's status is the one at $now (RoundStopwatchStatus): a scheduled start whose moment has passed is
     * running, one still ahead is `scheduled` - not started.
     *
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row, DateTimeImmutable $now): RoundResultsOverview
    {
        /** @var array{id: string, name: string, category: string, starts_at: string, minutes_limit: null|int, slug: null|string, stopwatch_status: null|string, stopwatch_started_at: null|string, stopwatch_stopped_at: null|string, results_published_at: null|string, results_first_published_at: null|string, table_numbers_off: bool, puzzle_format: string, puzzles_to_solve: null|int, is_online: bool, puzzles_count: int, pieces_count: null|int, stopwatch_reveal_left: null|int, total: int, with_table_number: int, with_result: int, qualified: int, on_waitlist: int} $row */
        $date = static fn (null|string $value): null|DateTimeImmutable => $value !== null ? new DateTimeImmutable($value) : null;
        $startsAt = new DateTimeImmutable($row['starts_at']);
        $stopwatchStartedAt = $date($row['stopwatch_started_at']);
        $stopwatchStatus = RoundStopwatchStatus::at($row['stopwatch_status'], $stopwatchStartedAt, $now);

        return new RoundResultsOverview(
            roundId: $row['id'],
            name: $row['name'],
            category: $row['category'],
            startsAt: $startsAt,
            minutesLimit: $row['minutes_limit'],
            slug: $row['slug'],
            stopwatchStatus: $stopwatchStatus,
            stopwatchStartedAt: $stopwatchStartedAt,
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
            peopleOnWaitlist: $row['on_waitlist'],
            puzzleFormat: $row['puzzle_format'],
            puzzlesToSolve: $row['puzzles_to_solve'],
            stopwatchRevealMissed: self::stopwatchRevealMissed((int) $row['stopwatch_reveal_left'], $row['stopwatch_status'], $stopwatchStartedAt, $startsAt, $row['minutes_limit'], $now),
            showsTablesReadiness: SeatingReadiness::isShown(
                $row['is_online'],
                $row['table_numbers_off'],
                $row['total'],
                $stopwatchStatus,
                $startsAt,
                $now,
            ),
        );
    }
}
