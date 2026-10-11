<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Results\AchievementCatalogueLists;
use SpeedPuzzling\Web\Results\AchievementCatalogueRow;
use SpeedPuzzling\Web\Results\BadgeCatalogEntry;
use SpeedPuzzling\Web\Results\BadgeCatalogGroup;
use SpeedPuzzling\Web\Results\BadgeProgress;
use SpeedPuzzling\Web\Services\AchievementCatalogueRows;
use SpeedPuzzling\Web\Value\AchievementCatalogueFilter;
use SpeedPuzzling\Web\Value\BadgeTier;
use SpeedPuzzling\Web\Value\BadgeType;

/**
 * The orders, closest next steps and summary numbers of the achievements shelf
 * (docs/features/xp-levels/ux-polish/design-spec.md §1).
 */
final class AchievementCatalogueRowsTest extends TestCase
{
    public function testAllStartsWithTheRevealThenTheBestTiersRarerFirstThenTheProgress(): void
    {
        $catalog = [
            self::group(BadgeType::Streak, earnedUpTo: null, progress: 40),
            self::group(BadgeType::PuzzlesSolved, earnedUpTo: BadgeTier::Gold, progress: 10),
            self::group(BadgeType::FirstTry, earnedUpTo: BadgeTier::Silver, progress: 80),
            self::group(BadgeType::Unboxed, earnedUpTo: null, progress: null),
            self::group(BadgeType::TeamPlayer, earnedUpTo: BadgeTier::Bronze, progress: 5, unrevealed: true),
            self::group(BadgeType::Marathoner, earnedUpTo: BadgeTier::Silver, progress: 20),
            self::group(BadgeType::Photographer, earnedUpTo: null, progress: 90),
        ];
        $rarity = [
            BadgeType::FirstTry->value => [2 => 30.0],
            BadgeType::Marathoner->value => [2 => 4.0],
        ];

        $lists = AchievementCatalogueRows::build($catalog, $rarity, personal: true, revealFlow: true);

        self::assertSame([
            BadgeType::TeamPlayer, // unrevealed: an action
            BadgeType::PuzzlesSolved, // Gold
            BadgeType::Marathoner, // Silver held by 4 %
            BadgeType::FirstTry, // Silver held by 30 %
            BadgeType::Photographer, // 90 %
            BadgeType::Streak, // 40 %
            BadgeType::Unboxed, // nothing to measure
        ], self::types($lists->rows(AchievementCatalogueFilter::All)));

        self::assertSame(
            [BadgeType::TeamPlayer, BadgeType::PuzzlesSolved, BadgeType::Marathoner, BadgeType::FirstTry],
            self::types($lists->rows(AchievementCatalogueFilter::Earned)),
        );
    }

    public function testClosestNextStepsAreTheThreeHighestProgressesWithoutUnrevealedOrCompleteTypes(): void
    {
        $catalog = [
            self::group(BadgeType::Streak, earnedUpTo: null, progress: 40),
            self::group(BadgeType::PuzzlesSolved, earnedUpTo: BadgeTier::Diamond, progress: null),
            self::group(BadgeType::FirstTry, earnedUpTo: BadgeTier::Silver, progress: 80),
            self::group(BadgeType::TeamPlayer, earnedUpTo: BadgeTier::Bronze, progress: 99, unrevealed: true),
            self::group(BadgeType::Marathoner, earnedUpTo: null, progress: 20),
            self::group(BadgeType::Photographer, earnedUpTo: null, progress: 90),
        ];

        $lists = AchievementCatalogueRows::build($catalog, [], personal: true, revealFlow: true);

        self::assertSame([BadgeType::Photographer, BadgeType::FirstTry, BadgeType::Streak], self::types($lists->closest));

        // A visitor never sees the reveal flow: the same tier is a normal step for them
        $visitor = AchievementCatalogueRows::build($catalog, [], personal: true);
        self::assertSame(BadgeType::TeamPlayer, $visitor->closest[0]->type);
    }

