<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Navigation;

use SpeedPuzzling\Web\Navigation\FormerNavigation;
use SpeedPuzzling\Web\Navigation\FormerSurface;
use SpeedPuzzling\Web\Navigation\NavigationRegistry;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The old navigation as data (docs/features/navigation LAUNCH-02, decision 78): every route the old chrome linked for
 * any role (the frozen P0-02 baseline) is some item's route - nothing of the old menus is missing from "Where did it
 * go?" - and every item names a registry entry as its new home and keeps its old label in all six catalogues.
 */
final class FormerNavigationTest extends KernelTestCase
{
    private const string BASELINE = __DIR__ . '/../../docs/features/navigation/delivery/baseline-chrome-routes.json';

    public function testEveryRouteOfTheOldChromeHasItsItem(): void
    {
        $baseline = json_decode((string) file_get_contents(self::BASELINE), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($baseline);
        self::assertSame('a76cd36b', $baseline['baseCommit'] ?? null);
        self::assertIsArray($baseline['routes'] ?? null);

        $linked = [];

        foreach ($baseline['routes'] as $surfaces) {
            self::assertIsArray($surfaces);

            foreach ($surfaces as $surface => $routes) {
                self::assertIsArray($routes);
                // The baseline's surfaces are the old surfaces of the page
                self::assertNotNull(FormerSurface::tryFrom((string) $surface), (string) $surface);

                foreach ($routes as $route) {
                    self::assertIsString($route);
                    $linked[$route] = true;
                }
            }
        }

        $mapped = [];

        foreach (FormerNavigation::items() as $item) {
            foreach ($item->routes as $route) {
                $mapped[$route] = true;
            }
        }

        $linked = array_keys($linked);
        $mapped = array_keys($mapped);
        sort($linked);
        sort($mapped);

        self::assertCount(66, $linked);
        self::assertSame($linked, $mapped, 'Every route of the old chrome, and nothing else');
    }

    public function testEveryItemHasItsNewHomeAndItsOldName(): void
    {
        $registry = self::getContainer()->get(NavigationRegistry::class);
        $routes = self::getContainer()->get(RouterInterface::class)->getRouteCollection();
        $translator = self::getContainer()->get(TranslatorInterface::class);
        $surfaces = [];

        foreach (FormerNavigation::items() as $item) {
            $surfaces[$item->surface->value] = true;
            self::assertNotNull($registry->byId($item->entry), $item->entry);
            self::assertNotNull($registry->byId($item->linkEntry()), $item->linkEntry());

            foreach ($item->routes as $route) {
                self::assertTrue($routes->get($route) !== null || $routes->get($route . '.en') !== null, "Route {$route} does not exist");
            }

            // The old label key stays (NAV-06/FOOT-01 never delete them); the admin key's rows keep their English names
            foreach (['en', 'cs', 'de', 'es', 'fr', 'ja'] as $locale) {
                foreach ([$item->label, ...$item->path] as $key) {
                    if ($key !== null && $key !== '☰') {
                        self::assertTrue($translator->getCatalogue($locale)->defines($key), sprintf('%s %s', $locale, $key));
                    }
                }
            }

            if ($item->label === null) {
                self::assertSame(FormerSurface::AdminKey, $item->surface, $item->entry);
            }
        }

        self::assertSame(array_map(static fn (FormerSurface $surface): string => $surface->value, FormerSurface::cases()), array_keys($surfaces), 'Every old surface, in page order');
    }
}
