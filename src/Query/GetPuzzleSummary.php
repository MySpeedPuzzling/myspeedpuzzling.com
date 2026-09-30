<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use DateInterval;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Exceptions\PuzzleNotFound;
use SpeedPuzzling\Web\Results\CompetitionReference;
use SpeedPuzzling\Web\Results\PuzzleSummary;

/**
 * The public facts of a puzzle page's "About this puzzle" section and meta description, in one query: the precomputed
 * puzzle_statistics row (a primary key lookup) plus the competitions the puzzle was used at.
 *
 * "Used at" is the union of the competitions (or whole series) the puzzle's tags belong to and the competitions
 * whose rounds it is in, publicly visible ones only. A round puzzle hidden until its round starts stays out until
 * GetEditionRounds reveals it too (10 minutes after the start), as does a puzzle under a platform-wide embargo -
 * otherwise this paragraph would leak what the event page keeps secret.
 */
readonly final class GetPuzzleSummary
{
    private const string ROUND_REVEAL_BUFFER = 'PT10M';

    public function __construct(
        private Connection $database,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws PuzzleNotFound
     */
    public function forPuzzle(string $puzzleId): PuzzleSummary
    {
        if (Uuid::isValid($puzzleId) === false) {
            throw new PuzzleNotFound();
        }

        $visibleCompetition = IsCompetitionPubliclyVisible::SQL_CONDITION;

        $query = <<<SQL
SELECT
    COALESCE(ps.solved_times_solo_count, 0) AS solo_count,
    ps.median_time_solo,
    ps.fastest_time_solo,
    COALESCE(ps.solved_times_duo_count, 0) AS duo_count,
    ps.median_time_duo,
    ps.fastest_time_duo,
    COALESCE(ps.solved_times_team_count, 0) AS team_count,
    ps.median_time_team,
    ps.fastest_time_team,
    (
        SELECT COALESCE(json_agg(used ORDER BY used.is_series, used.date_from NULLS LAST, used.name), '[]'::json)
        FROM (
            SELECT
                c.name,
                c.slug,
                cs.name AS series_name,
                cs.slug AS series_slug,
                false AS is_series,
                c.date_from
            FROM competition c
            LEFT JOIN competition_series cs ON cs.id = c.series_id
            WHERE {$visibleCompetition}
                AND (
                    c.tag_id IN (SELECT tp.tag_id FROM tag_puzzle tp WHERE tp.puzzle_id = p.id)
                    OR (
                        (p.hide_until IS NULL OR p.hide_until <= :now::timestamp)
                        AND c.id IN (
                            SELECT cr.competition_id
                            FROM competition_round_puzzle crp
                            INNER JOIN competition_round cr ON cr.id = crp.round_id
                            WHERE crp.puzzle_id = p.id
                                AND (crp.hide_until_round_starts = false OR cr.starts_at <= :revealedRoundsStartedBefore::timestamp)
                        )
                    )
                )
            UNION ALL
            SELECT
                cs.name,
                cs.slug,
                NULL,
                NULL,
                true,
                NULL
            FROM competition_series cs
            WHERE cs.tag_id IN (SELECT tp.tag_id FROM tag_puzzle tp WHERE tp.puzzle_id = p.id)
                AND cs.approved_at IS NOT NULL
                AND cs.rejected_at IS NULL
        ) used
    ) AS used_at
FROM puzzle p
LEFT JOIN puzzle_statistics ps ON ps.puzzle_id = p.id
WHERE p.id = :puzzleId
SQL;

        $now = $this->clock->now();

        /**
         * @var false|array{
         *     solo_count: int|string,
         *     median_time_solo: null|int|string,
         *     fastest_time_solo: null|int|string,
         *     duo_count: int|string,
         *     median_time_duo: null|int|string,
         *     fastest_time_duo: null|int|string,
         *     team_count: int|string,
         *     median_time_team: null|int|string,
         *     fastest_time_team: null|int|string,
         *     used_at: string,
         * } $row
         */
        $row = $this->database
            ->executeQuery($query, [
                'puzzleId' => $puzzleId,
                'now' => $now->format('Y-m-d H:i:s'),
                'revealedRoundsStartedBefore' => $now->sub(new DateInterval(self::ROUND_REVEAL_BUFFER))->format('Y-m-d H:i:s'),
            ])
            ->fetchAssociative();

        if ($row === false) {
            throw new PuzzleNotFound();
        }

        /**
         * @var list<array{
         *     name: string,
         *     slug: null|string,
         *     series_name: null|string,
         *     series_slug: null|string,
         *     is_series: bool,
         * }> $competitions
         */
        $competitions = json_decode($row['used_at'], true, flags: JSON_THROW_ON_ERROR);

        return new PuzzleSummary(
            soloSolvesCount: (int) $row['solo_count'],
            medianTimeSolo: self::nullableInt($row['median_time_solo']),
            fastestTimeSolo: self::nullableInt($row['fastest_time_solo']),
            duoSolvesCount: (int) $row['duo_count'],
            medianTimeDuo: self::nullableInt($row['median_time_duo']),
            fastestTimeDuo: self::nullableInt($row['fastest_time_duo']),
            teamSolvesCount: (int) $row['team_count'],
            medianTimeTeam: self::nullableInt($row['median_time_team']),
            fastestTimeTeam: self::nullableInt($row['fastest_time_team']),
            usedAt: array_map(
                static fn (array $competition): CompetitionReference => new CompetitionReference(
                    name: $competition['name'],
                    slug: $competition['slug'],
                    seriesName: $competition['series_name'],
                    seriesSlug: $competition['series_slug'],
                    isSeries: $competition['is_series'],
                ),
                $competitions,
            ),
        );
    }

    private static function nullableInt(null|int|string $value): null|int
    {
        return $value === null ? null : (int) $value;
    }
}
