<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Results\AnalyticsStudioPlayerFacts;

/**
 * The studio members' numbers as of now (analytics-studio.md §2.7, R4, R13) - one statement for every analysable
 * member, shaped like GetPlayerCard's: community totals (community_player_stats), the MSP Rating at 500 pieces (the
 * ladder's) with the player header's place (GetPlayerRatingRanking::sqlRankOf() - no viewer here, so nobody's blocks
 * close it up) and the skill tier at the asked piece counts. A player who opted out of rankings has neither rating nor
 * skill (`ranking_opted_out`). Only analysable (public) members are asked, so no private player is ever read.
 */
readonly final class GetAnalyticsStudioPlayerFacts
{
    public const int RATING_PIECES = 500;

    public function __construct(
        private Connection $database,
        private GetPlayerRatingRanking $getPlayerRatingRanking,
    ) {
    }

    /**
     * @param list<string> $playerIds analysable members only
     * @param list<int> $piecesCounts the visible puzzles' piece counts (500 is always asked)
     * @return array<string, AnalyticsStudioPlayerFacts>
     */
    public function of(array $playerIds, array $piecesCounts): array
    {
        if ($playerIds === []) {
            return [];
        }

        $piecesCounts = array_values(array_unique([self::RATING_PIECES, ...$piecesCounts]));
        $rankOf = $this->getPlayerRatingRanking->sqlRankOf('elo');

        $rows = $this->database->executeQuery(
            <<<SQL
SELECT
    p.id AS player_id,
    COALESCE(s.solved_total, 0) AS solved_total,
    s.best500_seconds,
    CASE WHEN p.ranking_opted_out THEN NULL ELSE elo.elo_rating END AS elo_rating,
    CASE WHEN p.ranking_opted_out OR elo.id IS NULL THEN NULL ELSE {$rankOf} END AS elo_place,
    CASE WHEN p.ranking_opted_out THEN NULL ELSE (
        SELECT json_object_agg(skill.pieces_count, skill.skill_tier)
        FROM player_skill skill
        WHERE skill.player_id = p.id
            AND skill.pieces_count IN (:piecesCounts)
    )::text END AS skill_tiers
FROM player p
LEFT JOIN community_player_stats s ON s.player_id = p.id
LEFT JOIN player_elo elo ON elo.player_id = p.id AND elo.pieces_count = :ratingPieces
WHERE p.id IN (:playerIds)
SQL,
            ['playerIds' => $playerIds, 'piecesCounts' => $piecesCounts, 'ratingPieces' => self::RATING_PIECES],
            ['playerIds' => ArrayParameterType::STRING, 'piecesCounts' => ArrayParameterType::INTEGER],
        )->fetchAllAssociative();

        $facts = [];

        foreach ($rows as $row) {
            /** @var array{player_id: string, solved_total: int|string, best500_seconds: null|int|string, elo_rating: null|float|string, elo_place: null|int|string, skill_tiers: null|string} $row */
            /** @var array<string, int|string> $tiers */
            $tiers = $row['skill_tiers'] !== null ? json_decode($row['skill_tiers'], true, flags: JSON_THROW_ON_ERROR) : [];
            $skillTiers = [];
            foreach ($tiers as $pieces => $tier) {
                $skillTiers[(int) $pieces] = (int) $tier;
            }

            $playerId = strtolower($row['player_id']);
            $facts[$playerId] = new AnalyticsStudioPlayerFacts(
                playerId: $playerId,
                solvedTotal: (int) $row['solved_total'],
                best500Seconds: $row['best500_seconds'] !== null ? (int) $row['best500_seconds'] : null,
                rating: $row['elo_rating'] !== null ? (int) round((float) $row['elo_rating'] * 1000) : null,
                ratingPlace: $row['elo_place'] !== null ? (int) $row['elo_place'] : null,
                skillTiers: $skillTiers,
            );
        }

        return $facts;
    }
}
