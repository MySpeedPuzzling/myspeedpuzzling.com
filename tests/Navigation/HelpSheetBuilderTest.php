<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Navigation;

use SpeedPuzzling\Web\Navigation\HelpPageContext;
use SpeedPuzzling\Web\Navigation\HelpPageContextFactory;
use SpeedPuzzling\Web\Navigation\HelpSheetBuilder;
use SpeedPuzzling\Web\Navigation\HelpSheetItem;
use SpeedPuzzling\Web\Navigation\HelpSheetItemKind;
use SpeedPuzzling\Web\Navigation\NavEntry;
use SpeedPuzzling\Web\Navigation\NavigationRegistry;
use SpeedPuzzling\Web\Query\GetPlayerProfile;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Tests\TestDouble\FixedXpLaunchReadiness;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * The Help sheet's items in their order (README §4.5, HELP-02): the link rows come from the registry (its helpSheet
 * positions, in order, for the viewer's audience), the feedback box / "Sign in to send feedback", the puzzle correction,
 * Language and Reload around them.
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

    /**
     * README §4.5 (HELP-02): Ideas & feature requests · FAQ · Getting started · How progress works (XP visible) · Where
     * did it go? (signed in only, R2)
     */
    public function testTheRegistryRowsForGuestsAndPlayers(): void
    {
        $builder = self::getContainer()->get(HelpSheetBuilder::class);
        $player = self::getContainer()->get(GetPlayerProfile::class)->byId(PlayerFixture::PLAYER_REGULAR);
        self::assertSame(['feature_requests', 'faq', 'getting_started', 'progression'], self::ids($builder->rows(null)));
        self::assertSame(['feature_requests', 'faq', 'getting_started', 'progression', 'where_did_it_go'], self::ids($builder->rows($player)));
    }

    /**
     * The whole sheet in its order (README §4.5): the feedback box (signed in) or "Sign in to send feedback" (guests,
     * HELP-03) · the puzzle correction (puzzle pages only, first) · the registry rows · Language · Reload page
     */
    public function testTheItemsInTheirOrder(): void
    {
        $builder = self::getContainer()->get(HelpSheetBuilder::class);
        $factory = self::getContainer()->get(HelpPageContextFactory::class);
        $player = self::getContainer()->get(GetPlayerProfile::class)->byId(PlayerFixture::PLAYER_REGULAR);
        $request = Request::create('http://localhost/en/help');
        $puzzlePage = $factory->fromUrlParameter('/en/puzzle/' . PuzzleFixture::PUZZLE_500_01, $request);
        $faq = $factory->fromUrlParameter('/en/faq', $request);

        self::assertSame(
            ['feedback', 'suggest_correction', 'feature_requests', 'faq', 'getting_started', 'progression', 'where_did_it_go', 'language', 'reload'],
            self::itemIds($builder->items($player, $puzzlePage)),
        );
        self::assertSame(
            ['feedback', 'feature_requests', 'faq', 'getting_started', 'progression', 'where_did_it_go', 'language', 'reload'],
            self::itemIds($builder->items($player, $faq)),
        );
        self::assertSame(
            ['feedback_sign_in', 'suggest_correction', 'feature_requests', 'faq', 'getting_started', 'progression', 'language', 'reload'],
            self::itemIds($builder->items(null, $puzzlePage)),
        );
        self::assertSame(
            ['feedback_sign_in', 'feature_requests', 'faq', 'getting_started', 'progression', 'language', 'reload'],
            self::itemIds($builder->items(null, HelpPageContext::empty())),
        );

        $items = $builder->items($player, $puzzlePage);
        self::assertSame(
            [HelpSheetItemKind::Feedback, HelpSheetItemKind::Correction, HelpSheetItemKind::Link, HelpSheetItemKind::Link, HelpSheetItemKind::Link, HelpSheetItemKind::Link, HelpSheetItemKind::Link, HelpSheetItemKind::Language, HelpSheetItemKind::Reload],
            array_map(static fn (HelpSheetItem $item): HelpSheetItemKind => $item->kind, $items),
        );
        // Labels, icons and the description come from the registry (one name per destination)
        self::assertSame('puzzle_suggest_change', $items[1]->entry?->target->route);
        self::assertSame('help.suggest_correction', $items[1]->entry->label);
        self::assertSame('help.suggest_correction_desc', $items[1]->entry->description);
        self::assertSame('bi-translate', $items[7]->entry?->icon);
    }

    /**
     * "How progress works" follows the XP gate (XpFeatureGate): not before the launch, admins always
     */
    public function testHowProgressWorksFollowsTheXpGate(): void
    {
        $builder = self::getContainer()->get(HelpSheetBuilder::class);
        $player = self::getContainer()->get(GetPlayerProfile::class)->byId(PlayerFixture::PLAYER_REGULAR);
        $admin = self::getContainer()->get(GetPlayerProfile::class)->byId(PlayerFixture::PLAYER_ADMIN);

        FixedXpLaunchReadiness::$ready = false;

        self::assertNotContains('progression', self::ids($builder->rows(null)));
        self::assertNotContains('progression', self::ids($builder->rows($player)));
        self::assertContains('progression', self::ids($builder->rows($admin)));

        FixedXpLaunchReadiness::$ready = true;

        self::assertContains('progression', self::ids($builder->rows(null)));
        self::assertContains('progression', self::ids($builder->rows($player)));
    }

    /**
     * @param list<NavEntry> $rows
     * @return list<string>
     */
    private static function ids(array $rows): array
    {
        return array_map(static fn (NavEntry $entry): string => $entry->id, $rows);
    }

    /**
     * @param list<HelpSheetItem> $items
     * @return list<string>
     */
    private static function itemIds(array $items): array
    {
        return array_map(static fn (HelpSheetItem $item): string => $item->id, $items);
    }
}