    public function testSummaryCountsTypesAndTiersFromTheCatalogue(): void
    {
        $catalog = [
            self::group(BadgeType::Streak, earnedUpTo: null, progress: 40),
            self::group(BadgeType::PuzzlesSolved, earnedUpTo: BadgeTier::Diamond, progress: null),
            self::group(BadgeType::FirstTry, earnedUpTo: BadgeTier::Silver, progress: 80),
        ];

        $lists = AchievementCatalogueRows::build($catalog, [], personal: true);

        self::assertSame(2, $lists->earnedTypes);
        self::assertSame(3, $lists->totalTypes);
        self::assertSame(7, $lists->earnedTiers);
        self::assertSame(15, $lists->totalTiers);
    }

    public function testTheNewDotIsForTiersEarnedInTheLastDaysAndAfterLaunch(): void
    {
        $catalog = [
            self::group(BadgeType::Streak, earnedUpTo: BadgeTier::Gold, progress: 10, earnedAt: '2026-10-08 09:00:00'),
            self::group(BadgeType::FirstTry, earnedUpTo: BadgeTier::Silver, progress: 10, earnedAt: '2026-09-20 09:00:00'),
            // Within the last 7 days, but stamped by the launch backfill
            self::group(BadgeType::Unboxed, earnedUpTo: BadgeTier::Bronze, progress: 10, earnedAt: '2026-10-05 09:00:00'),
        ];
        $newSince = new DateTimeImmutable('2026-10-04 12:00:00');
        $launchAt = new DateTimeImmutable('2026-10-05 09:17:00');

        $owner = AchievementCatalogueRows::build($catalog, [], personal: true, revealFlow: true, newSince: $newSince, launchAt: $launchAt);
        $visitor = AchievementCatalogueRows::build($catalog, [], personal: true, launchAt: $launchAt);
        $beforeLaunch = AchievementCatalogueRows::build($catalog, [], personal: true, revealFlow: true, newSince: $newSince);

        self::assertSame([BadgeType::Streak], self::newOnes($owner));
        self::assertSame([], self::newOnes($visitor));
        self::assertSame([], self::newOnes($beforeLaunch), 'without the launch marker every tier counts as held at launch');
    }

    public function testEarnedIsNewestFirstWithTheLaunchHoldersAfterTheDatedOnes(): void
    {
        $catalog = [
            self::group(BadgeType::Streak, earnedUpTo: BadgeTier::Diamond, progress: null, earnedAt: '2026-08-01 09:00:00'),
            self::group(BadgeType::FirstTry, earnedUpTo: BadgeTier::Bronze, progress: 10, earnedAt: '2026-09-20 09:00:00'),
            self::group(BadgeType::Unboxed, earnedUpTo: BadgeTier::Silver, progress: 10, earnedAt: '2026-09-01 09:00:00'),
            self::group(BadgeType::Marathoner, earnedUpTo: BadgeTier::Gold, progress: 10, earnedAt: '2026-08-01 09:00:00'),
        ];

        $lists = AchievementCatalogueRows::build($catalog, [], personal: true, launchAt: new DateTimeImmutable('2026-08-01 09:17:00'));

        self::assertSame(
            [BadgeType::FirstTry, BadgeType::Unboxed, BadgeType::Streak, BadgeType::Marathoner],
            self::types($lists->rows(AchievementCatalogueFilter::Earned)),
        );
        self::assertTrue($lists->rows(AchievementCatalogueFilter::Earned)[2]->heldAtLaunch);
    }

