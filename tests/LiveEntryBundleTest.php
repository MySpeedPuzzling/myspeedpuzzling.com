<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * The live entry page's own Encore entry `live_entry` (assets/live_entry.js, competition toolkit C2 -
 * docs/features/competitions-management/toolkit/C-judge-page.md §1.1, §4.3) stays what makes it fast and offline-able:
 * - no lazy chunk: the service worker stores only what entrypoints.json lists with the page's offline copy, so an
 *   `import()` anywhere in the entry's graph would be a file missing offline;
 * - no site bundle: Stimulus only - never Turbo, Live Components, Bootstrap JS or the stimulus bridge (it would pull in
 *   every controller);
 * - a size budget for its JS, measured by tests/live-entry-bundle.mjs as Encore's minifier (terser) would leave the
 *   modules - independent of whether public/build holds a dev or a production build. 2026-11-17: 128 KB minified /
 *   35 KB gzip (a production build: 144 KB for live_entry.js after Babel, + 6 KB runtime.js). Raise the budget only
 *   on purpose, saying why in the commit. 2026-11-17 (toolkit C7): 160 KB after C3-C6 (155 KB), C7 added 5 KB - outbox
 *   groups that are never split (R §1.1), the O(1) entries view and find rows, the unique-table open - budget 170 KB.
 *   2026-11-17 (toolkit C10): 174 KB / 47.6 KB gzip after C8 (44.7 KB gzip) - the puzzle choice of pick rounds pulls in
 *   official_results_puzzles.js (the record's rules, shared with the server's twin - imported, never re-implemented)
 *   and live_entry_puzzles.js (chips, K-of-N taps, the grouped save, "Puzzle missing"); budget 180 KB / 50 KB gzip,
 *   which leaves C9 (proposals) its room. 2026-11-17 (toolkit C9): 185 KB / 50.8 KB gzip - time proposals on the card
 *   (panel with Confirm / Change / Not right, late and link hints, the "Proposals waiting" list, outcome annotations)
 *   need live_entry_proposals.js (3.2 KB) + 7.7 KB in the controller after compacting its markup; budget 190 KB /
 *   52 KB gzip. 2026-11-17 (reliability hardening T1): 186.2 → 195.2 KB / 51.1 → 53.7 KB gzip - "Didn't finish" counted
 *   as missing pieces (official_results_missing.js 2.2 KB, shared with the results desk), a pick round's per-puzzle
 *   rows (several puzzles not finished, a count each), the Missing | Placed switch kept per phone, the time's-up open;
 *   budget 197 KB / 54 KB gzip. 2026-11-17 (reliability hardening T2): 197.1 KB / 54.1 KB gzip - the evidence-based
 *   timing notes of a proposal ("Check this time", "Use received", clock note) and uuid_v7.js; budget 205 KB / 56 KB
 *   gzip, which leaves F1 (account check before sending, monotonic clock, snapshot max wait) its room. 2026-11-17
 *   (reliability hardening F1): +2.7 KB / +0.8 KB gzip (measured before T2) - the outbox never sends under another
 *   player's session (viewer check, `expectedPlayerId`, 409 `another_account`), the server clock on the monotonic clock
 *   with sleep / clock-change detection, the snapshot's max wait + first state at once, the banner below an open card;
 * - one file of its own: `live_entry` shares no split chunk with `app`, so the site's pages keep their script tags.
 */
final class LiveEntryBundleTest extends TestCase
{
    private const int MINIFIED_BUDGET_BYTES = 205_000;
    private const int GZIP_BUDGET_BYTES = 56_000;
    private const string ENTRYPOINTS = __DIR__ . '/../public/build/entrypoints.json';

    /** @var null|array{modules: list<string>, packages: list<string>, dynamicImports: list<string>, minifiedBytes: int, gzipBytes: int} */
    private static null|array $graph = null;

    public function testTheEntryLoadsTheLiveResultsControllerAndItsModulesEagerly(): void
    {
        $modules = $this->graph()['modules'];

        self::assertContains('assets/controllers/live_results_controller.js', $modules);
        self::assertContains('assets/official_results_outbox.js', $modules);
        self::assertContains('assets/official_results_scan.js', $modules, 'the name tag scanner - statically, not as a lazy chunk');
        self::assertContains('assets/live_entry_page.js', $modules);
    }

    public function testNothingInTheEntryIsLoadedLazily(): void
    {
        self::assertSame([], $this->graph()['dynamicImports'], 'An import() in the live entry is a file the service worker never stores for offline - import it statically');
    }

    public function testTheEntryCarriesNoneOfTheSiteBundle(): void
    {
        self::assertSame(['@hotwired/stimulus'], $this->graph()['packages']);
    }

    public function testTheEntryStaysWithinItsBudget(): void
    {
        $graph = $this->graph();

        self::assertLessThanOrEqual(self::MINIFIED_BUDGET_BYTES, $graph['minifiedBytes'], sprintf('live_entry is %d bytes minified', $graph['minifiedBytes']));
        self::assertLessThanOrEqual(self::GZIP_BUDGET_BYTES, $graph['gzipBytes'], sprintf('live_entry is %d bytes gzipped', $graph['gzipBytes']));
    }

    public function testTheBuiltEntryIsOneFileOfItsOwn(): void
    {
        if (!is_file(self::ENTRYPOINTS)) {
            self::markTestSkipped('Assets are not built (public/build/entrypoints.json missing).');
        }

        /** @var array{entrypoints: array<string, array{js?: list<string>, css?: list<string>}>} $entrypoints */
        $entrypoints = json_decode((string) file_get_contents(self::ENTRYPOINTS), true, flags: JSON_THROW_ON_ERROR);

        self::assertArrayHasKey('live_entry', $entrypoints['entrypoints'], 'webpack.config.js has no live_entry entry');
        $liveEntry = $entrypoints['entrypoints']['live_entry'];
        $appFiles = $entrypoints['entrypoints']['app']['js'] ?? [];

        self::assertArrayNotHasKey('css', $liveEntry, 'the page uses the site\'s stylesheet (app) - the entry imports no CSS');
        self::assertCount(2, $liveEntry['js'] ?? [], 'runtime.js + the entry itself, no chunk split off');

        foreach ($liveEntry['js'] as $file) {
            if (str_contains($file, '/runtime')) {
                continue;
            }

            self::assertNotContains($file, $appFiles, $file . ' is shared with the app entry');
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

            $process = new Process([$node, __DIR__ . '/live-entry-bundle.mjs']);
            $process->mustRun();

            /** @var array{modules: list<string>, packages: list<string>, dynamicImports: list<string>, minifiedBytes: int, gzipBytes: int} $graph */
            $graph = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
            self::$graph = $graph;
        }

        return self::$graph;
    }
}
