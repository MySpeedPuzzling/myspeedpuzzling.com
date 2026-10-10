<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services;

use SpeedPuzzling\Web\Results\AchievementCatalogueRow;
use SpeedPuzzling\Web\Results\BadgeCatalogGroup;
use SpeedPuzzling\Web\Value\AchievementCatalogueFilter;
use SpeedPuzzling\Web\Value\BadgeTier;

/**
 * The rows of an achievements list - the catalogue (badges_overview) and a player's achievements page
 * (player_achievements) - and their "Next up" / "Earned" / "Rarest" orders. Pure PHP over GetBadgeCatalog's groups.
 */
final class AchievementCatalogueRows
{
    /**
     * @param list<BadgeCatalogGroup> $catalog
     * @param array<string, array<int, float>> $rarity AchievementRarityProvider::percentages()
     * @param bool $personal the groups belong to a member whose tiers may be shown (else rows are the bare catalogue)
     * @param bool $revealFlow the owner looks at their own list: unrevealed tiers become "?" medallions
     * @return array<string, list<AchievementCatalogueRow>> filter value → rows in that filter's order
     */
    public static function build(array $catalog, array $rarity, bool $personal, bool $revealFlow = false): array
    {
        $rows = [];

        foreach ($catalog as $group) {
            $earnedTier = $personal ? $group->highestEarnedTier() : null;
            $progress = $personal ? $group->progressToNext : null;
            $focusTier = $progress !== null ? $progress->nextTier : ($earnedTier ?? BadgeTier::Bronze);

            $rows[] = new AchievementCatalogueRow(
                type: $group->type,
                displayTier: $earnedTier ?? BadgeTier::Bronze,
                earnedTier: $earnedTier,
                progress: $progress,
                rarityPercent: $rarity[$group->type->value][$focusTier->value] ?? 0.0,
                unrevealed: $revealFlow && $personal && $group->highestEarnedIsUnrevealed(),
            );
        }

        $nextUp = array_values(array_filter(
            $rows,
            static fn (AchievementCatalogueRow $row): bool => $row->progress !== null && $row->progress->percent > 0,
        ));
        usort($nextUp, static fn (AchievementCatalogueRow $a, AchievementCatalogueRow $b): int => ($b->progress->percent ?? 0) <=> ($a->progress->percent ?? 0));

        $earned = array_values(array_filter(
            $rows,
            static fn (AchievementCatalogueRow $row): bool => $row->earnedTier !== null,
        ));
        usort($earned, static fn (AchievementCatalogueRow $a, AchievementCatalogueRow $b): int => ($b->earnedTier->value ?? 0) <=> ($a->earnedTier->value ?? 0));

        $rarest = $rows;
        usort($rarest, static fn (AchievementCatalogueRow $a, AchievementCatalogueRow $b): int => $a->rarityPercent <=> $b->rarityPercent);

        return [
            AchievementCatalogueFilter::NextUp->value => $nextUp,
            AchievementCatalogueFilter::Earned->value => $earned,
            AchievementCatalogueFilter::All->value => $rows,
            AchievementCatalogueFilter::Rarest->value => $rarest,
        ];
    }
}
