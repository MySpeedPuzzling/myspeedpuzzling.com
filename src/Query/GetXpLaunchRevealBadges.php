<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Results\BadgeProgress;
use SpeedPuzzling\Web\Results\BadgeResult;
use SpeedPuzzling\Web\Results\XpLaunchRevealBadge;
use SpeedPuzzling\Web\Services\AchievementRarityProvider;
use SpeedPuzzling\Web\Value\AchievementRarityBand;
use SpeedPuzzling\Web\Value\BadgeTier;
use SpeedPuzzling\Web\Value\BadgeType;
use SpeedPuzzling\Web\Value\XpLaunchRevealHighlight;

/**
 * Every achievement of a player for the XP launch reveal, in crescendo order: one statement over all earned tier rows,
 * folded per achievement into its highest tier and the Achievement Points its tiers brought.
 */
readonly final class GetXpLaunchRevealBadges
{
    /** "Within reach": the next tier is at least this far done */
    public const int WITHIN_REACH_PERCENT = 75;

    public function __construct(
        private Connection $database,
        private GetBadgeCatalog $getBadgeCatalog,
        private AchievementRarityProvider $achievementRarityProvider,
    ) {
    }

    /**
     * Crescendo: lower tiers first, the best last. A single-tier achievement (Early Adopter) comes right before the
     * player's top tier - special, but the show still ends on the best tier earned. Within a tier, catalogue order.
     *
     * @return list<XpLaunchRevealBadge>
     */
    public function forPlayer(string $playerId): array
    {
        return self::crescendo(self::fold($this->rows($playerId)));
    }

    /**
     * The deck's cards with the facts the achievements pages show (design-spec §9, the launch reveal): the progress to
     * the next tier (GetBadgeCatalog - the same rule as the shelf and the sheet, fed with the rows read here, so only
     * the player's stats snapshot is added), how many puzzlers hold the tier (AchievementRarityProvider, cached) and at
     * most one highlight per card.
     *
     * @return list<XpLaunchRevealBadge>
     */
    public function withFacts(string $playerId): array
    {
        $rows = $this->rows($playerId);
        $badges = self::fold($rows);

        if ($badges === []) {
            return [];
        }

        /** @var array<string, null|BadgeProgress> $progress */
        $progress = [];

        foreach ($this->getBadgeCatalog->forPlayer($playerId, self::badgeResults($rows)) as $group) {
            $progress[$group->type->value] = $group->progressToNext;
        }

        $percentages = $this->achievementRarityProvider->percentages();
        $holders = $this->achievementRarityProvider->holders();

        $withFacts = [];

        foreach ($badges as $badge) {
            $tierKey = $badge->tier->value ?? 0;
            $next = $badge->tier === null || $badge->tier === BadgeTier::Diamond ? null : ($progress[$badge->type->value] ?? null);

            $withFacts[] = new XpLaunchRevealBadge(
                type: $badge->type,
                tier: $badge->tier,
                points: $badge->points,
                badgeId: $badge->badgeId,
                revealed: $badge->revealed,
                // the catalogue's next tier is the one above the highest held (the evaluator fills the gaps)
                progress: $next !== null && $next->nextTier->value > ($badge->tier->value ?? 0) ? $next : null,
                holders: $holders[$badge->type->value][$tierKey] ?? 0,
                sharePercent: $percentages[$badge->type->value][$tierKey] ?? 0.0,
            );
        }

        return self::crescendo(self::highlighted($withFacts));
    }

    /**
     * At most one highlight per card, the first that is true: Early Adopter thanks, Diamond is the top tier, the
     * rarest held tier of the deck (one card, never a common tier, only when there are several to compare), the next
     * tier within reach.
     *
     * @param list<XpLaunchRevealBadge> $badges
     * @return list<XpLaunchRevealBadge>
     */
    private static function highlighted(array $badges): array
    {
        $rarest = self::rarest($badges);

        return array_map(static function (XpLaunchRevealBadge $badge) use ($rarest): XpLaunchRevealBadge {
            $highlight = match (true) {
                $badge->tier === null => XpLaunchRevealHighlight::Thanks,
                $badge->isTopTier() => XpLaunchRevealHighlight::TopTier,
                $badge === $rarest => XpLaunchRevealHighlight::Rarest,
                $badge->progress !== null && $badge->progress->percent >= self::WITHIN_REACH_PERCENT => XpLaunchRevealHighlight::WithinReach,
                default => null,
            };

            return new XpLaunchRevealBadge(
                type: $badge->type,
                tier: $badge->tier,
                points: $badge->points,
                badgeId: $badge->badgeId,
                revealed: $badge->revealed,
                progress: $badge->progress,
                holders: $badge->holders,
                sharePercent: $badge->sharePercent,
                highlight: $highlight,
            );
        }, $badges);
    }

    /**
     * The held tier with the fewest holders (on a tie the higher tier) - only among several tiered cards, and never a
     * common one.
     *
     * @param list<XpLaunchRevealBadge> $badges
     */
    private static function rarest(array $badges): null|XpLaunchRevealBadge
    {
        $tiered = array_values(array_filter(
            $badges,
            static fn (XpLaunchRevealBadge $badge): bool => $badge->tier !== null && $badge->holders > 0,
        ));

        if (count($tiered) < 2) {
            return null;
        }

        usort($tiered, static fn (XpLaunchRevealBadge $a, XpLaunchRevealBadge $b): int => [$a->sharePercent, -($a->tier->value ?? 0)] <=> [$b->sharePercent, -($b->tier->value ?? 0)]);

        return $tiered[0]->rarityBand() === AchievementRarityBand::Common ? null : $tiered[0];
    }

    /**
     * @return list<array{id: string, type: BadgeType, tier: null|BadgeTier, earned_at: string, revealed_at: null|string}>
     */
    private function rows(string $playerId): array
    {
        $sql = <<<SQL
SELECT id, type, tier, earned_at, revealed_at
FROM badge
WHERE player_id = :playerId
SQL;

        /** @var list<array{id: string, type: string, tier: null|int|string, earned_at: string, revealed_at: null|string}> $rows */
        $rows = $this->database
            ->executeQuery($sql, ['playerId' => $playerId])
            ->fetchAllAssociative();

        $known = [];

        foreach ($rows as $row) {
            // A type or tier the code no longer knows must not break the page
            $type = BadgeType::tryFrom($row['type']);
            $tier = $row['tier'] === null ? null : BadgeTier::tryFrom((int) $row['tier']);

            if ($type === null || ($row['tier'] !== null && $tier === null)) {
                continue;
            }

            $known[] = ['id' => $row['id'], 'type' => $type, 'tier' => $tier, 'earned_at' => $row['earned_at'], 'revealed_at' => $row['revealed_at']];
        }

        return $known;
    }

    /**
     * @param list<array{id: string, type: BadgeType, tier: null|BadgeTier, earned_at: string, revealed_at: null|string}> $rows
     * @return list<XpLaunchRevealBadge>
     */
    private static function fold(array $rows): array
    {
        /** @var array<string, array{type: BadgeType, tier: null|BadgeTier, points: int, id: string, revealed: bool}> $achievements */
        $achievements = [];

        foreach ($rows as $row) {
            $type = $row['type'];
            $tier = $row['tier'];
            $points = $tier?->points() ?? BadgeTier::SINGLE_TIER_POINTS;
            $current = $achievements[$type->value] ?? null;

            if ($current === null || $tier?->value > ($current['tier']->value ?? 0)) {
                // the highest tier so far stands for the achievement (its row is the one the reveal endpoint flips)
                $achievements[$type->value] = [
                    'type' => $type,
                    'tier' => $tier,
                    'points' => ($current['points'] ?? 0) + $points,
                    'id' => $row['id'],
                    'revealed' => $row['revealed_at'] !== null,
                ];
            } else {
                $current['points'] += $points;
                $achievements[$type->value] = $current;
            }
        }

        return array_map(
            static fn (array $achievement): XpLaunchRevealBadge => new XpLaunchRevealBadge(
                type: $achievement['type'],
                tier: $achievement['tier'],
                points: $achievement['points'],
                badgeId: $achievement['id'],
                revealed: $achievement['revealed'],
            ),
            array_values($achievements),
        );
    }

    /**
     * The rows as GetBadges::allEarnedTiers() would return them, so the catalogue does not read them a second time.
     *
     * @param list<array{id: string, type: BadgeType, tier: null|BadgeTier, earned_at: string, revealed_at: null|string}> $rows
     * @return list<BadgeResult>
     */
    private static function badgeResults(array $rows): array
    {
        return array_map(
            static fn (array $row): BadgeResult => new BadgeResult(
                type: $row['type'],
                tier: $row['tier'],
                earnedAt: new DateTimeImmutable($row['earned_at']),
                id: $row['id'],
                revealedAt: $row['revealed_at'] === null ? null : new DateTimeImmutable($row['revealed_at']),
            ),
            $rows,
        );
    }

    /**
     * @param list<XpLaunchRevealBadge> $badges
     * @return list<XpLaunchRevealBadge>
     */
    private static function crescendo(array $badges): array
    {
        $topTier = 0;

        foreach ($badges as $badge) {
            $topTier = max($topTier, $badge->tier->value ?? 0);
        }

        $catalogueOrder = array_flip(array_map(static fn (BadgeType $type): string => $type->value, BadgeType::cases()));

        usort($badges, static fn (XpLaunchRevealBadge $a, XpLaunchRevealBadge $b): int => [
            $a->tier->value ?? $topTier - 0.5,
            $catalogueOrder[$a->type->value],
        ] <=> [
            $b->tier->value ?? $topTier - 0.5,
            $catalogueOrder[$b->type->value],
        ]);

        return $badges;
    }
}
