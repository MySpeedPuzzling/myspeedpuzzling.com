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
SELECT DISTINCT ON (type) type, tier, earned_at
FROM badge
WHERE player_id = :playerId
ORDER BY type ASC, tier DESC NULLS LAST
SQL;

        /** @var list<array{type: string, tier: null|int|string, earned_at: string}> $rows */
        $rows = $this->database
            ->executeQuery($sql, ['playerId' => $playerId])
            ->fetchAllAssociative();

        return array_map(static function (array $row): BadgeResult {
            $tier = $row['tier'] === null ? null : BadgeTier::from((int) $row['tier']);

            return new BadgeResult(
                type: BadgeType::from($row['type']),
                tier: $tier,
                earnedAt: new DateTimeImmutable($row['earned_at']),
            );
        }, $rows);
    }
}
