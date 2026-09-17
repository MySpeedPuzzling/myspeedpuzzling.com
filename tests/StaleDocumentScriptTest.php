<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Runs the inline stale-document script of base.html.twig, exactly as a page
 * rendered it, in a fake browser (tests/stale-document-harness.js).
 *
 * The script fails open on purpose - anything unexpected does nothing - so a
 * mistake in it is silent forever: no report, no reload, no error. And the one
 * failure that is not silent is worse: reloading a healthy page.
 */
final class StaleDocumentScriptTest extends WebTestCase
{
    private const int RENDERED_AT = 1_800_000_000;
    private const int DAY = 86400;

    /** @var null|array<string, array{name: string, probes: int, probe?: array{url: string, method: string, cache: string}, beacons: list<array{url: string, payload: array<string, mixed>}>, reloads: int, cacheDeletes: list<string>}> */
    private static null|array $results = null;

    public function testFreshDocumentCostsNothing(): void
    {
        $did = $this->whatTheScriptDid('fresh');

        self::assertSame(0, $did['probes'], 'A fresh page must not even ask the server for the time');
        self::assertSame([], $did['beacons']);
        self::assertSame(0, $did['reloads']);
    }

    public function testStaleDocumentIsReportedDroppedFromEveryCacheAndReloaded(): void
    {
        $did = $this->whatTheScriptDid('stale');

        self::assertSame(['url' => '/service-worker.js', 'method' => 'HEAD', 'cache' => 'no-store'], $did['probe'] ?? null);
        self::assertCount(1, $did['beacons']);
        self::assertSame('/-/stale-document', $did['beacons'][0]['url']);
        self::assertSame(
            ['page' => '/en/hub', 'age' => self::DAY, 'type' => 'reload', 'delivery' => '', 'transferSize' => 0, 'throughWorker' => true, 'discarded' => false, 'retry' => false],
            $did['beacons'][0]['payload'],
        );
        self::assertSame(
            ['static-v7 https://myspeedpuzzling.com/en/hub', 'images-v7 https://myspeedpuzzling.com/en/hub'],
            $did['cacheDeletes'],
        );
        self::assertSame(1, $did['reloads']);
    }

    /**
     * The visitor's clock only raises suspicion. A phone running a day fast
     * makes every page look a day old - the server's Date header says otherwise.
     */
    public function testWrongClientClockReportsAndReloadsNothing(): void
    {
        $did = $this->whatTheScriptDid('client clock a day fast');

        self::assertSame(1, $did['probes']);
        self::assertSame([], $did['beacons']);
        self::assertSame(0, $did['reloads']);
    }

    public function testStillStaleAfterHealingIsReportedButNeverReloadedAgain(): void
    {
        $did = $this->whatTheScriptDid('stale again after healing');

        self::assertCount(1, $did['beacons']);
        self::assertTrue($did['beacons'][0]['payload']['retry']);
        self::assertSame([], $did['cacheDeletes']);
        self::assertSame(0, $did['reloads'], 'One heal per session - a second reload is how a loop starts');
    }

    /**
     * Browsers reuse stale HTML for history navigations on purpose.
     */
    public function testBackForwardNavigationIsLeftAlone(): void
    {
        self::assertSame(0, $this->whatTheScriptDid('stale via back_forward')['probes']);
    }

    /**
     * No worker, no way for this app to have cached the page - and it is what
     * keeps crawlers, which render HTML fetched days earlier, out entirely.
     */
    public function testPageWithoutAServiceWorkerIsLeftAlone(): void
    {
        self::assertSame(0, $this->whatTheScriptDid('stale without a service worker')['probes']);
    }

    /**
     * @return array{name: string, probes: int, probe?: array{url: string, method: string, cache: string}, beacons: list<array{url: string, payload: array<string, mixed>}>, reloads: int, cacheDeletes: list<string>}
     */
    private function whatTheScriptDid(string $scenario): array
    {
        if (self::$results === null) {
            $browser = self::createClient();
            $browser->request('GET', '/en/hub');

            self::assertSame(
                1,
                preg_match('~<meta name="msp-rendered-at"[^>]*>\s*<script>(.*?)</script>~s', (string) $browser->getResponse()->getContent(), $matches),
                'The stale-document script must directly follow its <meta> in base.html.twig',
            );

            $stale = ['renderedAt' => self::RENDERED_AT, 'clientNow' => self::RENDERED_AT + self::DAY, 'serverNow' => self::RENDERED_AT + self::DAY, 'controlled' => true, 'navigationType' => 'reload', 'healedBefore' => false];

            $node = new ExecutableFinder()->find('node');

            self::assertIsString($node, 'node is required to execute the script - it is part of the base image');

            $process = new Process([$node, __DIR__ . '/stale-document-harness.js']);
            $process->setInput((string) json_encode([
                'script' => $matches[1],
                'scenarios' => [
                    ['name' => 'fresh', ...$stale, 'clientNow' => self::RENDERED_AT + 2, 'serverNow' => self::RENDERED_AT + 2],
                    ['name' => 'stale', ...$stale],
                    ['name' => 'client clock a day fast', ...$stale, 'serverNow' => self::RENDERED_AT + 2],
                    ['name' => 'stale again after healing', ...$stale, 'healedBefore' => true],
                    ['name' => 'stale via back_forward', ...$stale, 'navigationType' => 'back_forward'],
                    ['name' => 'stale without a service worker', ...$stale, 'controlled' => false],
                ],
            ]));
            $process->mustRun();

            /** @var list<array{name: string, probes: int, probe?: array{url: string, method: string, cache: string}, beacons: list<array{url: string, payload: array<string, mixed>}>, reloads: int, cacheDeletes: list<string>}> $decoded */
            $decoded = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);

            self::$results = array_column($decoded, null, 'name');
        }

        self::assertArrayHasKey($scenario, self::$results);

        return self::$results[$scenario];
    }
}
