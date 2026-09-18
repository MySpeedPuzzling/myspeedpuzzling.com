<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Routing\RouterInterface;

/**
 * A localized route (`path: ['cs' => ..., 'en' => ...]`) only exists in the locales it
 * lists, and `path('name')` resolves it through the *current* request locale - so a
 * page rendered in any other locale that links to it fails with a 500
 * (RouteNotFoundException wrapped in a Twig RuntimeError).
 */
final class LocalizedRoutesTest extends KernelTestCase
{
    private const array LOCALES = ['cs', 'de', 'en', 'es', 'fr', 'ja'];

    /**
     * Deliberately partial: nothing ever links to these through path(), they only
     * catch old inbound URLs that existed in these locales.
     */
    private const array PARTIAL_BY_DESIGN = [
        'wjpc_2024',
    ];

    public function testEveryLocalizedRouteExistsInEveryLocale(): void
    {
        $router = self::getContainer()->get(RouterInterface::class);

        /** @var array<string, list<string>> $localesByRoute */
        $localesByRoute = [];

        foreach ($router->getRouteCollection() as $route) {
            $canonicalName = $route->getDefault('_canonical_route');
            $locale = $route->getDefault('_locale');

            if (!is_string($canonicalName) || !is_string($locale)) {
                continue;
            }

            $localesByRoute[$canonicalName][] = $locale;
        }

        self::assertNotEmpty($localesByRoute);

        $incomplete = [];

        foreach ($localesByRoute as $routeName => $locales) {
            if (in_array($routeName, self::PARTIAL_BY_DESIGN, true)) {
                continue;
            }

            $missing = array_values(array_diff(self::LOCALES, $locales));

            if ($missing !== []) {
                $incomplete[$routeName] = implode(', ', $missing);
            }
        }

        self::assertSame([], $incomplete, 'Localized routes missing some locales (route => missing locales)');
    }
}
