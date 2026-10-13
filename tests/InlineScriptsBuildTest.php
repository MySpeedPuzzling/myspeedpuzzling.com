<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests;

use PHPUnit\Framework\TestCase;

/**
 * The inline <head> scripts every page carries are inlined as their minified builds (docs/features/navigation README
 * §4.12 "Weight"): tools/build-inline-scripts.mjs (npm run inline-scripts) turns the readable ES5 sources in
 * assets/head/ and assets/inline/ into templates/_inline_scripts/, and records the hash of each source and of its build
 * in sources.json. A source changed without a new build fails here - the page would ship the old behaviour - and so
 * does a build edited by hand: the next build would silently drop that edit. What the builds do is
 * tested on the rendered page (PlatformOsScriptTest, AssetFailureScriptTest, StaleDocumentScriptTest) and against
 * their sources (NavLayoutScriptTest, TopbarScrollScriptTest).
 */
final class InlineScriptsBuildTest extends TestCase
{
    private const string BUILDS = 'templates/_inline_scripts/';

    /** built file => where the page inlines it */
    private const array INLINED_BY = [
        'platform_os.js' => 'templates/_head_flags.html.twig',
        'standalone.js' => 'templates/_head_flags.html.twig',
        'nav_layout.js' => 'templates/_head_flags.html.twig',
        'asset_failure.js' => 'templates/base.html.twig',
        'stale_document.js' => 'templates/base.html.twig',
    ];

    public function testEverySourceHasItsCurrentBuild(): void
    {
        $manifest = $this->manifest();
        $sources = [];

        foreach ($manifest as $built => $entry) {
            $sources[] = $entry['source'];
            $code = $this->read($entry['source']);

            self::assertSame(
                $entry['sha256'],
                hash('sha256', $code),
                $entry['source'] . ' changed after its build: run `npm run inline-scripts` and commit ' . self::BUILDS . $built,
            );
            self::assertFileExists($this->root() . '/' . self::BUILDS . $built);
            self::assertSame(
                $entry['build_sha256'],
                hash('sha256', $this->read(self::BUILDS . $built)),
                self::BUILDS . $built . ' is not the build of ' . $entry['source'] . ': edit ' . $entry['source'] . ' and run `npm run inline-scripts`; never edit ' . self::BUILDS,
            );
        }

        // Every head script and inline script has a build - a new one cannot be inlined from its source by mistake
        $files = [...glob($this->root() . '/assets/head/*.js') ?: [], ...glob($this->root() . '/assets/inline/*.js') ?: []];
        $expected = array_map(fn (string $file): string => substr($file, strlen($this->root()) + 1), $files);
        sort($expected);
        sort($sources);
        self::assertSame($expected, $sources, 'tools/build-inline-scripts.mjs builds every file of assets/head/ and assets/inline/');
        self::assertSame(array_keys(self::INLINED_BY), array_keys($manifest));
    }

    public function testTheBuildsAreSafeToInline(): void
    {
        foreach (array_keys($this->manifest()) as $built) {
            $code = $this->read(self::BUILDS . $built);

            self::assertNotSame('', trim($code), $built);
            self::assertStringNotContainsStringIgnoringCase('</script', $code, $built);
            self::assertStringNotContainsString('<!--', $code, $built);
            // ES5: no arrow functions, let/const, template literals; comments dropped
            self::assertDoesNotMatchRegularExpression('~=>|\b(let|const)\s|`|/\*~', $code, $built);
            self::assertStringNotContainsString("\n", $code, $built . ': one line, nothing to strip at request time');
        }
    }

    public function testThePagesInlineTheBuildsNeverTheSources(): void
    {
        foreach (self::INLINED_BY as $built => $template) {
            self::assertMatchesRegularExpression(
                '~<script>\{\{ source\(\'_inline_scripts/' . preg_quote($built, '~') . '\'\)(\|replace\(\{[^}]*\}\))?\|raw \}\}</script>~',
                $this->read($template),
                $template . ' inlines ' . self::BUILDS . $built,
            );
        }

        foreach (['templates/_head_flags.html.twig', 'templates/base.html.twig'] as $template) {
            self::assertStringNotContainsString('assets/head', (string) preg_replace('~\{#.*?#\}~s', '', $this->read($template)), $template);
        }
    }

    /**
     * @return array<string, array{source: string, sha256: string, build_sha256: string}>
     */
    private function manifest(): array
    {
        /** @var array<string, array{source: string, sha256: string, build_sha256: string}> $manifest */
        $manifest = json_decode($this->read(self::BUILDS . 'sources.json'), true, flags: JSON_THROW_ON_ERROR);

        return $manifest;
    }

    private function read(string $path): string
    {
        $code = file_get_contents($this->root() . '/' . $path);
        self::assertIsString($code, $path);

        return $code;
    }

    private function root(): string
    {
        return dirname(__DIR__);
    }
}
