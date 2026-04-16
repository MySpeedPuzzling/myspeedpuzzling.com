<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Results\BadgeResult;
use SpeedPuzzling\Web\Value\BadgeTier;
use SpeedPuzzling\Web\Value\BadgeType;

readonly class GetBadges
{
    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * @return list<BadgeResult>
     */
    public function forPlayer(string $playerId): array
    {
        $sql = <<<SQL
SELECT type, tier, earned_at
FROM badge
WHERE player_id = :playerId
ORDER BY type ASC, tier ASC NULLS FIRST
SQL;

        /** @var list<array{type: string, tier: null|int|string, earned_at: string}> $rows */
        $rows = $this->database
            ->executeQuery($sql, ['playerId' => $playerId])
            ->fetchAllAssociative();

        $badges = [];

        foreach ($rows as $row) {
            // Unknown values in the database (e.g. badge types that were
            // removed or not implemented yet) must not break player profiles
            $type = BadgeType::tryFrom($row['type']);

            if ($type === null) {
                continue;
            }

            $badges[] = new BadgeResult(
                type: $type,
                tier: $row['tier'] === null ? null : BadgeTier::from((int) $row['tier']),
                earnedAt: new DateTimeImmutable($row['earned_at']),
            );
        }

        return $badges;
    }
}
