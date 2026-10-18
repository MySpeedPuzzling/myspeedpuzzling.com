<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use DateTimeImmutable;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Results\Insights\InsightsPuzzleRef;
use SpeedPuzzling\Web\Results\Insights\PuzzleRatingTimeLists;
use SpeedPuzzling\Web\Results\Insights\RatingEntrySnapshot;
use SpeedPuzzling\Web\Results\Insights\RatingSolvedPuzzle;
use SpeedPuzzling\Web\Services\HiddenPlayers;
use SpeedPuzzling\Web\Value\RatingPortfolio;

/**
 * What the rating what-ifs read (docs/features/insights/computation.md §3, §4, §4c.2): the player's solved puzzles of a
 * rated piece count with their stored rating entries, the sorted time lists of the puzzles a what-if needs, and the
 * ladder's ratings for "which place would that be".
 */
readonly final class GetInsightsRatingInputs
{
    public function __construct(
        private Connection $database,
        private ClockInterface $clock,
        private HiddenPlayers $hiddenPlayers,
    ) {
    }

    /**
     * Every puzzle of the piece count the player has solo results on, all time, aggregated the way the rating takes
     * them (MspRatingCalculator: solo, not suspicious, timed - unboxed included), with the stored entries. One
     * statement; `private` is the player's own setting (a private player has no MSP rating at all).
     *
     * @return array{private: bool, puzzles: list<RatingSolvedPuzzle>}
     */
    public function solvedPuzzles(string $playerId, int $piecesCount): array
    {
        $puzzleColumns = InsightsPuzzleRef::sqlColumns();
        $onShelf = GetInsightsShelf::sqlOnShelf('s.puzzle_id');

        $query = <<<SQL
WITH solves AS (
    SELECT
        pst.puzzle_id,
        MIN(CASE WHEN pst.first_attempt = true THEN pst.seconds_to_solve END) AS first_attempt_seconds,
        MIN(CASE WHEN pst.first_attempt = true THEN COALESCE(pst.finished_at, pst.tracked_at) END) AS first_attempt_at,
        MIN(pst.seconds_to_solve) AS best_seconds,
        MAX(COALESCE(pst.finished_at, pst.tracked_at)) AS latest_at,
        COUNT(*) AS attempts
    FROM puzzle_solving_time pst
    WHERE pst.player_id = :playerId
        AND pst.puzzling_type = 'solo'
        AND pst.suspicious = false
        AND pst.seconds_to_solve IS NOT NULL
    GROUP BY pst.puzzle_id
)
SELECT
    (SELECT owner.is_private FROM player owner WHERE owner.id = :playerId) AS player_private,
    s.first_attempt_seconds,
    s.first_attempt_at,
    s.best_seconds,
    s.latest_at,
    s.attempts,
    {$onShelf} AS on_shelf,
    prt.qualifies,
    prt.solver_count,
    prt.difficulty_weight,
    fa.seconds AS fa_seconds,
    fa.solved_at AS fa_solved_at,
    fa.percentile AS fa_percentile,
    fa.difficulty_weight AS fa_weight,
    fa.points AS fa_points,
    fa.in_top AS fa_in_top,
    bt.seconds AS bt_seconds,
    bt.solved_at AS bt_solved_at,
    bt.percentile AS bt_percentile,
    bt.difficulty_weight AS bt_weight,
    bt.points AS bt_points,
    bt.in_top AS bt_in_top,
    {$puzzleColumns}
FROM solves s
JOIN puzzle p ON p.id = s.puzzle_id AND p.pieces_count = :piecesCount
LEFT JOIN manufacturer m ON m.id = p.manufacturer_id
LEFT JOIN puzzle_rating_times prt ON prt.puzzle_id = s.puzzle_id
LEFT JOIN player_rating_entry fa ON fa.player_id = :playerId AND fa.pieces_count = :piecesCount AND fa.puzzle_id = s.puzzle_id AND fa.portfolio = :firstAttempt
LEFT JOIN player_rating_entry bt ON bt.player_id = :playerId AND bt.pieces_count = :piecesCount AND bt.puzzle_id = s.puzzle_id AND bt.portfolio = :bestTime
ORDER BY s.puzzle_id
SQL;

        /** @var list<array{player_private: null|bool|int|string, first_attempt_seconds: null|int|string, first_attempt_at: null|string, best_seconds: int|string, latest_at: string, attempts: int|string, on_shelf: bool|int|string, qualifies: null|bool|int|string, solver_count: null|int|string, difficulty_weight: null|float|string, fa_seconds: null|int|string, fa_solved_at: null|string, fa_percentile: null|float|string, fa_weight: null|float|string, fa_points: null|float|string, fa_in_top: null|bool|int|string, bt_seconds: null|int|string, bt_solved_at: null|string, bt_percentile: null|float|string, bt_weight: null|float|string, bt_points: null|float|string, bt_in_top: null|bool|int|string, puzzle_id: string, puzzle_name: string, puzzle_image: null|string, brand_name: null|string, pieces_count: int|string, puzzle_secret: bool|int|string}> $rows */
        $rows = $this->database->fetchAllAssociative($query, [
            'playerId' => $playerId,
            'piecesCount' => $piecesCount,
            'firstAttempt' => RatingPortfolio::FirstAttempt->value,
            'bestTime' => RatingPortfolio::BestTime->value,
            'now' => $this->clock->now()->format('Y-m-d H:i:s'),
        ]);

        $private = $rows !== [] && self::bool($rows[0]['player_private']);
        $puzzles = [];

        foreach ($rows as $row) {
            $puzzles[] = new RatingSolvedPuzzle(
                puzzleId: $row['puzzle_id'],
                puzzle: InsightsPuzzleRef::fromRow($row),
                firstAttemptSeconds: $row['first_attempt_seconds'] !== null ? (int) $row['first_attempt_seconds'] : null,
                firstAttemptAt: $row['first_attempt_at'] !== null ? new DateTimeImmutable($row['first_attempt_at']) : null,
                bestSeconds: (int) $row['best_seconds'],
                latestAt: new DateTimeImmutable($row['latest_at']),
                attempts: (int) $row['attempts'],
                onShelf: self::bool($row['on_shelf']),
                qualifies: $row['qualifies'] !== null ? self::bool($row['qualifies']) : null,
                solverCount: $row['solver_count'] !== null ? (int) $row['solver_count'] : null,
                difficultyWeight: $row['difficulty_weight'] !== null ? (float) $row['difficulty_weight'] : null,
                firstAttemptEntry: self::entry($row['fa_seconds'], $row['fa_solved_at'], $row['fa_percentile'], $row['fa_weight'], $row['fa_points'], $row['fa_in_top']),
                bestTimeEntry: self::entry($row['bt_seconds'], $row['bt_solved_at'], $row['bt_percentile'], $row['bt_weight'], $row['bt_points'], $row['bt_in_top']),
            );
        }

        return ['private' => $private, 'puzzles' => $puzzles];
    }

    /**
     * The time lists of the puzzles; the first-attempt list only of $withFirstAttempts (null = of every one; a re-solve
     * needs the best times alone - half of what a heavy player's what-ifs would read and decode otherwise), else an
     * empty list; the best times only when $withBestTimes.
     *
     * @param list<string> $puzzleIds
     * @param null|list<string> $withFirstAttempts
     *
     * @return array<string, PuzzleRatingTimeLists>
     */
    public function ratingTimes(array $puzzleIds, null|array $withFirstAttempts = null, bool $withBestTimes = true): array
    {
        if ($puzzleIds === []) {
            return [];
        }

        /** @var list<array{puzzle_id: string, qualifies: bool|int|string, solver_count: int|string, difficulty_weight: null|float|string, first_attempt_times: null|string, best_times: null|string}> $rows */
        $rows = $this->database->fetchAllAssociative(
            'SELECT puzzle_id, qualifies, solver_count, difficulty_weight,
                CASE WHEN CAST(:allFirstAttempts AS boolean) OR puzzle_id IN (:withFirstAttempts) THEN first_attempt_times END AS first_attempt_times,
                CASE WHEN CAST(:withBestTimes AS boolean) THEN best_times END AS best_times
            FROM puzzle_rating_times
            WHERE puzzle_id IN (:puzzleIds)',
            [
                'puzzleIds' => array_values(array_unique($puzzleIds)),
                'allFirstAttempts' => $withFirstAttempts === null ? 'true' : 'false',
                'withBestTimes' => $withBestTimes ? 'true' : 'false',
                // An empty IN () is invalid SQL: a uuid nobody has stands in
                'withFirstAttempts' => $withFirstAttempts === null || $withFirstAttempts === [] ? ['00000000-0000-0000-0000-000000000000'] : array_values(array_unique($withFirstAttempts)),
            ],
            ['puzzleIds' => ArrayParameterType::STRING, 'withFirstAttempts' => ArrayParameterType::STRING],
        );

        $lists = [];

        foreach ($rows as $row) {
            /** @var list<int> $firstAttemptTimes */
            $firstAttemptTimes = $row['first_attempt_times'] !== null ? json_decode($row['first_attempt_times'], true, flags: JSON_THROW_ON_ERROR) : [];
            /** @var list<int> $bestTimes */
            $bestTimes = $row['best_times'] !== null ? json_decode($row['best_times'], true, flags: JSON_THROW_ON_ERROR) : [];

            $lists[$row['puzzle_id']] = new PuzzleRatingTimeLists(
                puzzleId: $row['puzzle_id'],
                qualifies: self::bool($row['qualifies']),
                solverCount: (int) $row['solver_count'],
                difficultyWeight: $row['difficulty_weight'] !== null ? (float) $row['difficulty_weight'] : null,
                firstAttemptTimes: $firstAttemptTimes,
                bestTimes: $bestTimes,
            );
        }

        return $lists;
    }

    /**
     * The ratings of everybody else on the ladder at the piece count, best first - the pool of
     * GetPlayerRatingRanking (public, not opted out of rankings, players hidden from the viewer closed up), so
     * 1 + (ratings above r) is the place a rating r would have.
     *
     * @return list<float>
     */
    public function ladderRatings(int $piecesCount, string $playerId): array
    {
        $hidden = $this->hiddenPlayers->sqlExclude('lp.id');

        /** @var list<float|string> $ratings */
        $ratings = $this->database->fetchFirstColumn(
            "SELECT pe.elo_rating
            FROM player_elo pe
            JOIN player lp ON lp.id = pe.player_id
            WHERE pe.pieces_count = :piecesCount
                AND pe.player_id <> :playerId
                AND lp.is_private = false
                AND lp.ranking_opted_out = false{$hidden}
            ORDER BY pe.elo_rating DESC",
            ['piecesCount' => $piecesCount, 'playerId' => $playerId],
        );

        return array_map(floatval(...), $ratings);
    }

    private static function entry(
        null|int|string $seconds,
        null|string $solvedAt,
        null|float|string $percentile,
        null|float|string $weight,
        null|float|string $points,
        null|bool|int|string $inTop,
    ): null|RatingEntrySnapshot {
        if ($seconds === null || $solvedAt === null || $percentile === null || $weight === null || $points === null) {
            return null;
        }

        return new RatingEntrySnapshot(
            seconds: (int) $seconds,
            solvedAt: new DateTimeImmutable($solvedAt),
            percentile: (float) $percentile,
            difficultyWeight: (float) $weight,
            points: (float) $points,
            inTop: self::bool($inTop),
        );
    }

    private static function bool(null|bool|float|int|string $value): bool
    {
        return $value === true || $value === 1 || $value === '1' || $value === 't';
    }
}
