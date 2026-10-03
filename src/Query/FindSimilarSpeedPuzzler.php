<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Results\SimilarSpeedPuzzler;
use SpeedPuzzling\Web\Value\CountryCode;
use SpeedPuzzling\Web\Value\SkillTier;

/**
 * "Someone at your speed" (docs/features/player-comparison.md, members): the 50 players nearest to the viewer by skill
 * percentile at 500 pieces, shuffled by a seed, and the first of them that passes - at least MIN_SHARED_PUZZLES solo
 * puzzles in common with the viewer and a solo solve in the last ACTIVE_MONTHS. When the viewer has no skill yet (or
 * none of those 50 passes), the same over player_baseline at the viewer's main piece count (most qualifying solves).
 *
 * Only public profiles (`is_private = false` - global-ranking semantics: nobody is suggested differently to different
 * viewers), not opted out of rankings, no block in either direction - explicit `user_block` rows for the viewer id
 * passed in, so it does not depend on a security token; a random suggestion of somebody who blocked you is a safety
 * issue, not only a matter of taste. Never the viewer, never somebody already in the line-up.
 *
 * Shape (measured, see the doc): MATERIALIZED CTEs + a lazy LATERAL check - the checks run row by row in shuffled
 * order and stop at the first pass (LIMIT 1); the naive shape took 820 ms. The order relies on PostgreSQL reading a
 * materialized, ordered CTE back in order (nested loop on top of a CTE scan) - an outer ORDER BY would force all 50
 * checks. A seed instead of random() keeps "Roll again" a new seed and tests deterministic (like the puzzle picker).
 */
