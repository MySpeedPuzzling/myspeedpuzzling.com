<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Navigation;

use SpeedPuzzling\Web\Navigation\FooterColumn;
use SpeedPuzzling\Web\Navigation\NavigationRegistry;
use SpeedPuzzling\Web\Navigation\NavVisibility;
use SpeedPuzzling\Web\Navigation\RowKind;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Routing\RouterInterface;

/**
 * The footer's placements in the registry (navigation FOOT-01, README §4.11): what FooterBuilder can render for
 * everybody - every placement a route of its own (never the viewer's page, the footer is the same for all), one
 * position per column, a well-formed data-nav, the four columns filled; a sign-in page keeps rel="nofollow".
 * The rendered columns per viewer: Controller\SiteFooterTest.
 */
final class FooterPlacementsTest extends KernelTestCase
{
    private const string NAV = '/^[a-z_]+\/[a-z0-9_]+$/';

    public function testEveryPlacementCanBeRenderedForEverybody(): void
    {
        $registry = self::getContainer()->get(NavigationRegistry::class);
        $router = self::getContainer()->get(RouterInterface::class);
        $entries = $registry->footer();
        $positions = [];
        $navs = [];

        self::assertCount(27, $entries, 'Puzzles 6 · Community 7 · Learn 7 · MySpeedPuzzling 7 (README §4.11)');

        foreach ($entries as $entry) {
            $footer = $entry->footer;
            self::assertNotNull($footer);
            $key = $footer->column->value . '/' . $footer->position;
            self::assertArrayNotHasKey($key, $positions, $entry->id . ' shares its position with ' . ($positions[$key] ?? ''));
            $positions[$key] = $entry->id;

            $nav = $footer->navFor($entry->id);
            self::assertMatchesRegularExpression(self::NAV, $nav, $entry->id);
            self::assertArrayNotHasKey($nav, $navs, $entry->id . ': data-nav taken by ' . ($navs[$nav] ?? ''));
            $navs[$nav] = $entry->id;

            if ($entry->kind === RowKind::HelpSheet) {
                continue;
            }

            self::assertNotNull($entry->target->route, $entry->id);
            self::assertFalse($entry->target->needsViewer(), $entry->id . ': the footer is the same for everybody - no page of the viewer\'s own');
            self::assertNotNull($router->getRouteCollection()->get($entry->target->route) ?? $router->getRouteCollection()->get($entry->target->route . '.en'), $entry->id);

            // A page behind sign-in is linked for guests too: crawlers are not invited to follow it (What next? is a row
            // of the signed-in Menu, but its page has a public landing for guests - indexed, in the sitemap)
            if (!$entry->visibility->includesGuests() && $footer->visibility === NavVisibility::Everyone) {
                self::assertTrue($footer->nofollow || $entry->target->route === 'puzzle_picker', $entry->id . ': a page behind sign-in keeps rel="nofollow"');
            }
        }

        $columns = array_values(array_unique(array_map(static fn ($entry): string => (string) $entry->footer?->column->value, $entries)));
        self::assertSame(array_map(static fn (FooterColumn $column): string => $column->value, FooterColumn::cases()), $columns, 'Column order');
    }
}
