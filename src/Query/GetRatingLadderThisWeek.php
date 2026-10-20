<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Results\RatingLadderSpotlight;
use SpeedPuzzling\Web\Value\MspRatingTimeline;

/**
 * "This week on the ladder" above the MSP Rating ladder (docs/features/msp-rating-history.md): against the stored day
 * the ladder's movement compares with (MspRatingTimeline::moveReferenceDay()),
 * - the biggest climber - only a player with a new result in the rating since that day, so nobody climbs because
 *   others' older results faded,
 * - the best-placed new entry,
 * - a player at a better place than on any stored day before, with at least a week of history.
 * The world list without anybody's blocks (GetPlayerRatingRanking::sqlOnStoredLadder()), a few candidates per tile:
 * Services\RatingLadderThisWeek caches them for everybody and leaves out the viewer's hidden players and repeats.
 * One statement, ~150 ms on a copy of production (the best-ever history per climber) - never run per request.
 */
readonly final class GetRatingLadderThisWeek
{
    // A player needs this many stored days on the ladder before a place counts as a best ever
    public const int BEST_EVER_MIN_DAYS = 7;

    // Candidates per tile - enough for a viewer who hides a few of them
    public const int CANDIDATES = 5;

    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * @return list<RatingLadderSpotlight> best first within each kind, kinds in the order climber, new entry, best ever
     */
    public function candidates(int $piecesCount, DateTimeImmutable $now): array
    {
        $referenceDay = MspRatingTimeline::moveReferenceDay($now, GetPlayerRatingRanking::MOVE_DAYS);

        if ($referenceDay === null) {
            return [];
        }

        $onLadder = GetPlayerRatingRanking::sqlOnStoredLadder('p');

        $query = <<<SQL
WITH reference AS (
    SELECT MAX(snapshot_date) AS day
    FROM player_rating_snapshot
    WHERE pieces_count = :piecesCount
        AND elo_rating IS NOT NULL
        AND snapshot_date <= CAST(:referenceDay AS timestamp)
        AND snapshot_date >= CAST(:notBefore AS timestamp)
),
now_board AS (
    SELECT pe.player_id, RANK() OVER (ORDER BY pe.elo_rating DESC) AS rank
    FROM player_elo pe
    INNER JOIN player p ON p.id = pe.player_id
    WHERE pe.pieces_count = :piecesCount
        AND {$onLadder}
),
prev AS (
    SELECT s.player_id, RANK() OVER (ORDER BY s.elo_rating DESC) AS prev_rank
    FROM reference
    INNER JOIN player_rating_snapshot s ON s.pieces_count = :piecesCount AND s.snapshot_date = reference.day
    INNER JOIN player p ON p.id = s.player_id
    WHERE s.elo_rating IS NOT NULL
        AND {$onLadder}
),
moved AS (
    SELECT now_board.player_id, now_board.rank, prev.prev_rank
    FROM now_board
    LEFT JOIN prev ON prev.player_id = now_board.player_id
    WHERE (SELECT day FROM reference) IS NOT NULL
),
climber AS (
    SELECT moved.player_id, moved.rank, moved.prev_rank - moved.rank AS value,
        ROW_NUMBER() OVER (ORDER BY moved.prev_rank - moved.rank DESC, moved.rank, moved.player_id) AS position
    FROM moved
    WHERE moved.prev_rank > moved.rank
        AND EXISTS (
            SELECT 1 FROM player_rating_entry e
            WHERE e.player_id = moved.player_id
                AND e.pieces_count = :piecesCount
                AND e.solved_at > (SELECT day FROM reference)
        )
    ORDER BY moved.prev_rank - moved.rank DESC, moved.rank, moved.player_id
    LIMIT :candidates
),
new_entry AS (
    SELECT moved.player_id, moved.rank, NULL::int AS value,
        ROW_NUMBER() OVER (ORDER BY moved.rank, moved.player_id) AS position
    FROM moved
    WHERE moved.prev_rank IS NULL
    ORDER BY moved.rank, moved.player_id
    LIMIT :candidates
),
best_ever AS (
    SELECT moved.player_id, moved.rank, history.best_before AS value,
        ROW_NUMBER() OVER (ORDER BY moved.rank, moved.player_id) AS position
    FROM moved
    CROSS JOIN LATERAL (
        SELECT MIN(s.elo_rank) AS best_before, COUNT(*) AS days
        FROM player_rating_snapshot s
        WHERE s.player_id = moved.player_id
            AND s.pieces_count = :piecesCount
            AND s.snapshot_date < CAST(:today AS timestamp)
            AND s.elo_rank IS NOT NULL
    ) history
    WHERE moved.prev_rank > moved.rank
        AND history.days >= :bestEverMinDays
        AND moved.rank < history.best_before
    ORDER BY moved.rank, moved.player_id
    LIMIT :candidates
),
tiles AS (
    SELECT 1 AS sort, 'climber' AS kind, climber.* FROM climber
    UNION ALL SELECT 2, 'new_entry', new_entry.* FROM new_entry
    UNION ALL SELECT 3, 'best_ever', best_ever.* FROM best_ever
)
SELECT
    tiles.kind,
    tiles.player_id,
    tiles.rank,
    tiles.value,
    p.name AS player_name,
    p.code AS player_code,
    p.country AS player_country,
    p.avatar AS player_avatar
FROM tiles
INNER JOIN player p ON p.id = tiles.player_id
ORDER BY tiles.sort, tiles.position
SQL;

        /** @var list<array{kind: string, player_id: string, rank: int|string, value: null|int|string, player_name: null|string, player_code: null|string, player_country: null|string, player_avatar: null|string}> $rows */
        $rows = $this->database->fetchAllAssociative($query, [
            'piecesCount' => $piecesCount,
            'referenceDay' => $referenceDay->format('Y-m-d'),
            'notBefore' => MspRatingTimeline::moveNotBefore($referenceDay, GetPlayerRatingRanking::MOVE_DAYS)->format('Y-m-d'),
            'today' => $now->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d'),
            'bestEverMinDays' => self::BEST_EVER_MIN_DAYS,
            'candidates' => self::CANDIDATES,
        ]);

        $tiles = [];

        foreach ($rows as $row) {
            $tiles[] = new RatingLadderSpotlight(
                kind: $row['kind'],
                playerId: $row['player_id'],
                playerName: $row['player_name'],
                playerCode: $row['player_code'],
                playerCountry: $row['player_country'],
                playerAvatar: $row['player_avatar'],
                rank: (int) $row['rank'],
                value: $row['value'] !== null ? (int) $row['value'] : null,
            );
        }

        return $tiles;
    }
}
