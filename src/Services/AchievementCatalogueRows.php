<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services;

use DateTimeImmutable;
use SpeedPuzzling\Web\Results\AchievementCatalogueLists;
use SpeedPuzzling\Web\Results\AchievementCatalogueRow;
use SpeedPuzzling\Web\Results\BadgeCatalogGroup;
use SpeedPuzzling\Web\Value\AchievementCatalogueFilter;
use SpeedPuzzling\Web\Value\BadgeTier;
use SpeedPuzzling\Web\Value\BadgeType;
use SpeedPuzzling\Web\Value\HeldAtLaunch;

/**
 * The cells of an achievements shelf - the catalogue (badges_overview) and a player's achievements page
 * (player_achievements) - their filters' orders, the closest next steps and the summary numbers
 * (docs/features/xp-levels/ux-polish/design-spec.md §1). Pure PHP over GetBadgeCatalog's groups: no query of its own.
 */
final class AchievementCatalogueRows
{
    public const int CLOSEST_STEPS = 3;

    /**
     * @param list<BadgeCatalogGroup> $catalog
     * @param array<string, array<int, float>> $rarity AchievementRarityProvider::percentages()
     * @param bool $personal the groups belong to a member whose tiers may be shown (else rows are the bare catalogue)
     * @param bool $revealFlow the owner looks at their own list: unrevealed tiers become "?" seals
     * @param null|DateTimeImmutable $newSince the owner looks at their own list: tiers earned since then get "New" -
     *                                       never one held at launch (design-spec §8.1)
     * @param null|HeldAtLaunch $heldAtLaunch LaunchStampWindow::current() - the tiers still stamped by the launch backfill;
     *                                       none given = every tier is held at launch (before the launch)
     * @param list<BadgeType> $hideProgressOf types whose progress the visitor may not see (an owner's streak opt-out)
     */
    public static function build(
        array $catalog,
        array $rarity,
        bool $personal,
        bool $revealFlow = false,
        null|DateTimeImmutable $newSince = null,
        null|HeldAtLaunch $heldAtLaunch = null,
        array $hideProgressOf = [],
    ): AchievementCatalogueLists {
        $rows = [];
        $heldAtLaunch ??= new HeldAtLaunch(null, null);

        foreach ($catalog as $group) {
            $earnedTier = $personal ? $group->highestEarnedTier() : null;
            $progress = $personal && in_array($group->type, $hideProgressOf, true) === false ? $group->progressToNext : null;
            $earnedAt = $earnedTier !== null ? self::earnedAt($group, $earnedTier) : null;
            $held = $earnedAt !== null && $heldAtLaunch->holds($earnedAt);
            $focusTier = $progress !== null ? $progress->nextTier : ($earnedTier ?? BadgeTier::Bronze);
            $unrevealed = $revealFlow && $personal && $group->highestEarnedIsUnrevealed();

            $rows[] = new AchievementCatalogueRow(
                type: $group->type,
                displayTier: $earnedTier ?? BadgeTier::Bronze,
                earnedTier: $earnedTier,
                progress: $progress,
                rarityPercent: $rarity[$group->type->value][$focusTier->value] ?? 0.0,
                unrevealed: $unrevealed,
                tiersEarned: $personal ? $group->earnedCount() : 0,
                newlyEarned: $unrevealed === false && $earnedAt !== null && $held === false && $newSince !== null && $earnedAt >= $newSince,
                earnedRarityPercent: $earnedTier !== null ? ($rarity[$group->type->value][$earnedTier->value] ?? 0.0) : 100.0,
                earnedAt: $earnedAt,
                heldAtLaunch: $held,
            );
        }

        // All: unrevealed tiers first (they are an action), then the earned types - highest tier first, rarer first
        // within a tier - then the rest by progress, highest first. Without personal data the catalogue order stays.
        $all = $rows;
        usort($all, self::compareForAll(...));

        $nextUp = array_values(array_filter(
            $all,
            static fn (AchievementCatalogueRow $row): bool => $row->progress !== null && $row->progress->percent > 0,
        ));
        usort($nextUp, static fn (AchievementCatalogueRow $a, AchievementCatalogueRow $b): int => ($b->progress->percent ?? 0) <=> ($a->progress->percent ?? 0));

        // Earned: newest first (design-spec §8.6); the tiers held at launch share the backfill's timestamp, so they
        // come after the dated ones in the order of All (best tier first)
        $earned = array_values(array_filter(
            $all,
            static fn (AchievementCatalogueRow $row): bool => $row->earnedTier !== null,
        ));
        usort($earned, static fn (AchievementCatalogueRow $a, AchievementCatalogueRow $b): int => self::newestFirstKey($a) <=> self::newestFirstKey($b));

        // Rarest first: what the player holds, the highest tier first (Diamond, Platinum, ...) and the rarer one first
        // within a tier - a common Diamond still before a rare Silver (Jan 2026-10-12: "Early Adopter, then Diamond") -
        // then what they do not hold yet, by how rare its Bronze is (Jan 2026-10-11)
        $rarest = $rows;
        usort($rarest, static fn (AchievementCatalogueRow $a, AchievementCatalogueRow $b): int => self::rarestKey($a) <=> self::rarestKey($b));

        // A step whose current tier is still waiting for its reveal would give the surprise away ("→ Platinum")
        $closest = array_slice(array_values(array_filter(
            $nextUp,
            static fn (AchievementCatalogueRow $row): bool => $row->unrevealed === false,
        )), 0, self::CLOSEST_STEPS);

        $withProgress = array_values(array_filter(
            $all,
            static fn (AchievementCatalogueRow $row): bool => $row->earnedTier === null && $row->progress !== null,
        ));
        usort($withProgress, static fn (AchievementCatalogueRow $a, AchievementCatalogueRow $b): int => ($b->progress->percent ?? 0) <=> ($a->progress->percent ?? 0));

        return new AchievementCatalogueLists(
            lists: [
                AchievementCatalogueFilter::All->value => $all,
                AchievementCatalogueFilter::Earned->value => $earned,
                AchievementCatalogueFilter::NextUp->value => $nextUp,
                AchievementCatalogueFilter::Rarest->value => $rarest,
            ],
            closest: $closest,
            nearestFirst: $withProgress[0] ?? null,
            earnedTypes: count($earned),
            totalTypes: count($rows),
            earnedTiers: array_sum(array_map(static fn (AchievementCatalogueRow $row): int => $row->tiersEarned, $rows)),
            totalTiers: count($rows) * count(BadgeTier::cases()),
        );
    }