readonly final class FindSimilarSpeedPuzzler
{
    public const int SKILL_PIECES_COUNT = 500;

    public const int NEAREST = 50;

    public const int MIN_SHARED_PUZZLES = 5;

    public const int ACTIVE_MONTHS = 12;

    public const int RECENT_DAYS = 30;

    public function __construct(
        private Connection $database,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @param list<string> $excludedPlayerIds the viewer's Solo line-up
     * @param string $seed any string; the same seed gives the same suggestion while the data stays the same
     */
    public function find(string $viewerPlayerId, array $excludedPlayerIds, string $seed): null|SimilarSpeedPuzzler
    {
        if (Uuid::isValid($viewerPlayerId) === false) {
            return null;
        }

        $excluded = array_values(array_unique(array_map(
            strtolower(...),
            array_filter($excludedPlayerIds, static fn(string $id): bool => Uuid::isValid($id)),
        )));

        $now = $this->clock->now();
        $parameters = [
            'viewerId' => strtolower($viewerPlayerId),
            // A PostgreSQL array literal of validated uuids: `<> ALL('{}')` keeps everybody, unlike `NOT IN ()`
            'excluded' => '{' . implode(',', $excluded) . '}',
            'seed' => $seed,
            'activeSince' => $now->modify('-' . self::ACTIVE_MONTHS . ' months')->format('Y-m-d H:i:s'),
            'recentSince' => $now->modify('-' . self::RECENT_DAYS . ' days')->format('Y-m-d H:i:s'),
            'nearest' => self::NEAREST,
            'minShared' => self::MIN_SHARED_PUZZLES,
            'skillPieces' => self::SKILL_PIECES_COUNT,
        ];
        $types = [
            'nearest' => ParameterType::INTEGER,
            'minShared' => ParameterType::INTEGER,
            'skillPieces' => ParameterType::INTEGER,
        ];

        $eligible = self::eligible();

        $bySkill = <<<SQL
me AS MATERIALIZED (
    SELECT CAST(:skillPieces AS INT) AS pieces_count, skill_percentile, skill_tier, NULL::INT AS baseline_seconds
    FROM player_skill
    WHERE player_id = :viewerId AND pieces_count = :skillPieces
),
nearest AS MATERIALIZED (
    SELECT candidate.player_id, candidate.skill_percentile, candidate.skill_tier, NULL::INT AS baseline_seconds
    FROM me
    INNER JOIN player_skill candidate ON candidate.pieces_count = me.pieces_count
    INNER JOIN player ON player.id = candidate.player_id
    WHERE {$eligible}
    ORDER BY ABS(candidate.skill_percentile - me.skill_percentile), candidate.player_id
    LIMIT :nearest
)
SQL;

        $byBaseline = <<<SQL
me AS MATERIALIZED (
    SELECT pieces_count, NULL::DOUBLE PRECISION AS skill_percentile, NULL::INT AS skill_tier, baseline_seconds
    FROM player_baseline
    WHERE player_id = :viewerId AND baseline_seconds > 0
    ORDER BY qualifying_solves_count DESC, ABS(pieces_count - :skillPieces), pieces_count
    LIMIT 1
),
nearest AS MATERIALIZED (
    SELECT candidate.player_id, NULL::DOUBLE PRECISION AS skill_percentile, NULL::INT AS skill_tier, candidate.baseline_seconds
    FROM me
    INNER JOIN player_baseline candidate ON candidate.pieces_count = me.pieces_count AND candidate.baseline_seconds > 0
    INNER JOIN player ON player.id = candidate.player_id
    WHERE {$eligible}
    ORDER BY ABS(LN(candidate.baseline_seconds::DOUBLE PRECISION / me.baseline_seconds)), candidate.player_id
    LIMIT :nearest
)
SQL;

        foreach ([SimilarSpeedPuzzler::BASIS_SKILL => $bySkill, SimilarSpeedPuzzler::BASIS_BASELINE => $byBaseline] as $basis => $candidates) {
            $found = $this->first($candidates, $basis, $parameters, $types);

            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }

    private static function eligible(): string
    {
        return <<<SQL
player.id <> :viewerId
        AND player.id <> ALL(CAST(:excluded AS UUID[]))
        AND player.is_private = false
        AND player.ranking_opted_out = false
        AND NOT EXISTS (SELECT 1 FROM user_block WHERE user_block.blocker_id = :viewerId AND user_block.blocked_id = player.id)
        AND NOT EXISTS (SELECT 1 FROM user_block WHERE user_block.blocker_id = player.id AND user_block.blocked_id = :viewerId)
SQL;
    }

    /**
     * @param array<string, mixed> $parameters
     * @param array<string, ParameterType> $types
     */
    private function first(string $candidates, string $basis, array $parameters, array $types): null|SimilarSpeedPuzzler
    {
        $query = <<<SQL
WITH {$candidates},
mine AS MATERIALIZED (
    SELECT ARRAY_AGG(DISTINCT puzzle_id) AS puzzle_ids
    FROM puzzle_solving_time
    WHERE player_id = :viewerId AND puzzling_type = 'solo' AND suspicious = false AND seconds_to_solve IS NOT NULL
),
shuffled AS MATERIALIZED (
    SELECT nearest.* FROM nearest ORDER BY MD5(:seed || nearest.player_id::TEXT)
),
found AS (
    SELECT shuffled.*, shared.puzzles AS shared_puzzles
    FROM shuffled
    CROSS JOIN mine
    CROSS JOIN LATERAL (
        SELECT COUNT(DISTINCT pst.puzzle_id) AS puzzles
        FROM puzzle_solving_time pst
        WHERE pst.player_id = shuffled.player_id
            AND pst.puzzling_type = 'solo' AND pst.suspicious = false AND pst.seconds_to_solve IS NOT NULL
            AND pst.puzzle_id = ANY(mine.puzzle_ids)
    ) shared
    WHERE shared.puzzles >= :minShared
        AND EXISTS (
            SELECT 1 FROM puzzle_solving_time active
            WHERE active.player_id = shuffled.player_id AND active.puzzling_type = 'solo'
                AND COALESCE(active.finished_at, active.tracked_at) >= CAST(:activeSince AS TIMESTAMP)
        )
    LIMIT 1
)
SELECT
    found.player_id,
    player.name AS player_name,
    player.code AS player_code,
    player.avatar AS player_avatar,
    player.country AS player_country,
    me.pieces_count,
    me.skill_percentile AS viewer_skill_percentile,
    me.skill_tier AS viewer_skill_tier,
    me.baseline_seconds AS viewer_baseline_seconds,
    found.skill_percentile,
    found.skill_tier,
    found.baseline_seconds,
    found.shared_puzzles,
    (
        SELECT COUNT(*)
        FROM puzzle_solving_time recent
        WHERE recent.player_id = found.player_id AND recent.puzzling_type = 'solo'
            AND recent.suspicious = false AND recent.seconds_to_solve IS NOT NULL
            AND COALESCE(recent.finished_at, recent.tracked_at) >= CAST(:recentSince AS TIMESTAMP)
    ) AS recent_solves
FROM found
CROSS JOIN me
INNER JOIN player ON player.id = found.player_id
SQL;

        /**
         * @var false|array{
         *     player_id: string,
         *     player_name: null|string,
         *     player_code: string,
         *     player_avatar: null|string,
         *     player_country: null|string,
         *     pieces_count: int,
         *     viewer_skill_percentile: null|float|string,
         *     viewer_skill_tier: null|int,
         *     viewer_baseline_seconds: null|int,
         *     skill_percentile: null|float|string,
         *     skill_tier: null|int,
         *     baseline_seconds: null|int,
         *     shared_puzzles: int,
         *     recent_solves: int,
         * } $row
         */
        $row = $this->database->fetchAssociative($query, $parameters, $types);

        if ($row === false) {
            return null;
        }

        return new SimilarSpeedPuzzler(
            playerId: $row['player_id'],
            playerName: $row['player_name'],
            playerCode: strtoupper($row['player_code']),
            playerAvatar: $row['player_avatar'],
            playerCountry: CountryCode::fromCode($row['player_country']),
            basis: $basis,
            piecesCount: (int) $row['pieces_count'],
            viewerSkillTier: $row['viewer_skill_tier'] !== null ? SkillTier::tryFrom((int) $row['viewer_skill_tier']) : null,
            viewerSkillPercentile: $row['viewer_skill_percentile'] !== null ? (float) $row['viewer_skill_percentile'] : null,
            skillTier: $row['skill_tier'] !== null ? SkillTier::tryFrom((int) $row['skill_tier']) : null,
            skillPercentile: $row['skill_percentile'] !== null ? (float) $row['skill_percentile'] : null,
            viewerBaselineSeconds: $row['viewer_baseline_seconds'] !== null ? (int) $row['viewer_baseline_seconds'] : null,
            baselineSeconds: $row['baseline_seconds'] !== null ? (int) $row['baseline_seconds'] : null,
            sharedPuzzles: (int) $row['shared_puzzles'],
            recentSolves: (int) $row['recent_solves'],
        );
    }
}
