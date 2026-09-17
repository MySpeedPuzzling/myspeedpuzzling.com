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
final class ServiceWorkerIconFontPrecacheTest extends TestCase
{
    private const string SERVICE_WORKER = __DIR__ . '/../public/service-worker.js';
    private const string MANIFEST = __DIR__ . '/../public/build/manifest.json';

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
