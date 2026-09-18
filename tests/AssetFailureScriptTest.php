<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Runs the inline asset-failure script of base.html.twig, exactly as a page
 * rendered it, in a fake browser (tests/asset-failure-harness.js) - one page
 * load after another, because what matters is what the script does across a
 * session: how often it reloads, and how often it reports.
 *
 * It fails open on purpose, so a mistake in it is silent forever. The one
 * failure that is not silent is worse: reloading a page in a loop.
 */
final class AssetFailureScriptTest extends WebTestCase
{
    private const int RENDERED_AT = 1_800_000_000;
    private const string CSS = 'https://myspeedpuzzling.com/build/app.9733e232.css';
    private const string JS = 'https://myspeedpuzzling.com/build/app.62cb6fb9.js';
    private const string CHUNK = 'https://myspeedpuzzling.com/build/839.32cae04d.js';
    private const array NETWORK = [
        self::CSS => ['status' => 200, 'body' => 'body{color:#1d1d1d}'],
        self::JS => ['status' => 200, 'body' => 'console.log("app");'],
        self::CHUNK => ['status' => 200, 'body' => 'console.log("chunk");'],
    ];

    /** @var null|array<string, list<array{via: string, beacons: list<array{url: string, payload: array<string, mixed>}>, reloads: int, refetches: list<array{url: string, cache: string}>, cacheDeletes: list<string>, historyMarked: bool}>> */
    private static null|array $results = null;

    public function testHealthyPageDoesNothing(): void
    {
        [$load] = $this->loadsOf('healthy page');

        self::assertSame([], $load['beacons']);
        self::assertSame([], $load['refetches']);
        self::assertSame(0, $load['reloads']);
    }

    public function testHealRepairsAPoisonedStylesheetAndReportsItOnce(): void
    {
        [$broken, $repaired] = $this->loadsOf('poisoned stylesheet');

        // The error event and the load-time sweep both see the file - one report.
        self::assertCount(1, $broken['beacons']);
        self::assertSame('/-/asset-load-failure', $broken['beacons'][0]['url']);
        self::assertSame([
            'v' => 2,
            'url' => self::CSS,
            'page' => '/en/hub',
            'controlled' => true,
            'retry' => false,
            'healing' => true,
            'refetch' => 'intact',
            'rendered' => self::RENDERED_AT,
            'webdriver' => false,
        ], $broken['beacons'][0]['payload']);
        self::assertSame([['url' => self::CSS, 'cache' => 'reload']], $broken['refetches']);
        self::assertSame(
            ['static-v7 https://myspeedpuzzling.com/build/app.css', 'images-v7 https://myspeedpuzzling.com/build/app.css'],
            $broken['cacheDeletes'],
            'Only /build entries are purged, never fonts or anything else',
        );
        self::assertSame(1, $broken['reloads']);
        self::assertTrue($broken['historyMarked']);

        self::assertSame([], $repaired['beacons']);
        self::assertSame(0, $repaired['reloads']);
    }

    /**
     * The second broken file used to read the attempt counter the first one had
     * just raised, and reported itself as a failed retry.
     */
    public function testSecondBrokenFileOnTheSamePageIsNotARetry(): void
    {
        [$load] = $this->loadsOf('two broken files');

        self::assertCount(2, $load['beacons']);
        self::assertSame([self::CSS, self::JS], array_map(static fn (array $beacon): mixed => $beacon['payload']['url'], $load['beacons']));

        foreach ($load['beacons'] as $beacon) {
            self::assertFalse($beacon['payload']['retry']);
            self::assertTrue($beacon['payload']['healing']);
            self::assertSame('intact', $beacon['payload']['refetch']);
        }

        self::assertSame(1, $load['reloads'], 'One heal per page load, however many files are broken');
    }

