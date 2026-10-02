<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Results\PlayerReviewCounts;
use SpeedPuzzling\Web\Value\DuplicateCaseStatus;

/**
 * The banner on the Hub and the player's own profile (docs/features/duplicate-results.md, "Banner"): open
 * duplicate cases, copies removed automatically lately and first-try conflicts - in one query. Only the owner
 * pays for it; nobody else's page asks.
 *
 * The first-try part reads every result of the player (~4-5 ms for the players with 2,000 results, measured
 * 2026-10-02 on a production copy), the rest < 0.1 ms - the Hub, the most visited page, leaves it out (it never
 * showed first-try conflicts), the own profile keeps it.
 */
readonly final class GetPlayerReviewCounts
{
    public const int AUTO_REMOVED_DAYS = 30;

    public function __construct(
        private Connection $database,
        private GetFirstTryTimes $getFirstTryTimes,
        private ClockInterface $clock,
    ) {
    }

    public function forPlayer(string $playerId, bool $withFirstTryConflicts = true): PlayerReviewCounts
    {
        $firstTryConflicts = $withFirstTryConflicts ? $this->getFirstTryTimes->conflictCountSql() : '0';

        $query = <<<SQL
SELECT
    (
        -- One result saved three times is three cases but one set on the review page: count it once
        -- (a set is one puzzle and one time of this person)
        SELECT COUNT(DISTINCT (a.puzzle_id, a.seconds_to_solve))
        FROM result_duplicate_case c
        INNER JOIN puzzle_solving_time a ON a.id = c.time_a_id
        INNER JOIN puzzle_solving_time b ON b.id = c.time_b_id
        WHERE c.player_id = :playerId
            AND c.status = :open
    ) AS duplicates,
    (
        SELECT COUNT(*)
        FROM result_auto_removal removal
        WHERE removal.player_id = :playerId
            AND removal.undone_at IS NULL
            AND removal.removed_at > :since
    ) AS auto_removed,
    {$firstTryConflicts} AS first_try_conflicts
SQL;

        /** @var array{duplicates: int|string, auto_removed: int|string, first_try_conflicts: int|string} $row */
        $row = $this->database->fetchAssociative($query, [
            'playerId' => $playerId,
            'open' => DuplicateCaseStatus::Open->value,
            'since' => $this->clock->now()->modify('-' . self::AUTO_REMOVED_DAYS . ' days')->format('Y-m-d H:i:s'),
        ]);

        return new PlayerReviewCounts(
            duplicates: (int) $row['duplicates'],
            autoRemoved: (int) $row['auto_removed'],
            firstTryConflicts: (int) $row['first_try_conflicts'],
        );
    }
}
