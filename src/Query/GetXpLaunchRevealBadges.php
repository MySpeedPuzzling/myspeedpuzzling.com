<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Results\XpLaunchRevealBadge;
use SpeedPuzzling\Web\Value\BadgeTier;
use SpeedPuzzling\Web\Value\BadgeType;

/**
 * Every achievement of a player for the XP launch reveal, in crescendo order: one statement over all earned tier rows,
 * folded per achievement into its highest tier and the XP its tiers brought.
 */
readonly final class GetXpLaunchRevealBadges
{
    public function __construct(
        private Connection $database,
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
        $sql = <<<SQL
SELECT id, type, tier, revealed_at
FROM badge
WHERE player_id = :playerId
SQL;

        /** @var list<array{id: string, type: string, tier: null|int|string, revealed_at: null|string}> $rows */
        $rows = $this->database
            ->executeQuery($sql, ['playerId' => $playerId])
            ->fetchAllAssociative();

        /** @var array<string, array{type: BadgeType, tier: null|BadgeTier, xp: int, id: string, revealed: bool}> $achievements */
        $achievements = [];

        foreach ($rows as $row) {
            // A type or tier the code no longer knows must not break the page
            $type = BadgeType::tryFrom($row['type']);
            $tier = $row['tier'] === null ? null : BadgeTier::tryFrom((int) $row['tier']);

            if ($type === null || ($row['tier'] !== null && $tier === null)) {
                continue;
            }

            $xp = $tier?->points() ?? BadgeTier::SINGLE_TIER_POINTS;
            $current = $achievements[$type->value] ?? null;

            if ($current === null || $tier?->value > ($current['tier']->value ?? 0)) {
                // the highest tier so far stands for the achievement (its row is the one the reveal endpoint flips)
                $achievements[$type->value] = [
                    'type' => $type,
                    'tier' => $tier,
                    'xp' => ($current['xp'] ?? 0) + $xp,
                    'id' => $row['id'],
                    'revealed' => $row['revealed_at'] !== null,
                ];
            } else {
                $current['xp'] += $xp;
                $achievements[$type->value] = $current;
            }
        }

        $badges = array_map(
            static fn (array $achievement): XpLaunchRevealBadge => new XpLaunchRevealBadge(
                type: $achievement['type'],
                tier: $achievement['tier'],
                xp: $achievement['xp'],
                badgeId: $achievement['id'],
                revealed: $achievement['revealed'],
            ),
            array_values($achievements),
        );

        return self::crescendo($badges);
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
