<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * @phpstan-type Step array{
 *     body: null|string,
 *     status: null|int,
 *     shellHeader: null|string,
 *     intercepted: bool,
 *     pruned: null|bool,
 *     messages: list<array{type: string, locale: string, storedAt?: int}>,
 *     networkFetches: list<array{path: string, cache: null|string, credentials: null|string}>,
 *     reports: list<string>,
 *     delays: list<int>,
 *     cacheReads: list<string>,
 *     cacheWrites: list<string>,
 * }
 * @phpstan-type Scenario array{name: string, steps: list<Step>, caches: array<string, list<string>>}
 *
 * The live result entry's offline copy in the real public/service-worker.js (competition toolkit C6 -
 * docs/features/competitions-management/toolkit/C-judge-page.md §1.4, §7.2, R-review-and-plan.md §2.7), run under node
 * by tests/service-worker-live-harness.js with real in-memory caches: `live-shell:prepare` stores the copy only once
 * every file it lists is stored, a live entry navigation goes to the network first (no cache read while it answers) and
 * gets the copy of its locale when the network fails or stays silent for 4 s, every other navigation never gets it, the
 * prune after a deploy keeps the copy's files, and the scanner's decoder is cache-first.
 *
 * The one exception to ServiceWorkerBehaviourTest's "no document is ever answered from a cache": the data-free copy
 * under `live-shell-v7`, for live entry URLs only, and only when the network did not answer.
 */
final class ServiceWorkerLiveShellTest extends TestCase
{
    private const string ROUND = '018d0020-0000-0000-0000-000000000101';
    private const string COMPETITION = '018d0020-0000-0000-0000-000000000c01';
    private const string PARTICIPANT = '018d0020-0000-0000-0000-0000000000f1';
    private const string STATIC = 'static-v7';
    private const string SHELLS = 'live-shell-v7';
    private const array ASSETS = ['/build/runtime.111.js', '/build/live_entry.222.js', '/build/app.333.css', '/fonts/rubik/rubik-latin.woff2'];
    private const array SCANNER = [
        '/barcode-scanner/zbar-wasm-0.9.15/barcode-detector-polyfill.js',
        '/barcode-scanner/zbar-wasm-0.9.15/zbar-wasm.js',
        '/barcode-scanner/zbar-wasm-0.9.15/zbar.wasm',
    ];

    // The copy's own URLs, another page, the results desk
    private const array OTHER_PAGES = ['/en/live-results-shell', '/zadavani-vysledku-shell', '/en/hub', '/en/official-results/rounds/' . self::ROUND];

    /** @var null|array<string, Scenario> */
    private static null|array $results = null;

    public function testPrepareStoresTheCopyAfterEveryFileItLists(): void
    {
        $result = self::scenarioResult('prepare');
        $step = $result['steps'][0];

        $messages = $step['messages'];
        self::assertCount(1, $messages);
        self::assertSame('live-shell:ready', $messages[0]['type']);
        self::assertSame('en', $messages[0]['locale']);
        self::assertGreaterThan(0, $messages[0]['storedAt'] ?? 0);
        self::assertSame(['path' => '/en/live-results-shell', 'cache' => 'no-cache', 'credentials' => 'omit'], $step['networkFetches'][0], 'the copy is fetched anew and without cookies - the same for everybody');

        $static = $result['caches'][self::STATIC];
        foreach (self::ASSETS as $asset) {
            self::assertContains($asset, $static);
        }
        self::assertSame(['/__live-shell/en.json', '/en/live-results-shell'], $result['caches'][self::SHELLS]);

        // The copy is written last: after every file it needs
        $writes = $step['cacheWrites'];
        self::assertSame(self::SHELLS . ' /en/live-results-shell', $writes[count($writes) - 2]);
        foreach (self::SCANNER as $file) {
            self::assertNotContains($file, $static, 'the decoder only when the page asks for it');
        }
    }

