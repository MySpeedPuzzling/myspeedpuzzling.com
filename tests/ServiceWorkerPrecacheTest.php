<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests;

use PHPUnit\Framework\TestCase;

/**
 * The service worker precaches the icon fonts on install by looking their
 * hashed URLs up in manifest.json. It did so with exact keys
 * ('build/fonts/cartzilla-icons.woff'), but Webpack keeps whatever query string
 * the stylesheet asked for, so the real keys are
 * 'build/fonts/cartzilla-icons.woff?ufvuz0' and 'build/fonts/bootstrap-icons.woff2?'.
 * Both lookups returned undefined, .filter(Boolean) emptied the list and the
 * precaching quietly did nothing for as long as it had existed.
 *
 * Nothing broke, which is exactly why it went unnoticed — /build goes through
 * cacheFirst, so the fonts were cached on first use instead of on install. This
 * pins the prefixes against the built manifest so the next regeneration of
 * either font (?ufvuz0 is icomoon's own cache-buster and does change) fails here
 * rather than silently turning the optimisation off again.
 */
final class ServiceWorkerPrecacheTest extends TestCase
{
    private const string SERVICE_WORKER = __DIR__ . '/../public/service-worker.js';
    private const string MANIFEST = __DIR__ . '/../public/build/manifest.json';

    /**
     * install() opens with an unguarded `await cache.addAll([OFFLINE_URL,
     * ...FONT_URLS])`, outside every try/catch, and addAll is atomic: one
     * request that fails rejects the lot. That rejection reaches waitUntil, so
     * install fails and the worker never activates — no offline page, no
     * caching, and no activate handler to purge stale caches. Renaming or
     * moving any one of these files would do it, silently and site-wide.
     */
    public function testUnconditionallyPrecachedFilesExist(): void
    {
        $serviceWorker = (string) file_get_contents(self::SERVICE_WORKER);

        preg_match_all("/^const OFFLINE_URL = '([^']+)';/m", $serviceWorker, $offline);
        preg_match_all("/^const FONT_URLS = \[(.*?)\];/ms", $serviceWorker, $fontBlock);

        $paths = $offline[1];

        if (isset($fontBlock[1][0])) {
            preg_match_all("/'([^']+)'/", $fontBlock[1][0], $fonts);
            $paths = [...$paths, ...$fonts[1]];
        }

        self::assertNotEmpty($paths, 'Found neither OFFLINE_URL nor FONT_URLS — has the service worker been restructured?');

        foreach ($paths as $path) {
            self::assertFileExists(
                __DIR__ . '/../public' . $path,
                sprintf(
                    'The service worker precaches "%s" in an unguarded addAll. If it does not exist, '
                    . 'install() rejects and the service worker never activates at all.',
                    $path,
                ),
            );
        }
    }

    public function testEveryIconFontPrefixResolvesToABuiltFile(): void
    {
        if (!is_file(self::MANIFEST)) {
            self::markTestSkipped('Assets are not built (public/build/manifest.json missing).');
        }

        /** @var array<string, string> $manifest */
        $manifest = (array) json_decode((string) file_get_contents(self::MANIFEST), associative: true);
        $prefixes = $this->iconFontPrefixes();

        self::assertNotEmpty($prefixes, 'ICON_FONT_KEY_PREFIXES not found — has the service worker been restructured?');

        foreach ($prefixes as $prefix) {
            $matched = array_filter(
                $manifest,
                static fn (string $key): bool => str_starts_with($key, $prefix),
                ARRAY_FILTER_USE_KEY,
            );

            self::assertNotEmpty(
                $matched,
                sprintf(
                    'The service worker precaches "%s" but no manifest key starts with it, so install() '
                    . 'caches nothing. Manifest keys carry the stylesheet query string — check the real key.',
                    $prefix,
                ),
            );
        }
    }

    /**
     * Parsed out of the shipped file rather than duplicated here: a copy of the
     * list would drift from the one that actually runs, which is the whole bug.
     *
     * @return list<string>
     */
    private function iconFontPrefixes(): array
    {
        $serviceWorker = (string) file_get_contents(self::SERVICE_WORKER);

        if (preg_match('/const ICON_FONT_KEY_PREFIXES = \[(.*?)\];/s', $serviceWorker, $block) !== 1) {
            return [];
        }

        preg_match_all("/'([^']+)'/", $block[1], $matches);

        return $matches[1];
    }
}