    /**
     * @return array{int, int, float}
     */
    private static function rarestKey(AchievementCatalogueRow $row): array
    {
        return $row->earnedTier !== null
            ? [0, -$row->earnedTier->value, $row->earnedRarityPercent]
            : [1, 0, $row->rarityPercent];
    }

    private static function compareForAll(AchievementCatalogueRow $a, AchievementCatalogueRow $b): int
    {
        return self::allSortKey($a) <=> self::allSortKey($b);
    }

    /**
     * @return array{int, int, float, int}
     */
    private static function allSortKey(AchievementCatalogueRow $row): array
    {
        if ($row->unrevealed) {
            return [0, 0, 0.0, 0];
        }

        if ($row->earnedTier !== null) {
            return [1, -$row->earnedTier->value, $row->earnedRarityPercent, 0];
        }

        return [2, 0, 0.0, -($row->progress->percent ?? -1)];
    }

    /**
     * @return array{int, int}
     */
    private static function newestFirstKey(AchievementCatalogueRow $row): array
    {
        if ($row->heldAtLaunch || $row->earnedAt === null) {
            return [1, 0];
        }

        return [0, -$row->earnedAt->getTimestamp()];
    }

    private static function earnedAt(BadgeCatalogGroup $group, BadgeTier $tier): null|DateTimeImmutable
    {
        foreach ($group->tiers as $entry) {
            if ($entry->tier === $tier && $entry->earned) {
                return $entry->earnedAt;
            }
        }

        return null;
    }
}
