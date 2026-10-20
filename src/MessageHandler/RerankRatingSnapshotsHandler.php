<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Message\RerankRatingSnapshots;
use SpeedPuzzling\Web\Query\GetPlayerRatingRanking;
use SpeedPuzzling\Web\Results\RatingSnapshotRerankResult;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * The stored places used to count ties as the worse place and players who opted out of rankings, and stored 0 for an
 * unrated player - a few places off the ladder. This ranks one stored day again from its stored ratings with the
 * ladder's rule (GetPlayerRatingRanking::sqlOnStoredLadder(), RANK()) and today's privacy and opt-out settings, the
 * same rule recordRatingSnapshots() writes by now. Only rows whose place changes are written.
 *
 * Two set-based UPDATEs on purpose: thousands of rows a day, a million over the history - loading them as entities
 * one by one would take hours.
 */
#[AsMessageHandler]
readonly final class RerankRatingSnapshotsHandler
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    public function __invoke(RerankRatingSnapshots $message): RatingSnapshotRerankResult
    {
        $onStoredLadder = GetPlayerRatingRanking::sqlOnStoredLadder('p');
        $params = ['day' => $message->day->format('Y-m-d')];

        $ranked = $this->connection->executeStatement(<<<SQL
UPDATE player_rating_snapshot s
SET elo_rank = ranked.place
FROM (
    SELECT
        s2.id,
        RANK() OVER (PARTITION BY s2.pieces_count, s2.snapshot_date ORDER BY s2.elo_rating DESC) AS place
    FROM player_rating_snapshot s2
    INNER JOIN player p ON p.id = s2.player_id
    WHERE s2.elo_rating IS NOT NULL
        AND {$onStoredLadder}
        AND s2.snapshot_date = CAST(:day AS timestamp)
) ranked
WHERE s.id = ranked.id
    AND s.elo_rank IS DISTINCT FROM ranked.place
SQL, $params);

        $unranked = $this->connection->executeStatement(<<<SQL
UPDATE player_rating_snapshot s
SET elo_rank = NULL
FROM player p
WHERE p.id = s.player_id
    AND s.elo_rank IS NOT NULL
    AND (s.elo_rating IS NULL OR NOT ({$onStoredLadder}))
    AND s.snapshot_date = CAST(:day AS timestamp)
SQL, $params);

        return new RatingSnapshotRerankResult((int) $ranked, (int) $unranked);
    }
}
