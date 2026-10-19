<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Services\PrivateProfileAccess;

/**
 * A fingerprint of everything of the player's own the Insights page is computed from, so a cached part is never
 * served after the player changed it (docs/features/insights/computation.md §4c.8): their results - solo and every
 * pair/team they are in, whoever tracked it; an edit of the time, the dates, the puzzle, the flags (suspicious,
 * unboxed, first try) or the group changes it, as does an added or deleted result - their pairs/teams as the page
 * shows them (the team's name, each member's name, code and whether this viewer may see them: a teammate turning
 * private, or a guest renamed, is never served from the cache), their collections and their lending. One statement
 * over the player's indexes (~1-3 ms for the heaviest player); the hashes are summed, so the order of rows never
 * matters. Other players' results reach the page only through the batch, covered by the generation - which the same
 * statement reads (withGeneration()), so a cache key costs one statement.
 */
readonly final class GetInsightsFingerprint
{
    public function __construct(
        private Connection $database,
        private PrivateProfileAccess $privateProfileAccess,
    ) {
    }

    public function forPlayer(string $playerId): string
    {
        return $this->withGeneration($playerId)['fingerprint'];
    }

    /**
     * The fingerprint and the batch generation (InsightsGeneration::current(), 0 = no run yet) in one statement - the
     * whole cache key of InsightsCache::keyFor().
     *
     * @return array{generation: int, fingerprint: string}
     */
    public function withGeneration(string $playerId): array
    {
        $resultHash = "hashtext(concat_ws('|', pst.id, pst.puzzle_id, pst.seconds_to_solve, pst.finished_at, pst.tracked_at, pst.suspicious, pst.unboxed, pst.first_attempt, pst.puzzling_type, pst.puzzling_team_id, pst.competition_id, pst.competition_series_id))";

        $isPrivate = $this->privateProfileAccess->sqlIsPrivate('member');

        /** @var array{generation: null|int|string, own: string, together: string, teams: string, collections: string, lending: string}|false $row */
        $row = $this->database->fetchAssociative(
            "SELECT
                (SELECT generation FROM insights_generation WHERE id = 1) AS generation,
                (SELECT COUNT(*) || ':' || COALESCE(SUM({$resultHash}), 0) FROM puzzle_solving_time pst WHERE pst.player_id = :playerId) AS own,
                (
                    SELECT COUNT(*) || ':' || COALESCE(SUM({$resultHash}), 0)
                    FROM puzzling_team_member me
                    JOIN puzzle_solving_time pst ON pst.puzzling_team_id = me.team_id
                    WHERE me.player_id = :playerId AND pst.player_id <> :playerId
                ) AS together,
                (
                    SELECT COUNT(*) || ':' || COALESCE(SUM(hashtext(concat_ws('|', ptm.team_id, pt.name, ptm.member_key, ptm.player_id, member.name, member.code, {$isPrivate}))), 0)
                    FROM puzzling_team_member me
                    JOIN puzzling_team pt ON pt.id = me.team_id
                    JOIN puzzling_team_member ptm ON ptm.team_id = me.team_id
                    LEFT JOIN player member ON member.id = ptm.player_id
                    WHERE me.player_id = :playerId
                ) AS teams,
                (
                    SELECT COUNT(*) || ':' || COALESCE(SUM(hashtext(concat_ws('|', ci.puzzle_id, ci.collection_id))), 0)
                    FROM collection_item ci
                    WHERE ci.player_id = :playerId
                ) AS collections,
                (
                    SELECT COUNT(*) || ':' || COALESCE(SUM(hashtext(concat_ws('|', lp.puzzle_id, lp.owner_player_id, lp.current_holder_player_id))), 0)
                    FROM lent_puzzle lp
                    WHERE lp.owner_player_id = :playerId OR lp.current_holder_player_id = :playerId
                ) AS lending",
            ['playerId' => $playerId],
        );

        if ($row === false) {
            return ['generation' => 0, 'fingerprint' => 'none'];
        }

        return [
            'generation' => (int) ($row['generation'] ?? 0),
            'fingerprint' => substr(sha1($row['own'] . '/' . $row['together'] . '/' . $row['teams'] . '/' . $row['collections'] . '/' . $row['lending']), 0, 16),
        ];
    }
}
