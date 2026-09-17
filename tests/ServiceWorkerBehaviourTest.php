<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Executes the real public/service-worker.js (tests/service-worker-harness.js)
 * against requests shaped like the ones browsers send, with every cache lookup
 * poisoned to answer "STALE" and the network answering "FRESH".
 *
 * ServiceWorkerRoutingTest pins HOW the v6 bug was fixed (ordering, no Accept
 * sniffing). This pins the rule itself, however the router is written next: a
 * document is never answered from a cache. v6 broke it for every real
 * navigation in Chromium and Firefox - reload, pull-to-refresh, PWA launch -
 * and because the strategy was stale-while-revalidate, people had to refresh
 * twice to see a time they had just added or a new notification: the first
 * refresh replayed the copy stored by the previous one.
 *
 * Node is part of the base image the suite runs in, locally and in CI. A
 * missing binary fails instead of skipping - a skipped guard guards nothing.
 */
final class ServiceWorkerBehaviourTest extends TestCase
{
    private const string CHROME_NAVIGATION_ACCEPT = 'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8,application/signed-exchange;v=b3;q=0.7';
    private const string FIREFOX_LEGACY_NAVIGATION_ACCEPT = 'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8';
    private const string SAFARI_NAVIGATION_ACCEPT = 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8';
    private const string TURBO_VISIT_ACCEPT = 'text/html, application/xhtml+xml';
    private const string CHROME_IMAGE_ACCEPT = 'image/avif,image/webp,image/apng,image/svg+xml,image/*,*/*;q=0.8';

    /** @var null|array<string, array{name: string, intercepted: bool, body: null|string, cacheReads: list<string>, cacheWrites: list<string>, networkFetches: list<string>}> */
    private static null|array $results = null;

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideDocumentRequests(): iterable
    {
        foreach (self::scenarios() as $scenario) {
            if ($scenario['expect'] === 'fresh-document') {
                yield $scenario['name'] => [$scenario['name']];
            }
        }
    }

    #[DataProvider('provideDocumentRequests')]
    public function testDocumentsAreNeverAnsweredFromACache(string $scenario): void
    {
        $result = self::answerTo($scenario);

        self::assertNotSame('STALE', $result['body'], 'The document was answered from a cache');
        self::assertSame([], $result['cacheReads'], 'Answering a document must not even consult a cache while the network works');
        self::assertSame([], $result['cacheWrites'], 'A document must never be written to a cache - it carries a name, times and admin links');

        // Not intercepting at all is as good as network-only; if it is
        // intercepted, the answer has to be the network's.
        if ($result['intercepted']) {
            self::assertSame('FRESH', $result['body']);
        }
    }

    /**
     * The positive control: proves the harness can see a cache hit at all, so
     * the assertions above cannot pass vacuously.
     */
    public function testImagesAreStillAnsweredFromTheCache(): void
    {
        $result = self::answerTo('image');

        self::assertTrue($result['intercepted']);
        self::assertSame('STALE', $result['body']);
    }

    public function testOfflineNavigationGetsTheOfflinePageNotACachedCopyOfThePage(): void
    {
        $result = self::answerTo('offline navigation');

        self::assertSame('OFFLINE', $result['body']);
        self::assertSame(['https://myspeedpuzzling.com/offline.html'], $result['cacheReads']);
    }

    /**
     * Second line of defence: even if routing regresses and a document reaches
     * the image strategy, its HTML must not be stored.
     */
    public function testHtmlAnsweredToAnImageRequestIsNotStored(): void
    {
        self::assertSame([], self::answerTo('image answered with html')['cacheWrites']);
    }

    /**
     * @return list<array{name: string, expect: string, path: string, mode: string, destination: string, accept: string, offline?: bool, cachedContentType?: string, networkContentType?: string}>
     */
    private static function scenarios(): array
    {
        return [
            ['name' => 'Chrome navigation / reload / PWA launch', 'expect' => 'fresh-document', 'path' => '/en/hub', 'mode' => 'navigate', 'destination' => 'document', 'accept' => self::CHROME_NAVIGATION_ACCEPT],
            ['name' => 'Firefox navigation advertising image types', 'expect' => 'fresh-document', 'path' => '/en/hub', 'mode' => 'navigate', 'destination' => 'document', 'accept' => self::FIREFOX_LEGACY_NAVIGATION_ACCEPT],
            ['name' => 'Safari navigation', 'expect' => 'fresh-document', 'path' => '/en/hub', 'mode' => 'navigate', 'destination' => 'document', 'accept' => self::SAFARI_NAVIGATION_ACCEPT],
            ['name' => 'Turbo Drive visit', 'expect' => 'fresh-document', 'path' => '/en/hub', 'mode' => 'same-origin', 'destination' => '', 'accept' => self::TURBO_VISIT_ACCEPT],
            ['name' => 'navigation to a URL that looks like an image', 'expect' => 'fresh-document', 'path' => '/en/result-image/abc.png', 'mode' => 'navigate', 'destination' => 'document', 'accept' => self::CHROME_NAVIGATION_ACCEPT],
            ['name' => 'navigation with an image-only Accept header', 'expect' => 'fresh-document', 'path' => '/en/hub', 'mode' => 'navigate', 'destination' => 'document', 'accept' => self::CHROME_IMAGE_ACCEPT],
            ['name' => 'iframe document', 'expect' => 'fresh-document', 'path' => '/en/hub', 'mode' => 'navigate', 'destination' => 'iframe', 'accept' => self::CHROME_NAVIGATION_ACCEPT],
            ['name' => 'image', 'expect' => 'cached', 'path' => '/img/speedpuzzling-logo.png', 'mode' => 'no-cors', 'destination' => 'image', 'accept' => self::CHROME_IMAGE_ACCEPT, 'cachedContentType' => 'image/png', 'networkContentType' => 'image/png'],
            ['name' => 'offline navigation', 'expect' => 'offline', 'path' => '/en/hub', 'mode' => 'navigate', 'destination' => 'document', 'accept' => self::CHROME_NAVIGATION_ACCEPT, 'offline' => true],
            ['name' => 'image answered with html', 'expect' => 'not-stored', 'path' => '/img/missing.png', 'mode' => 'no-cors', 'destination' => 'image', 'accept' => self::CHROME_IMAGE_ACCEPT, 'cachedContentType' => 'image/png'],
        ];
    }

    /**
     * @return array{name: string, intercepted: bool, body: null|string, cacheReads: list<string>, cacheWrites: list<string>, networkFetches: list<string>}
     */
    private static function answerTo(string $scenario): array
    {
        if (self::$results === null) {
            $node = new ExecutableFinder()->find('node');

            self::assertIsString($node, 'node is required to execute the service worker - it is part of the base image');

            $process = new Process([$node, __DIR__ . '/service-worker-harness.js']);
            $process->setInput((string) json_encode(self::scenarios()));
            $process->mustRun();

            /** @var list<array{name: string, intercepted: bool, body: null|string, cacheReads: list<string>, cacheWrites: list<string>, networkFetches: list<string>}> $decoded */
            $decoded = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);

            self::$results = array_column($decoded, null, 'name');
        }

        self::assertArrayHasKey($scenario, self::$results);

        return self::$results[$scenario];
    }
}
