<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use SpeedPuzzling\Web\BadgeConditions\BadgeConditionInterface;
use SpeedPuzzling\Web\Results\BadgeCatalogEntry;
use SpeedPuzzling\Web\Results\BadgeCatalogGroup;
use SpeedPuzzling\Web\Results\PlayerStatsSnapshot;
use SpeedPuzzling\Web\Value\BadgeTier;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

readonly final class GetBadgeCatalog
{
    /**
     * @param iterable<BadgeConditionInterface> $conditions
     */
    public function __construct(
        #[AutowireIterator('badge.condition')]
        private iterable $conditions,
        private GetPlayerStatsSnapshot $getPlayerStatsSnapshot,
        private GetBadges $getBadges,
    ) {
    }

    /**
     * @return list<BadgeCatalogGroup>
     */
    public function forPlayer(null|string $playerId): array
    {
        $earnedMap = [];
        $snapshot = null;

        if ($playerId !== null) {
            $snapshot = $this->getPlayerStatsSnapshot->forPlayer($playerId);

            foreach ($this->getBadges->forPlayer($playerId) as $badge) {
                if ($badge->tier === null) {
                    continue;
                }
                $earnedMap[$badge->type->value][$badge->tier->value] = $badge->earnedAt;
            }
        }

        $groups = [];

        foreach ($this->conditions as $condition) {
            $type = $condition->badgeType();
            $typeEarned = $earnedMap[$type->value] ?? [];
            $tiers = [];
            $highestEarned = null;

            foreach (BadgeTier::cases() as $tier) {
                $earned = isset($typeEarned[$tier->value]);
                if ($earned && ($highestEarned === null || $tier->value > $highestEarned->value)) {
                    $highestEarned = $tier;
                }

                $tiers[] = new BadgeCatalogEntry(
                    type: $type,
                    tier: $tier,
                    requirementTranslationKey: 'badges.requirement.' . $type->value . '_' . $tier->value,
                    earned: $earned,
                    earnedAt: $typeEarned[$tier->value] ?? null,
                );
            }

            $progress = null;
            if ($snapshot instanceof PlayerStatsSnapshot) {
                $progress = $condition->progressToNextTier($snapshot, $highestEarned);
            }

            $groups[] = new BadgeCatalogGroup(
                type: $type,
                tiers: $tiers,
                progressToNext: $progress,
            );
        }

        return $groups;
    }
}
