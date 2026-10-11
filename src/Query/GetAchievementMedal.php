<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Results\AchievementMedal;
use SpeedPuzzling\Web\Services\Xp\XpLaunchReadiness;
use SpeedPuzzling\Web\Value\BadgeTier;
use SpeedPuzzling\Web\Value\BadgeType;

/**
 * The medal facts of a player's earned tiers of one achievement (docs/features/xp-levels/ux-polish/design-spec.md §2
 * "Serial number" and §8.1 "Launch-time data"): the earn date and the serial number. Used by the detail sheet and the
 * holders page hero ("You hold Gold · No. 80 of 82").
 *
 * - Held at launch: a tier with earned_at at or before the launch marker (xp_settings.launch_ready_at, read through
 *   XpLaunchReadiness - no statement of its own), or any tier while the marker is not set. The launch backfill stamped
 *   them all within minutes, so they get neither a serial nor a date.
 * - Serial of a tier earned after the launch = 1 + the badge rows of that type and tier ahead of it by
 *   (earned_at, id) - every holder counts, the ones at launch included (the first new Gold after 79 at launch is
 *   No. 80). That is `ROW_NUMBER() OVER (PARTITION BY type, tier ORDER BY earned_at, id)` over all rows, the same
 *   number for every viewer. Computed, never stored: an earlier holder dropping the tier moves later numbers up by one.
 *
 * One statement for every earned tier of the achievement (at most five counts, none for the tiers held at launch).
 */
readonly class GetAchievementMedal
{
    public function __construct(
        private Connection $database,
        private XpLaunchReadiness $xpLaunchReadiness,
    ) {
    }

    /**
     * @return array<int, AchievementMedal> tier value → medal, only the tiers the player holds
     */
    public function forPlayerAndType(string $playerId, BadgeType $type): array
    {
        $launchedAt = $this->xpLaunchReadiness->readyAt();

        $sql = <<<SQL
SELECT
    mine.tier,
    mine.earned_at,
    CASE WHEN CAST(:launchedAt AS TIMESTAMP) IS NULL OR mine.earned_at <= CAST(:launchedAt AS TIMESTAMP) THEN NULL ELSE (
        SELECT COUNT(*) + 1
        FROM badge ahead
        WHERE ahead.type = mine.type
          AND ahead.tier = mine.tier
          AND (ahead.earned_at, ahead.id) < (mine.earned_at, mine.id)
    ) END AS serial
FROM badge mine
WHERE mine.player_id = :playerId
  AND mine.type = :type
  AND mine.tier IS NOT NULL
ORDER BY mine.tier
SQL;

        /** @var list<array{tier: int|string, earned_at: string, serial: null|int|string}> $rows */
        $rows = $this->database->executeQuery($sql, [
            'playerId' => $playerId,
            'type' => $type->value,
            'launchedAt' => $launchedAt?->format('Y-m-d H:i:s'),
        ])->fetchAllAssociative();

        $medals = [];

        foreach ($rows as $row) {
            $tier = BadgeTier::tryFrom((int) $row['tier']);

            if ($tier === null) {
                continue;
            }

            $medals[$tier->value] = new AchievementMedal(
                type: $type,
                tier: $tier,
                earnedAt: new DateTimeImmutable($row['earned_at']),
                heldAtLaunch: $row['serial'] === null,
                serial: $row['serial'] === null ? null : (int) $row['serial'],
            );
        }

        return $medals;
    }
}
