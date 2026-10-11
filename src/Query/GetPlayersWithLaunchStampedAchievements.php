<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Services\Badges\LaunchStampWindow;

/**
 * The players holding a tier that still carries the launch backfill's stamp (HeldAtLaunch) - the ones
 * myspeedpuzzling:date-achievements-from-history has work for. Nothing before the launch.
 */
readonly final class GetPlayersWithLaunchStampedAchievements
{
    public function __construct(
        private Connection $database,
        private LaunchStampWindow $launchStampWindow,
    ) {
    }

    /**
     * @return list<string>
     */
    public function playerIds(): array
    {
        $window = $this->launchStampWindow->current();

        if ($window->launchAt === null || $window->stampedFrom === null) {
            return [];
        }

        $sql = <<<SQL
SELECT DISTINCT CAST(player_id AS TEXT)
FROM badge
WHERE tier IS NOT NULL
  AND earned_at >= :stampedFrom
  AND earned_at <= :launchAt
ORDER BY 1
SQL;

        /** @var list<string> $ids */
        $ids = $this->database->executeQuery($sql, [
            'stampedFrom' => $window->stampedFrom->format('Y-m-d H:i:s'),
            'launchAt' => $window->launchAt->format('Y-m-d H:i:s'),
        ])->fetchFirstColumn();

        return $ids;
    }

    public function stampedTiers(): int
    {
        $window = $this->launchStampWindow->current();

        if ($window->launchAt === null || $window->stampedFrom === null) {
            return 0;
        }

        $value = $this->database->fetchOne(
            'SELECT COUNT(*) FROM badge WHERE tier IS NOT NULL AND earned_at >= :stampedFrom AND earned_at <= :launchAt',
            [
                'stampedFrom' => $window->stampedFrom->format('Y-m-d H:i:s'),
                'launchAt' => $window->launchAt->format('Y-m-d H:i:s'),
            ],
        );

        return is_numeric($value) ? (int) $value : 0;
    }
}
