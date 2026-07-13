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
     * Every earned badge row, all tiers included — the badge evaluator needs the complete
     * set to decide what is still missing (the display query below collapses to the highest
     * tier per type, which would make re-evaluations re-insert lower tiers).
     *
     * @return list<BadgeResult>
     */
    public function allEarnedTiers(string $playerId): array
    {
        $sql = <<<SQL
SELECT type, tier, earned_at
FROM badge
WHERE player_id = :playerId
ORDER BY type ASC, tier ASC NULLS LAST
SQL;

        /** @var list<array{type: string, tier: null|int|string, earned_at: string}> $rows */
        $rows = $this->database
            ->executeQuery($sql, ['playerId' => $playerId])
            ->fetchAllAssociative();

        return $this->hydrate($rows);
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

        return $this->hydrate($rows);
    }

    /**
     * @param list<array{type: string, tier: null|int|string, earned_at: string}> $rows
     * @return list<BadgeResult>
     */
    private function hydrate(array $rows): array
    {
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
