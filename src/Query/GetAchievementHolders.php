<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Results\AchievementHolder;
use SpeedPuzzling\Web\Results\AchievementTierHolders;
use SpeedPuzzling\Web\Value\BadgeTier;
use SpeedPuzzling\Web\Value\BadgeType;
use SpeedPuzzling\Web\Value\CountryCode;

/**
 * Holders directory (§1.7): holder LISTS show members with public profiles only
 * (public badge display is a membership perk); private profiles and XP-opted-out
 * players never appear in lists; holder COUNTS include everyone.
 */
readonly class GetAchievementHolders
{
    private const int LISTED_PER_TIER = 30;

    private const string LISTED_ELIGIBILITY = <<<SQL
  p.is_private = false
  AND p.experience_system_opted_out = false
  AND (
    (m.ends_at IS NULL AND m.billing_period_ends_at IS NOT NULL)
    OR GREATEST(
        COALESCE(m.ends_at, m.billing_period_ends_at, '1970-01-01'::timestamp),
        COALESCE(m.granted_until, '1970-01-01'::timestamp)
    ) > NOW()
  )
SQL;

    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * Tier sections Diamond → Bronze; holders inside a tier ordered by earn date
     * (the first row is the "first to earn" highlight).
     *
     * @return list<AchievementTierHolders>
     */
    public function forType(BadgeType $type, null|string $country): array
    {
        $listedSql = <<<SQL
SELECT tier, earned_at, player_id, player_name, code, country, avatar
FROM (
    SELECT
        b.tier,
        b.earned_at,
        p.id AS player_id,
        p.name AS player_name,
        p.code,
        p.country,
        p.avatar,
        ROW_NUMBER() OVER (PARTITION BY b.tier ORDER BY b.earned_at ASC, b.id ASC) AS position
    FROM badge b
    JOIN player p ON p.id = b.player_id
    LEFT JOIN membership m ON m.player_id = p.id
    WHERE b.type = :type
      AND b.tier IS NOT NULL
      AND (CAST(:country AS TEXT) IS NULL OR p.country = :country)
      AND {$this->listedEligibility()}
) ranked
WHERE position <= :limit
ORDER BY tier DESC, position ASC
SQL;

        /** @var list<array{tier: int, earned_at: string, player_id: string, player_name: null|string, code: string, country: null|string, avatar: null|string}> $listedRows */
        $listedRows = $this->database->executeQuery($listedSql, [
            'type' => $type->value,
            'country' => $country,
            'limit' => self::LISTED_PER_TIER,
        ])->fetchAllAssociative();

        $countsSql = <<<SQL
SELECT b.tier, COUNT(*) AS holders_count
FROM badge b
JOIN player p ON p.id = b.player_id
WHERE b.type = :type
  AND b.tier IS NOT NULL
  AND (CAST(:country AS TEXT) IS NULL OR p.country = :country)
GROUP BY b.tier
SQL;

        /** @var list<array{tier: int, holders_count: int|string}> $countRows */
        $countRows = $this->database->executeQuery($countsSql, [
            'type' => $type->value,
            'country' => $country,
        ])->fetchAllAssociative();

        $countsByTier = [];
        foreach ($countRows as $row) {
            $countsByTier[$row['tier']] = is_numeric($row['holders_count']) ? (int) $row['holders_count'] : 0;
        }

        $holdersByTier = [];
        foreach ($listedRows as $row) {
            $holdersByTier[$row['tier']][] = new AchievementHolder(
                playerId: $row['player_id'],
                playerName: $row['player_name'],
                playerCode: $row['code'],
                countryCode: CountryCode::fromCode($row['country']),
                avatar: $row['avatar'],
                earnedAt: new DateTimeImmutable($row['earned_at']),
            );
        }

        $sections = [];

        foreach (array_reverse(BadgeTier::cases()) as $tier) {
            $sections[] = new AchievementTierHolders(
                tier: $tier,
                holders: $holdersByTier[$tier->value] ?? [],
                totalCount: $countsByTier[$tier->value] ?? 0,
            );
        }

        return $sections;
    }

    /**
     * Freshest earns across all tiers, one entry per player.
     *
     * @return list<AchievementHolder>
     */
    public function newestEarners(BadgeType $type, int $limit = 8): array
    {
        $sql = <<<SQL
SELECT tier, earned_at, player_id, player_name, code, country, avatar
FROM (
    SELECT DISTINCT ON (p.id)
        b.tier,
        b.earned_at,
        p.id AS player_id,
        p.name AS player_name,
        p.code,
        p.country,
        p.avatar
    FROM badge b
    JOIN player p ON p.id = b.player_id
    LEFT JOIN membership m ON m.player_id = p.id
    WHERE b.type = :type
      AND b.tier IS NOT NULL
      AND {$this->listedEligibility()}
    ORDER BY p.id, b.earned_at DESC
) newest
ORDER BY earned_at DESC
LIMIT :limit
SQL;

        /** @var list<array{tier: int, earned_at: string, player_id: string, player_name: null|string, code: string, country: null|string, avatar: null|string}> $rows */
        $rows = $this->database->executeQuery($sql, [
            'type' => $type->value,
            'limit' => $limit,
        ])->fetchAllAssociative();

        $earners = [];

        foreach ($rows as $row) {
            $earners[] = new AchievementHolder(
                playerId: $row['player_id'],
                playerName: $row['player_name'],
                playerCode: $row['code'],
                countryCode: CountryCode::fromCode($row['country']),
                avatar: $row['avatar'],
                earnedAt: new DateTimeImmutable($row['earned_at']),
                tier: BadgeTier::from($row['tier']),
            );
        }

        return $earners;
    }

    /**
     * Countries available for the filter — from listed holders only.
     *
     * @return list<string>
     */
    public function countries(BadgeType $type): array
    {
        $sql = <<<SQL
SELECT DISTINCT p.country
FROM badge b
JOIN player p ON p.id = b.player_id
LEFT JOIN membership m ON m.player_id = p.id
WHERE b.type = :type
  AND b.tier IS NOT NULL
  AND p.country IS NOT NULL
  AND {$this->listedEligibility()}
ORDER BY p.country
SQL;

        /** @var list<string> $countries */
        $countries = $this->database->executeQuery($sql, ['type' => $type->value])->fetchFirstColumn();

        return $countries;
    }

    private function listedEligibility(): string
    {
        return self::LISTED_ELIGIBILITY;
    }
}