    public function testAHiddenProgressIsLeftOutEverywhere(): void
    {
        $catalog = [
            self::group(BadgeType::Streak, earnedUpTo: BadgeTier::Bronze, progress: 90),
            self::group(BadgeType::FirstTry, earnedUpTo: null, progress: 30),
        ];

        $lists = AchievementCatalogueRows::build($catalog, [], personal: true, hideProgressOf: [BadgeType::Streak]);

        $streak = $lists->rows(AchievementCatalogueFilter::All)[0];
        self::assertSame(BadgeType::Streak, $streak->type);
        self::assertSame(BadgeTier::Bronze, $streak->earnedTier);
        self::assertNull($streak->progress);
        self::assertSame([BadgeType::FirstTry], self::types($lists->rows(AchievementCatalogueFilter::NextUp)));
    }

    public function testWithoutAnyAchievementTheNearestFirstOneIsKnown(): void
    {
        $catalog = [
            self::group(BadgeType::Streak, earnedUpTo: null, progress: 0),
            self::group(BadgeType::FirstTry, earnedUpTo: null, progress: 30),
            self::group(BadgeType::Unboxed, earnedUpTo: null, progress: null),
        ];

        $lists = AchievementCatalogueRows::build($catalog, [], personal: true, revealFlow: true);

        self::assertSame(0, $lists->earnedTypes);
        self::assertSame(BadgeType::FirstTry, $lists->nearestFirst?->type);
    }

    public function testTheBareCatalogueKeepsItsOrderAndKnowsNothingPersonal(): void
    {
        $catalog = [
            self::group(BadgeType::Streak, earnedUpTo: BadgeTier::Gold, progress: 40),
            self::group(BadgeType::FirstTry, earnedUpTo: null, progress: 80),
        ];

        $lists = AchievementCatalogueRows::build($catalog, [BadgeType::Streak->value => [1 => 53.0]], personal: false);

        self::assertSame([BadgeType::Streak, BadgeType::FirstTry], self::types($lists->rows(AchievementCatalogueFilter::All)));
        self::assertSame([], $lists->closest);
        self::assertSame(0, $lists->earnedTiers);
        $streak = $lists->rows(AchievementCatalogueFilter::All)[0];
        self::assertNull($streak->earnedTier);
        self::assertNull($streak->progress);
        self::assertSame(BadgeTier::Bronze, $streak->displayTier);
        self::assertSame(53.0, $streak->rarityPercent);
    }

    /**
     * @return list<BadgeType>
     */
    private static function newOnes(AchievementCatalogueLists $lists): array
    {
        return self::types(array_values(array_filter(
            $lists->rows(AchievementCatalogueFilter::All),
            static fn (AchievementCatalogueRow $row): bool => $row->newlyEarned,
        )));
    }

    /**
     * @param list<AchievementCatalogueRow> $rows
     * @return list<BadgeType>
     */
    private static function types(array $rows): array
    {
        return array_map(static fn (AchievementCatalogueRow $row): BadgeType => $row->type, $rows);
    }

    private static function group(
        BadgeType $type,
        null|BadgeTier $earnedUpTo,
        null|int $progress,
        bool $unrevealed = false,
        string $earnedAt = '2026-01-01 10:00:00',
    ): BadgeCatalogGroup {
        $tiers = [];

        foreach (BadgeTier::cases() as $tier) {
            $earned = $earnedUpTo !== null && $tier->value <= $earnedUpTo->value;
            $tiers[] = new BadgeCatalogEntry(
                type: $type,
                tier: $tier,
                requirementTranslationKey: 'badges.requirement.' . $type->value . '_' . $tier->value,
                earned: $earned,
                earnedAt: $earned ? new DateTimeImmutable($earnedAt) : null,
                revealed: !($unrevealed && $tier === $earnedUpTo),
            );
        }

        $nextTier = BadgeTier::tryFrom(($earnedUpTo->value ?? 0) + 1);

        return new BadgeCatalogGroup(
            type: $type,
            tiers: $tiers,
            progressToNext: $progress !== null && $nextTier !== null
                ? new BadgeProgress(nextTier: $nextTier, currentValue: $progress, targetValue: 100, percent: $progress)
                : null,
        );
    }
}
