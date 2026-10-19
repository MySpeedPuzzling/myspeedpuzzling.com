<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;

/**
 * Whether a player has enough solo results for the Insights page to work (InsightsReport::MIN_RESULTS) - the results
 * GetInsightsSoloResults counts: timed, not suspicious, not on a secret puzzle. One short index scan that stops at
 * the threshold; asked only for the owner's "See why" links while Insights is visible.
 */
readonly final class GetInsightsResultCount
{
    public function __construct(
        private Connection $database,
        private ClockInterface $clock,
    ) {
    }

    public function reaches(string $playerId, int $minimum): bool
    {
        $found = $this->database->fetchOne(
            "SELECT 1
            FROM puzzle_solving_time pst
            JOIN puzzle p ON p.id = pst.puzzle_id
            WHERE pst.player_id = :playerId
                AND pst.puzzling_type = 'solo'
                AND pst.suspicious = false
                AND pst.seconds_to_solve > 0
                AND (p.hide_until IS NULL OR p.hide_until <= :now)
                AND (p.hide_image_until IS NULL OR p.hide_image_until <= :now)
            LIMIT 1 OFFSET :offset",
            [
                'playerId' => $playerId,
                'now' => $this->clock->now()->format('Y-m-d H:i:s'),
                'offset' => max(0, $minimum - 1),
            ],
        );

        return $found !== false;
    }
}