    /**
     * A browser that blocks stylesheets itself - the headless scrapers do - is
     * healed twice, told once that it stays broken, and then left alone:
     * reporting every page view is what produced 1,500 warnings a day.
     */
    public function testPersistentFailureIsReportedOnceWhenTheHealsRunOutThenNeverAgain(): void
    {
        [$first, $second, $final, $reloadedByHand, $nextPage] = $this->loadsOf('blocked in the browser');

        self::assertSame(1, $first['reloads']);
        self::assertTrue($first['beacons'][0]['payload']['healing']);
        self::assertFalse($first['beacons'][0]['payload']['retry']);

        self::assertSame(1, $second['reloads']);
        self::assertTrue($second['beacons'][0]['payload']['healing']);
        self::assertTrue($second['beacons'][0]['payload']['retry']);

        self::assertSame(0, $final['reloads']);
        self::assertCount(1, $final['beacons']);
        self::assertFalse($final['beacons'][0]['payload']['healing']);
        self::assertTrue($final['beacons'][0]['payload']['retry']);
        self::assertSame('intact', $final['beacons'][0]['payload']['refetch'], 'The final report carries what the last refetch found');
        self::assertFalse($final['beacons'][0]['payload']['controlled']);

        foreach ([$reloadedByHand, $nextPage] as $load) {
            self::assertSame([], $load['beacons']);
            self::assertSame(0, $load['reloads']);
        }
    }

    public function testBytesThatFailTheSriHashAreReportedAsCorrupt(): void
    {
        self::assertSame('corrupt', $this->loadsOf('corrupt bytes')[0]['beacons'][0]['payload']['refetch']);
    }

    public function testRefetchReportsWhatTheNetworkAnswered(): void
    {
        self::assertSame('http-404', $this->loadsOf('asset gone')[0]['beacons'][0]['payload']['refetch']);
        self::assertSame('unreachable', $this->loadsOf('network down')[0]['beacons'][0]['payload']['refetch']);
        self::assertSame('fetched', $this->loadsOf('lazy chunk without SRI hash')[0]['beacons'][0]['payload']['refetch']);
    }

    public function testStalledRefetchDoesNotHoldTheReportAndTheReloadBack(): void
    {
        [$load] = $this->loadsOf('stalled network');

        self::assertSame('timeout', $load['beacons'][0]['payload']['refetch']);
        self::assertSame(1, $load['reloads']);
    }

    /**
     * One Chrome reloaded 95 times in 22 seconds on 2026-09-16: its
     * sessionStorage did not survive the reload, so the attempt cap never
     * counted. The mark on the history entry has to stop that on its own.
     */
    public function testLostSessionStorageCannotCauseAReloadLoop(): void
    {
        $loads = $this->loadsOf('sessionStorage lost on reload');

        self::assertSame(1, array_sum(array_column($loads, 'reloads')));

        foreach (array_slice($loads, 1) as $load) {
            self::assertCount(1, $load['beacons']);
            self::assertFalse($load['beacons'][0]['payload']['healing']);
        }
    }

    public function testWithoutSessionStorageItReportsButNeverReloads(): void
    {
        foreach ($this->loadsOf('sessionStorage unavailable') as $load) {
            self::assertSame(0, $load['reloads']);
            self::assertCount(1, $load['beacons']);
            self::assertFalse($load['beacons'][0]['payload']['healing']);
        }
    }

    public function testAutomatedBrowserSaysSo(): void
    {
        self::assertTrue($this->loadsOf('automated browser')[0]['beacons'][0]['payload']['webdriver']);
    }

    /**
     * @return list<array{via: string, beacons: list<array{url: string, payload: array<string, mixed>}>, reloads: int, refetches: list<array{url: string, cache: string}>, cacheDeletes: list<string>, historyMarked: bool}>
     */
    private function loadsOf(string $scenario): array
    {
        if (self::$results === null) {
            self::$results = $this->runScenarios();
        }

        self::assertArrayHasKey($scenario, self::$results);

        return self::$results[$scenario];
    }

