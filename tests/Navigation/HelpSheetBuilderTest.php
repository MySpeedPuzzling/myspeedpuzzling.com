<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Navigation;

use SpeedPuzzling\Web\Navigation\HelpSheetBuilder;
use SpeedPuzzling\Web\Navigation\NavEntry;
use SpeedPuzzling\Web\Navigation\NavigationRegistry;
use SpeedPuzzling\Web\Query\GetPlayerProfile;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The Help sheet's link rows come from the registry (README §4.5, §4.12): its helpSheet positions, in order, for the
 * viewer's audience. HELP-01a registers the skeleton's two links; HELP-02 orders the rest.
 */
final class HelpSheetBuilderTest extends KernelTestCase
{
    public function testEveryHelpSheetRowHasAnAudienceTheBuilderKnows(): void
    {
        $rows = self::getContainer()->get(NavigationRegistry::class)->helpSheet();

        self::assertNotSame([], $rows);

        foreach ($rows as $entry) {
            self::assertContains($entry->visibility, HelpSheetBuilder::AUDIENCES, sprintf('Help sheet row "%s": audience "%s" is not one the builder decides', $entry->id, $entry->visibility->value));
            // Rows are plain links: no viewer id to fill, a real route
            self::assertFalse($entry->target->needsViewer(), $entry->id);
            self::assertNotNull($entry->target->route, $entry->id);
        }
    }

    public function testTheSkeletonRowsForGuestsAndPlayers(): void
    {
        $builder = self::getContainer()->get(HelpSheetBuilder::class);
        $player = self::getContainer()->get(GetPlayerProfile::class)->byId(PlayerFixture::PLAYER_REGULAR);
        self::assertSame(['feature_requests', 'faq'], self::ids($builder->rows(null)));
        self::assertSame(['feature_requests', 'faq'], self::ids($builder->rows($player)));
    }

    /**
     * @param list<NavEntry> $rows
     * @return list<string>
     */
    private static function ids(array $rows): array
    {
        return array_map(static fn (NavEntry $entry): string => $entry->id, $rows);
    }
}
