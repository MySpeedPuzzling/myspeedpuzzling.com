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

    public function forPlayer(string $playerId): PlayerReviewCounts
    {
        $query = <<<SQL
SELECT
    (
        SELECT COUNT(*)
        FROM result_duplicate_case c
        WHERE c.player_id = :playerId
            AND c.status = :open
            AND EXISTS (SELECT 1 FROM puzzle_solving_time a WHERE a.id = c.time_a_id)
            AND EXISTS (SELECT 1 FROM puzzle_solving_time b WHERE b.id = c.time_b_id)
    ) AS duplicates,
    (
        SELECT COUNT(*)
        FROM result_auto_removal removal
        WHERE removal.player_id = :playerId
            AND removal.undone_at IS NULL
            AND removal.removed_at > :since
    ) AS auto_removed,
    {$this->getFirstTryTimes->conflictCountSql()} AS first_try_conflicts
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