    /**
     * @return array<string, list<array{via: string, beacons: list<array{url: string, payload: array<string, mixed>}>, reloads: int, refetches: list<array{url: string, cache: string}>, cacheDeletes: list<string>, historyMarked: bool}>>
     */
    private function runScenarios(): array
    {
        $browser = self::createClient();
        $browser->request('GET', '/en/hub');

        self::assertSame(
            1,
            preg_match('~<script>((?:(?!</script>).)*?/-/asset-load-failure(?:(?!</script>).)*)</script>~s', (string) $browser->getResponse()->getContent(), $matches),
            'The asset-failure script must be rendered inline in base.html.twig',
        );

        $brokenCss = ['url' => self::CSS, 'via' => 'both', 'integrity' => 'auto'];
        $healthy = ['via' => 'navigate', 'stylesheets' => [self::CSS]];
        $broken = ['via' => 'navigate', 'stylesheets' => [self::CSS], 'failing' => [$brokenCss]];
        $brokenAgain = ['via' => 'reload', 'stylesheets' => [self::CSS], 'failing' => [$brokenCss]];
        $scenario = ['controlled' => true, 'webdriver' => false, 'storage' => 'persistent', 'renderedAt' => self::RENDERED_AT, 'network' => self::NETWORK];

        $node = new ExecutableFinder()->find('node');

        self::assertIsString($node, 'node is required to execute the script - it is part of the base image');

        $process = new Process([$node, __DIR__ . '/asset-failure-harness.js']);
        $process->setInput((string) json_encode([
            'script' => $matches[1],
            'scenarios' => [
                ['name' => 'healthy page', ...$scenario, 'loads' => [$healthy]],
                ['name' => 'poisoned stylesheet', ...$scenario, 'loads' => [$broken, ['via' => 'reload', 'stylesheets' => [self::CSS]]]],
                ['name' => 'two broken files', ...$scenario, 'loads' => [[
                    'via' => 'navigate',
                    'stylesheets' => [self::CSS],
                    'failing' => [['url' => self::CSS, 'via' => 'error', 'integrity' => 'auto'], ['url' => self::JS, 'via' => 'error', 'integrity' => 'auto']],
                ]]],
                ['name' => 'blocked in the browser', ...$scenario, 'controlled' => false, 'loads' => [$broken, $brokenAgain, $brokenAgain, $brokenAgain, $broken]],
                ['name' => 'corrupt bytes', ...$scenario, 'loads' => [[
                    'via' => 'navigate',
                    'stylesheets' => [self::CSS],
                    'failing' => [['url' => self::CSS, 'via' => 'both', 'integrity' => 'sha384-' . base64_encode(hash('sha384', 'what the build produced', true))]],
                ]]],
                ['name' => 'asset gone', ...$scenario, 'network' => [self::CSS => ['status' => 404, 'body' => '']], 'loads' => [$broken]],
                ['name' => 'network down', ...$scenario, 'network' => [], 'loads' => [$broken]],
                ['name' => 'stalled network', ...$scenario, 'network' => [self::CSS => ['status' => 'stalled']], 'loads' => [$broken]],
                ['name' => 'lazy chunk without SRI hash', ...$scenario, 'loads' => [['via' => 'navigate', 'failing' => [['url' => self::CHUNK, 'via' => 'error']]]]],
                ['name' => 'sessionStorage lost on reload', ...$scenario, 'storage' => 'wiped on reload', 'loads' => [$broken, $brokenAgain, $brokenAgain]],
                ['name' => 'sessionStorage unavailable', ...$scenario, 'storage' => 'unavailable', 'loads' => [$broken, $broken]],
                ['name' => 'automated browser', ...$scenario, 'webdriver' => true, 'loads' => [$broken]],
            ],
        ]));
        $process->mustRun();

        /** @var list<array{name: string, loads: list<array{via: string, beacons: list<array{url: string, payload: array<string, mixed>}>, reloads: int, refetches: list<array{url: string, cache: string}>, cacheDeletes: list<string>, historyMarked: bool}>}> $decoded */
        $decoded = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);

        $results = [];

        foreach ($decoded as $result) {
            $results[$result['name']] = $result['loads'];
        }

        return $results;
    }
}