    public function testPrepareStoresTheScannerDecoderWhenThePageAsks(): void
    {
        $static = self::scenarioResult('prepare with the scanner')['caches'][self::STATIC];

        foreach (self::SCANNER as $file) {
            self::assertContains($file, $static);
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function refusedCopies(): iterable
    {
        yield 'a file it lists is missing' => ['prepare with a missing file'];
        yield 'not HTML' => ['prepare answered with JSON'];
        yield 'a Set-Cookie' => ['prepare answered with a cookie'];
        yield 'not a 200' => ['prepare answered 500'];
        yield 'another site\'s file' => ['prepare listing another site'];
        yield 'no file list' => ['prepare without a file list'];
        yield 'an unknown locale' => ['prepare of an unknown locale'];
    }

    #[DataProvider('refusedCopies')]
    public function testACopyThatCannotWorkIsNeverStored(string $scenario): void
    {
        $result = self::scenarioResult($scenario);

        self::assertSame([], $result['caches'][self::SHELLS] ?? [], 'a stored copy never points at a file the device lacks');
        $messages = $result['steps'][0]['messages'];
        self::assertContains($messages, [[], [['type' => 'live-shell:failed', 'locale' => 'en']]]);
    }

    public function testOnlineALiveEntryNavigationGetsTheNetworkAndReadsNoCache(): void
    {
        foreach (self::scenarioResult('online')['steps'] as $index => $step) {
            if ($index === 0) {
                continue;
            }

            self::assertSame('FRESH', $step['body']);
            self::assertNull($step['shellHeader']);
            self::assertSame([], $step['cacheReads'], 'no cache is even consulted while the network answers');
            self::assertSame([], $step['cacheWrites'], 'the real page is never stored');
        }
    }

    public function testOfflineALiveEntryNavigationGetsTheCopyOfItsLocale(): void
    {
        $steps = self::scenarioResult('offline')['steps'];

        // [0] prepare en, [1] prepare cs, [2] offline, then navigations
        self::assertSame('SHELL cs', $steps[3]['body'], 'a cs path gets the cs copy');
        self::assertSame('1', $steps[3]['shellHeader']);
        self::assertSame(200, $steps[3]['status']);
        self::assertSame([500, 4000], self::sorted($steps[3]['delays']), 'the one retry, then the copy');
        self::assertSame([], $steps[3]['reports'], 'a copy shown is no failure to report');

        self::assertSame('SHELL en', $steps[4]['body'], 'the printed name tag of the en locale');
        self::assertSame('SHELL cs', $steps[5]['body'], 'the cs name tag');
        self::assertSame('SHELL en', $steps[6]['body'], 'an event URL');
    }

    public function testATagPathWithoutALocaleOrALocaleNeverPreparedGetsTheStoredCopy(): void
    {
        $steps = self::scenarioResult('offline with one copy')['steps'];

        self::assertSame('SHELL en', $steps[2]['body'], '/live/{c}/t/12');
        self::assertSame('1', $steps[2]['shellHeader']);
        self::assertSame('SHELL en', $steps[3]['body'], '/de/live-results/{r} - a copy in English beats the offline page');
    }

    public function testADeviceThatKnowsItIsOfflineGetsTheCopyWithoutTheRetry(): void
    {
        $step = self::scenarioResult('device knows it is offline')['steps'][1];

        self::assertSame('SHELL en', $step['body']);
        self::assertCount(1, $step['networkFetches']);
        self::assertNotContains(500, $step['delays']);
    }

    public function testASilentNetworkGetsTheCopyAfterFourSeconds(): void
    {
        $step = self::scenarioResult('lie-fi')['steps'][1];

        self::assertSame('SHELL en', $step['body']);
        self::assertContains(4000, $step['delays']);
    }

    public function testWhatTheNetworkAnswersIsPassedThrough(): void
    {
        $steps = self::scenarioResult('network answers')['steps'];

        self::assertSame(302, $steps[1]['status'], 'a sign-in redirect stays a sign-in redirect');
        self::assertSame(403, $steps[2]['status']);
        self::assertSame('FRESH', $steps[3]['body'], 'a navigation that failed once is asked again');
        self::assertNull($steps[3]['shellHeader']);
        self::assertSame(['recovered'], $steps[3]['reports']);
    }

    public function testWithoutAStoredCopyTheOfflinePageAsBefore(): void
    {
        $steps = self::scenarioResult('no copy')['steps'];

        self::assertSame('OFFLINE', $steps[1]['body']);
        self::assertSame(['offline-page'], $steps[1]['reports']);
        self::assertContains(4000, $steps[3]['delays']);
        self::assertSame('FRESH', $steps[3]['body'], 'a slow network without a copy is waited for - no false offline page');
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function otherNavigations(): iterable
    {
        foreach (self::OTHER_PAGES as $index => $path) {
            yield $path => [$index];
        }
    }

    #[DataProvider('otherNavigations')]
    public function testNoOtherNavigationEverGetsTheCopy(int $index): void
    {
        // [0] prepare, [1] offline, then one navigation per page
        $step = self::scenarioResult('other navigations offline')['steps'][2 + $index];

        self::assertSame('OFFLINE', $step['body']);
        self::assertNull($step['shellHeader']);
    }

    public function testThePruneAfterADeployKeepsTheFilesOfAStoredCopy(): void
    {
        $result = self::scenarioResult('prune');

        self::assertTrue($result['steps'][0]['pruned']);
        self::assertSame(['/build/new.js', '/build/old-app.css', '/build/old-live.js', '/fonts/rubik/rubik-latin.woff2', '/offline.html'], $result['caches'][self::STATIC]);
    }

    public function testTheScannerDecoderIsCacheFirst(): void
    {
        $steps = self::scenarioResult('scanner files')['steps'];

        self::assertSame('/barcode-scanner/zbar-wasm-0.9.15/zbar.wasm', $steps[0]['body']);
        self::assertSame([self::STATIC . ' /barcode-scanner/zbar-wasm-0.9.15/zbar.wasm'], $steps[0]['cacheWrites']);
        self::assertSame('/barcode-scanner/zbar-wasm-0.9.15/zbar.wasm', $steps[2]['body'], 'offline: from the cache');
        self::assertSame([], $steps[2]['networkFetches']);
    }

    public function testTheWorkerReadsExactlyTheLiveEntryPathsOfTheSharedCases(): void
    {
        /** @var array{live: list<array{path: string, locale: null|string}>, notLive: list<string>} $routes */
        $routes = json_decode((string) file_get_contents(__DIR__ . '/fixtures/live-entry-routes.json'), true, flags: JSON_THROW_ON_ERROR);
        $answers = self::routesOf([...array_column($routes['live'], 'path'), ...$routes['notLive']]);

        foreach ($routes['live'] as $case) {
            self::assertSame(['locale' => $case['locale']], $answers[$case['path']], $case['path']);
        }

        foreach ($routes['notLive'] as $path) {
            self::assertNull($answers[$path], $path);
        }
    }

    /**
     * liveEntryRoute() of the worker for each path (ServiceWorkerLiveRoutesTest uses it for the router's paths).
     *
     * @param list<string> $paths
     * @return array<string, null|array{locale: null|string}>
     */
    public static function routesOf(array $paths): array
    {
        /** @var list<array{routes: list<array{path: string, route: null|array{locale: null|string}}>}> $decoded */
        $decoded = self::runHarness([['name' => 'routes', 'routes' => $paths]]);

        $answers = [];
        foreach ($decoded[0]['routes'] as $item) {
            $answers[$item['path']] = $item['route'];
        }

        return $answers;
    }

    /**
     * @param list<int> $delays
     * @return list<int>
     */
    private static function sorted(array $delays): array
    {
        $delays = array_values(array_unique($delays));
        sort($delays);

        return $delays;
    }

    /**
     * @return Scenario
     */
    private static function scenarioResult(string $scenario): array
    {
        if (self::$results === null) {
            /** @var list<Scenario> $decoded */
            $decoded = self::runHarness(self::scenarios());
            self::$results = array_column($decoded, null, 'name');
        }

        self::assertArrayHasKey($scenario, self::$results);

        return self::$results[$scenario];
    }

    /**
     * @param list<array<string, mixed>> $scenarios
     * @return list<array<string, mixed>>
     */
    private static function runHarness(array $scenarios): array
    {
        $node = new ExecutableFinder()->find('node');
        self::assertIsString($node, 'node is required to execute the service worker - it is part of the base image');

        $process = new Process([$node, __DIR__ . '/service-worker-live-harness.js']);
        $process->setInput((string) json_encode($scenarios));
        $process->mustRun();

        /** @var list<array<string, mixed>> */
        return json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * @param list<string> $assets
     */
    private static function shellHtml(string $marker, array $assets = self::ASSETS): string
    {
        return '<!DOCTYPE html><html><head><script type="application/json" id="msp-live-shell-assets">'
            . json_encode($assets) . '</script></head><body>' . $marker . '</body></html>';
    }

    /**
     * @return array<string, mixed>
     */
    private static function network(): array
    {
        $files = [];
        foreach ([...self::ASSETS, ...self::SCANNER] as $file) {
            $files[$file] = ['contentType' => 'application/octet-stream'];
        }

        return [
            ...$files,
            '/en/live-results-shell' => ['body' => self::shellHtml('SHELL en'), 'contentType' => 'text/html; charset=UTF-8'],
            '/zadavani-vysledku-shell' => ['body' => self::shellHtml('SHELL cs'), 'contentType' => 'text/html; charset=UTF-8'],
        ];
    }

    /**
     * The copies' bodies are the marker only when served - the harness answers the shell HTML, the test reads the
     * marker out of it.
     *
     * @return list<array<string, mixed>>
     */
    private static function scenarios(): array
    {
        $prepareEn = ['message' => ['type' => 'live-shell:prepare', 'locale' => 'en']];
        $prepareCs = ['message' => ['type' => 'live-shell:prepare', 'locale' => 'cs']];
        $offlinePage = ['static-v7' => ['/offline.html' => 'OFFLINE']];
        $round = '/en/live-results/' . self::ROUND;

        return [
            ['name' => 'prepare', 'network' => self::network(), 'steps' => [$prepareEn]],
            ['name' => 'prepare with the scanner', 'network' => self::network(), 'steps' => [['message' => ['type' => 'live-shell:prepare', 'locale' => 'en', 'scanner' => true]]]],
            ['name' => 'prepare with a missing file', 'network' => [...self::network(), '/build/live_entry.222.js' => ['status' => 404]], 'steps' => [$prepareEn]],
            ['name' => 'prepare answered with JSON', 'network' => ['/en/live-results-shell' => ['body' => self::shellHtml('x'), 'contentType' => 'application/json']], 'steps' => [$prepareEn]],
            ['name' => 'prepare answered with a cookie', 'network' => ['/en/live-results-shell' => ['body' => self::shellHtml('x'), 'contentType' => 'text/html', 'headers' => ['Set-Cookie' => 'PHPSESSID=1']]], 'steps' => [$prepareEn]],
            ['name' => 'prepare answered 500', 'network' => ['/en/live-results-shell' => ['body' => self::shellHtml('x'), 'contentType' => 'text/html', 'status' => 500]], 'steps' => [$prepareEn]],
            ['name' => 'prepare listing another site', 'network' => ['/en/live-results-shell' => ['body' => self::shellHtml('x', ['https://evil.example/build/x.js']), 'contentType' => 'text/html']], 'steps' => [$prepareEn]],
            ['name' => 'prepare without a file list', 'network' => ['/en/live-results-shell' => ['body' => '<html>x</html>', 'contentType' => 'text/html']], 'steps' => [$prepareEn]],
            ['name' => 'prepare of an unknown locale', 'network' => self::network(), 'steps' => [['message' => ['type' => 'live-shell:prepare', 'locale' => 'it']]]],
            ['name' => 'online', 'network' => self::network(), 'steps' => [
                $prepareEn,
                ['navigate' => $round],
                ['navigate' => '/live/' . self::COMPETITION . '/t/12'],
                ['navigate' => '/zadavani-vysledku/' . self::ROUND],
            ]],
            ['name' => 'offline', 'network' => self::network(), 'caches' => $offlinePage, 'steps' => [
                $prepareEn,
                $prepareCs,
                ['offline' => true],
                ['navigate' => '/zadavani-vysledku/' . self::ROUND],
                ['navigate' => '/en/live/' . self::COMPETITION . '/p/' . self::PARTICIPANT],
                ['navigate' => '/cs/live/' . self::COMPETITION . '/p/' . self::PARTICIPANT],
                ['navigate' => '/en/live-results/event/' . self::COMPETITION],
            ]],
            ['name' => 'offline with one copy', 'network' => self::network(), 'caches' => $offlinePage, 'steps' => [
                $prepareEn,
                ['offline' => true],
                ['navigate' => '/live/' . self::COMPETITION . '/t/12'],
                ['navigate' => '/de/live-results/' . self::ROUND],
            ]],
            ['name' => 'device knows it is offline', 'network' => [...self::network(), $round => 'reject'], 'onLine' => false, 'steps' => [$prepareEn, ['navigate' => $round]]],
            ['name' => 'lie-fi', 'network' => [...self::network(), $round => 'hang'], 'steps' => [$prepareEn, ['navigate' => $round]]],
            ['name' => 'network answers', 'network' => [
                ...self::network(),
                $round => ['status' => 302, 'body' => '', 'headers' => ['Location' => '/en/login']],
                '/en/live-results/event/' . self::COMPETITION => ['status' => 403, 'body' => 'no', 'contentType' => 'text/html'],
            ], 'steps' => [
                $prepareEn,
                ['navigate' => $round],
                ['navigate' => '/en/live-results/event/' . self::COMPETITION],
                ['navigate' => '/de/live-results/' . self::ROUND, 'failOnce' => true],
            ]],
            ['name' => 'no copy', 'network' => ['/en/live-results/event/' . self::COMPETITION => ['slow' => true]], 'caches' => $offlinePage, 'steps' => [
                ['offline' => true],
                ['navigate' => $round],
                ['offline' => false],
                ['navigate' => '/en/live-results/event/' . self::COMPETITION],
            ]],
            ['name' => 'other navigations offline', 'network' => self::network(), 'caches' => $offlinePage, 'steps' => [
                $prepareEn,
                ['offline' => true],
                ...array_map(static fn (string $path): array => ['navigate' => $path], self::OTHER_PAGES),
            ]],
            ['name' => 'prune', 'entrypoints' => ['entrypoints' => ['app' => ['js' => ['/build/new.js']]]], 'caches' => [
                'static-v7' => ['/offline.html' => 'OFFLINE', '/fonts/rubik/rubik-latin.woff2' => 'font', '/build/new.js' => 'new', '/build/old-live.js' => 'old', '/build/old-app.css' => 'old', '/build/stale.js' => 'stale'],
                'live-shell-v7' => ['/__live-shell/en.json' => ['locale' => 'en', 'assets' => ['/build/old-live.js', '/build/old-app.css'], 'storedAt' => 1]],
            ], 'steps' => [['prune' => true]]],
            ['name' => 'scanner files', 'network' => self::network(), 'steps' => [
                ['fetch' => '/barcode-scanner/zbar-wasm-0.9.15/zbar.wasm', 'mode' => 'cors', 'destination' => ''],
                ['offline' => true],
                ['fetch' => '/barcode-scanner/zbar-wasm-0.9.15/zbar.wasm', 'mode' => 'cors', 'destination' => ''],
            ]],
        ];
    }
}
