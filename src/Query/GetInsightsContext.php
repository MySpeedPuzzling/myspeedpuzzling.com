<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Results\InsightsContext;
use SpeedPuzzling\Web\Results\InsightsProfileAxisScore;
use SpeedPuzzling\Web\Value\InsightsProfileAxis;

/**
 * The batch-computed inputs of one player's Insights page in one statement (docs/features/insights/computation.md
 * "Core sections" §4): the insights generation, the profile card (player_insights_profile), the player's and the
 * community's learning ratios, the 500-piece MSP rating and its place on today's ladder (the header's rule,
 * GetPlayerRatingRanking::sqlRankOf(); none while private or opted out of rankings). Self-only - the owner's own rows.
 */
readonly final class GetInsightsContext
{
    public function __construct(
        private Connection $database,
        private GetPlayerRatingRanking $getPlayerRatingRanking,
    ) {
    }

    /**
     * @throws PlayerNotFound
     */
    public function forPlayer(string $playerId): InsightsContext
    {
        if (Uuid::isValid($playerId) === false) {
            throw new PlayerNotFound();
        }

        $axisColumns = [];

        foreach (InsightsProfileAxis::cases() as $axis) {
            $axisColumns[] = "ip.{$axis->value}_score, ip.{$axis->value}_sample_size, ip.{$axis->value}_percentile";
        }

        $axisSelect = implode(",\n    ", $axisColumns);
        $ratingPlace = $this->getPlayerRatingRanking->sqlRankOf('ctx_elo');

        $query = <<<SQL
SELECT
    (SELECT generation FROM insights_generation WHERE id = 1) AS generation,
    ip.player_id AS profile_player_id,
    ip.computed_at AS profile_computed_at,
    {$axisSelect},
    (
        SELECT json_agg(json_build_object('from', pir.from_attempt, 'ratio', pir.median_ratio, 'n', pir.sample_size))
        FROM player_improvement_ratio pir
        WHERE pir.player_id = :playerId
    ) AS player_ratios,
    (
        SELECT json_agg(json_build_object('pieces', gir.pieces_count, 'from', gir.from_attempt, 'ratio', gir.median_ratio, 'n', gir.sample_size))
        FROM global_improvement_ratio gir
        WHERE gir.gap_bucket = 'all'
    ) AS global_ratios,
    ctx_elo.elo_rating AS rating_500,
    CASE WHEN ctx_player.is_private = false AND ctx_player.ranking_opted_out = false AND ctx_elo.id IS NOT NULL
        THEN {$ratingPlace}
    END AS rating_500_place
FROM (SELECT 1) AS one
LEFT JOIN player_insights_profile ip ON ip.player_id = :playerId
LEFT JOIN player ctx_player ON ctx_player.id = :playerId
LEFT JOIN player_elo ctx_elo ON ctx_elo.player_id = :playerId AND ctx_elo.pieces_count = 500
SQL;

        /** @var array<string, mixed> $row */
        $row = $this->database->fetchAssociative($query, ['playerId' => $playerId]);

        $axes = [];

        if (is_string($row['profile_player_id'] ?? null)) {
            foreach (InsightsProfileAxis::cases() as $axis) {
                $score = $row[$axis->value . '_score'];
                $sampleSize = $row[$axis->value . '_sample_size'];
                $percentile = $row[$axis->value . '_percentile'];

                $axes[] = new InsightsProfileAxisScore(
                    axis: $axis,
                    score: is_numeric($score) ? (float) $score : null,
                    sampleSize: is_numeric($sampleSize) ? (int) $sampleSize : 0,
                    percentile: is_numeric($percentile) ? (float) $percentile : null,
                );
            }
        }

        $playerRatios = [];

        foreach (self::jsonList($row['player_ratios'] ?? null) as $ratio) {
            $playerRatios[(int) $ratio['from']] = ['ratio' => (float) $ratio['ratio'], 'sample_size' => (int) $ratio['n']];
        }

        ksort($playerRatios);

        $globalRatios = [];

        foreach (self::jsonList($row['global_ratios'] ?? null) as $ratio) {
            $globalRatios[(int) ($ratio['pieces'] ?? 0)][(int) $ratio['from']] = ['ratio' => (float) $ratio['ratio'], 'sample_size' => (int) $ratio['n']];
        }

        $generation = $row['generation'] ?? null;
        $computedAt = $row['profile_computed_at'] ?? null;
        $rating = $row['rating_500'] ?? null;
        $place = $row['rating_500_place'] ?? null;

        return new InsightsContext(
            generation: is_numeric($generation) ? (int) $generation : 0,
            profileAxes: $axes,
            profileComputedAt: is_string($computedAt) ? new DateTimeImmutable($computedAt) : null,
            playerImprovementRatios: $playerRatios,
            globalImprovementRatios: $globalRatios,
            rating500: is_numeric($rating) ? (float) $rating : null,
            ratingPlace500: is_numeric($place) ? (int) $place : null,
        );
    }

    /**
     * @return list<array{from: int|float|string, ratio: int|float|string, n: int|float|string, pieces?: int|float|string}>
     */
    private static function jsonList(mixed $json): array
    {
        if (is_string($json) === false) {
            return [];
        }

        $decoded = json_decode($json, true);

        if (is_array($decoded) === false) {
            return [];
        }

        $list = [];

        foreach ($decoded as $item) {
            if (is_array($item) && is_numeric($item['from'] ?? null) && is_numeric($item['ratio'] ?? null) && is_numeric($item['n'] ?? null)) {
                /** @var array{from: int|float|string, ratio: int|float|string, n: int|float|string, pieces?: int|float|string} $item */
                $list[] = $item;
            }
        }

        return $list;
    }
}
