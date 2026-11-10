<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Results\AchievementHolder;
use SpeedPuzzling\Web\Results\AchievementTierHolders;
use SpeedPuzzling\Web\Services\HiddenPlayers;
use SpeedPuzzling\Web\Value\BadgeTier;
use SpeedPuzzling\Web\Value\BadgeType;
use SpeedPuzzling\Web\Value\CountryCode;
use SpeedPuzzling\Web\Value\HeldAtLaunch;

/**
 * Holders directory (§1.7, layout: docs/features/xp-levels/ux-polish/design-spec.md §1): holder LISTS show members with public profiles only
 * (public badge display is a membership perk); private profiles, XP-opted-out players and players hiding their
 * achievements never appear in lists; holder COUNTS include everyone.
 *
 * The lists are global rankings, so a private profile stays out of them for everybody - also for viewers on its
 * allow list (docs/features/private-profile-allow-list.md); players the viewer blocks are left out too.
 */
readonly class GetAchievementHolders
{
    public const int LISTED_PER_TIER = 30;

    private const string LISTED_ELIGIBILITY = <<<SQL
  p.is_private = false
  AND p.experience_system_opted_out = false
  AND p.achievements_opted_out = false
  AND (
    (m.ends_at IS NULL AND m.billing_period_ends_at IS NOT NULL)
    OR GREATEST(
        COALESCE(m.ends_at, m.billing_period_ends_at, '1970-01-01'::timestamp),
        COALESCE(m.granted_until, '1970-01-01'::timestamp)
    ) > CAST(:now AS timestamp)
  )
SQL;

    public function __construct(
        private Connection $database,
        private HiddenPlayers $hiddenPlayers,
        private ClockInterface $clock,
    ) {
    }

    /**
     * Tier sections Diamond → Bronze (docs/features/xp-levels/ux-polish/design-spec.md §1 "Holders page", §8.2): the
     * newest LISTED_PER_TIER listed holders of each tier, newest first - the holders at launch carry the backfill's
     * timestamp, so they come last and the page shows "at launch" instead of their date (§8.1).
     *
     * A player is listed once, under the highest tier they hold (§8.2, Jan 2026-10-12): earning a tier fills in the
     * lower ones, so a Diamond holder used to stand in all five lists. A section's counts are the players at that
     * tier too, so its "Show all N" and "+N not listed" add up; allCount and earnedCount keep everybody who earned the
     * tier (the medal's "of 82", and telling "everyone went further" from "nobody yet").
     *
     * Each row knows whether it is the tier's very first holder among ALL holders (the "First to earn" trophy, given
     * only when that first earn came after launch): serial 1 of the medal serial rule of §2 (ORDER BY earned_at, id
     * within the type and tier), picked before anybody is left out of the list.
     *
     * Two statements whatever the number of holders: the listed rows, and per tier the counts (every country + the
     * filtered country).
     *
     * One tier can be expanded (the "Show more" / "Show all" of its section, Jan 2026-10-11): it lists up to
     * $expandedLimit holders instead of LISTED_PER_TIER - still the same one statement.
     *
     * @param HeldAtLaunch $heldAtLaunch LaunchStampWindow::current() - the tiers still stamped by the launch backfill
     * @return list<AchievementTierHolders>
     */
    public function forType(
        BadgeType $type,
        null|string $country,
        HeldAtLaunch $heldAtLaunch,
        null|BadgeTier $expandedTier = null,
        int $expandedLimit = self::LISTED_PER_TIER,
    ): array {
        $tiers = implode(', ', array_map(static fn (BadgeTier $tier): string => '(' . $tier->value . ')', BadgeTier::cases()));

        // Per tier one newest-first walk of the (type, tier, earned_at, id) index that stops at the section's limit,
        // instead of numbering every holder of the type twice with window functions. "Listed under the highest tier"
        // = no badge of a higher tier; "First to earn" = the tier's first badge of ALL holders, picked before anybody
        // is left out. The viewer's blocks are inside the walk, so the positions close up.
        $listedSql = <<<SQL
WITH first_holder AS (
    SELECT DISTINCT ON (b.tier) b.tier, b.id
    FROM badge b
    WHERE b.type = :type
      AND b.tier IS NOT NULL
    ORDER BY b.tier, b.earned_at ASC, b.id ASC
)
SELECT
    listed.tier,
    listed.earned_at,
    CASE WHEN listed.id = first_holder.id THEN 1 ELSE 0 END AS is_first,
    listed.player_id,
    listed.player_name,
    listed.code,
    listed.country,
    listed.avatar
FROM (VALUES {$tiers}) AS tiers(tier)
CROSS JOIN LATERAL (
    SELECT
        b.id,
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
      AND b.tier = tiers.tier
      AND NOT EXISTS (
          SELECT 1 FROM badge higher
          WHERE higher.player_id = b.player_id AND higher.type = b.type AND higher.tier > b.tier
      )
      AND (CAST(:country AS TEXT) IS NULL OR p.country = :country)
      AND {$this->listedEligibility()}
    ORDER BY b.earned_at DESC, b.id DESC
    LIMIT CASE WHEN tiers.tier = CAST(:expandedTier AS INT) THEN CAST(:expandedLimit AS INT) ELSE CAST(:limit AS INT) END
) listed
LEFT JOIN first_holder ON first_holder.tier = listed.tier
ORDER BY listed.tier DESC, listed.earned_at DESC, listed.id DESC
SQL;

        /** @var list<array{tier: int|string, earned_at: string, is_first: int|string, player_id: string, player_name: null|string, code: string, country: null|string, avatar: null|string}> $listedRows */
        $listedRows = $this->database->executeQuery($listedSql, [
            'type' => $type->value,
            'country' => $country,
            'limit' => self::LISTED_PER_TIER,
            'expandedTier' => $expandedTier->value ?? 0,
            'expandedLimit' => max(self::LISTED_PER_TIER, $expandedLimit),
            'now' => $this->now(),
        ])->fetchAllAssociative();

        $countsSql = <<<SQL
SELECT
    b.tier,
    COUNT(*) AS all_count,
    COUNT(*) FILTER (WHERE CAST(:country AS TEXT) IS NULL OR p.country = :country) AS earned_count,
    COUNT(*) FILTER (WHERE b.tier = b.highest_tier AND (CAST(:country AS TEXT) IS NULL OR p.country = :country)) AS holders_count,
    COUNT(*) FILTER (WHERE b.tier = b.highest_tier AND (CAST(:country AS TEXT) IS NULL OR p.country = :country) AND {$this->listedEligibility()}) AS listed_count
FROM (
    SELECT tier, player_id, MAX(tier) OVER (PARTITION BY player_id) AS highest_tier
    FROM badge
    WHERE type = :type
      AND tier IS NOT NULL
) b
JOIN player p ON p.id = b.player_id
LEFT JOIN membership m ON m.player_id = p.id
GROUP BY b.tier
SQL;

        /** @var list<array{tier: int|string, all_count: int|string, earned_count: int|string, holders_count: int|string, listed_count: int|string}> $countRows */
        $countRows = $this->database->executeQuery($countsSql, [
            'type' => $type->value,
            'country' => $country,
            'now' => $this->now(),
        ])->fetchAllAssociative();

        $countsByTier = [];
        foreach ($countRows as $row) {
            $countsByTier[(int) $row['tier']] = [
                'all' => (int) $row['all_count'],
                'earned' => (int) $row['earned_count'],
                'filtered' => (int) $row['holders_count'],
                'listed' => (int) $row['listed_count'],
            ];
        }

        $holdersByTier = [];
        foreach ($listedRows as $row) {
            $earnedAt = new DateTimeImmutable($row['earned_at']);
            $held = $heldAtLaunch->holds($earnedAt);

            $holdersByTier[(int) $row['tier']][] = new AchievementHolder(
                playerId: $row['player_id'],
                playerName: $row['player_name'],
                playerCode: $row['code'],
                countryCode: CountryCode::fromCode($row['country']),
                avatar: $row['avatar'],
                earnedAt: $earnedAt,
                heldAtLaunch: $held,
                firstToEarn: (int) $row['is_first'] === 1 && $held === false,
            );
        }

        $sections = [];

        foreach (array_reverse(BadgeTier::cases()) as $tier) {
            $sections[] = new AchievementTierHolders(
                tier: $tier,
                holders: $holdersByTier[$tier->value] ?? [],
                totalCount: $countsByTier[$tier->value]['filtered'] ?? 0,
                allCount: $countsByTier[$tier->value]['all'] ?? 0,
                listedCount: $countsByTier[$tier->value]['listed'] ?? 0,
                earnedCount: $countsByTier[$tier->value]['earned'] ?? 0,
            );
        }

        return $sections;
    }

    /**
     * Countries available for the filter — from listed holders only.
     *
     * @return list<string>
     */
    public function countries(BadgeType $type): array
    {
        // From membership: the listed eligibility needs a membership row (without one both of its branches are
        // false), and there are far fewer members than badges of a type
        $sql = <<<SQL
SELECT DISTINCT p.country
FROM membership m
JOIN player p ON p.id = m.player_id
WHERE p.country IS NOT NULL
  AND {$this->listedEligibility()}
  AND EXISTS (
      SELECT 1 FROM badge b
      WHERE b.player_id = p.id AND b.type = :type AND b.tier IS NOT NULL
  )
ORDER BY p.country
SQL;

        /** @var list<string> $countries */
        $countries = $this->database->executeQuery($sql, ['type' => $type->value, 'now' => $this->now()])->fetchFirstColumn();

        return $countries;
    }

    /**
     * Players the viewer blocks are left out of every list (docs/features/player-blocklist.md) - inside the ranked
     * subquery, so the positions close up. The counts are aggregates and keep everybody.
     */
    private function now(): string
    {
        return $this->clock->now()->format('Y-m-d H:i:s');
    }

    private function listedEligibility(): string
    {
        return self::LISTED_ELIGIBILITY . $this->hiddenPlayers->sqlExclude('p.id');
    }
}
