<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Services\Badges\LaunchStampWindow;

/**
 * The players holding a tier that still carries the launch backfill's stamp (HeldAtLaunch) - the ones
 * myspeedpuzzling:date-achievements-from-history has work for. Nothing before the launch. With `earnedSince`: the
 * players holding a tier earned from that moment on (a recount after a corrected rule).
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
    public function playerIds(null|DateTimeImmutable $earnedSince = null): array
    {
        $range = $this->range($earnedSince);

        if ($range === null) {
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
        $ids = $this->database->executeQuery($sql, $range)->fetchFirstColumn();

        return $ids;
    }

    public function stampedTiers(null|DateTimeImmutable $earnedSince = null): int
    {
        $range = $this->range($earnedSince);

        if ($range === null) {
            return 0;
        }

        $value = $this->database->fetchOne(
            'SELECT COUNT(*) FROM badge WHERE tier IS NOT NULL AND earned_at >= :stampedFrom AND earned_at <= :launchAt',
            $range,
        );

        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * @return null|array{stampedFrom: string, launchAt: string}
     */
    private function range(null|DateTimeImmutable $earnedSince): null|array
    {
        if ($earnedSince !== null) {
            return [
                'stampedFrom' => $earnedSince->format('Y-m-d H:i:s.u'),
                'launchAt' => '9999-12-31 23:59:59',
            ];
        }

        $window = $this->launchStampWindow->current();

        if ($window->launchAt === null || $window->stampedFrom === null) {
            return null;
        }

        return [
            'stampedFrom' => $window->stampedFrom->format('Y-m-d H:i:s'),
            'launchAt' => $window->launchAt->format('Y-m-d H:i:s'),
        ];
    }
}
