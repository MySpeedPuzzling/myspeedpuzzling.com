<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * The analytics studio's own Encore entry `analytics_studio` (assets/analytics_studio.js,
 * docs/features/competitions-management/analytics-studio.md §7.6, S3) stays a plain, standalone entry:
 * - no package at all: no Stimulus, Turbo or site bundle - the page adapter assets/analytics-studio/page.js wires the
 *   pure modules itself (Stimulus alone would have cost ~30 KB of the budget);
 * - no lazy chunk;
 * - a size budget for its JS, measured by tests/live-entry-bundle.mjs as Encore's minifier (terser) would leave the
 *   modules. 2026-11-17 (S3): 63.5 KB minified / 21.2 KB gzip (S2's modules + page.js 9 KB) - budget the measured
 *   size + 5 %. Raise it only on purpose, saying why in the commit. 2026-11-18 (v2 V3, the prediction column + sort:
 *   the round-predictions planner, merge, column and status line): 70.2 KB / 22.9 KB gzip → 73.7 KB / 24.1 KB;
 * - one file of its own: it shares no split chunk with `app`, and its stylesheet is its own (no Bootstrap).
 */
final class AnalyticsStudioBundleTest extends TestCase
{
    private const int MINIFIED_BUDGET_BYTES = 73_700;
    private const int GZIP_BUDGET_BYTES = 24_100;
    private const string ENTRYPOINTS = __DIR__ . '/../public/build/entrypoints.json';

    /** @var null|array{modules: list<string>, packages: list<string>, dynamicImports: list<string>, minifiedBytes: int, gzipBytes: int} */
    private static null|array $graph = null;

    public function testTheEntryLoadsThePageAdapterAndTheModules(): void
    {
        $modules = $this->graph()['modules'];

        foreach (['page', 'model', 'keys', 'url', 'refresh', 'render', 'format'] as $module) {
            self::assertContains('assets/analytics-studio/' . $module . '.js', $modules);
        }
    }

    public function testTheEntryIsPlain(): void
    {
        $graph = $this->graph();

        self::assertSame([], $graph['packages'], 'no Stimulus, Turbo or other package in the studio');
        self::assertSame([], $graph['dynamicImports']);
    }

    public function testTheEntryStaysWithinItsBudget(): void
    {
        $graph = $this->graph();

        self::assertLessThanOrEqual(self::MINIFIED_BUDGET_BYTES, $graph['minifiedBytes'], sprintf('analytics_studio is %d bytes minified', $graph['minifiedBytes']));
        self::assertLessThanOrEqual(self::GZIP_BUDGET_BYTES, $graph['gzipBytes'], sprintf('analytics_studio is %d bytes gzipped', $graph['gzipBytes']));
    }

    public function testTheBuiltEntryIsOneFileOfItsOwn(): void
    {
        if (!is_file(self::ENTRYPOINTS)) {
            self::markTestSkipped('Assets are not built (public/build/entrypoints.json missing).');
        }

        /** @var array{entrypoints: array<string, array{js?: list<string>, css?: list<string>}>} $entrypoints */
        $entrypoints = json_decode((string) file_get_contents(self::ENTRYPOINTS), true, flags: JSON_THROW_ON_ERROR);

        if (!isset($entrypoints['entrypoints']['analytics_studio'])) {
            self::markTestSkipped('The mounted build predates the analytics_studio entry.');
        }

        $studio = $entrypoints['entrypoints']['analytics_studio'];
        $app = $entrypoints['entrypoints']['app'] ?? [];

        self::assertCount(2, $studio['js'] ?? [], 'runtime.js + the entry itself, no chunk split off');
        self::assertCount(1, $studio['css'] ?? [], 'its own stylesheet only');

        foreach ([...$studio['js'], ...$studio['css']] as $file) {
            if (str_contains($file, '/runtime')) {
                continue;
            }

            self::assertNotContains($file, [...$app['js'] ?? [], ...$app['css'] ?? []], $file . ' is shared with the app entry');
        }
    }

    /**
     * @return array{modules: list<string>, packages: list<string>, dynamicImports: list<string>, minifiedBytes: int, gzipBytes: int}
     */
    private function graph(): array
    {
        if (self::$graph === null) {
            $node = new ExecutableFinder()->find('node');

            self::assertIsString($node, 'node is required to execute the script - it is part of the base image');

            $process = new Process([$node, __DIR__ . '/live-entry-bundle.mjs', 'assets/analytics_studio.js']);
            $process->mustRun();

            /** @var array{modules: list<string>, packages: list<string>, dynamicImports: list<string>, minifiedBytes: int, gzipBytes: int} $graph */
            $graph = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
            self::$graph = $graph;
        }

        return self::$graph;
    }
}
