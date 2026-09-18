<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\LogRecord;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class AssetLoadFailureControllerTest extends WebTestCase
{
    private const string BROWSER = 'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Mobile Safari/537.36';

    public function testValidBeaconIsAcceptedWithoutSession(): void
    {
        $browser = self::createClient();

        $browser->request('POST', '/-/asset-load-failure', content: (string) json_encode([
            'url' => 'https://myspeedpuzzling.com/build/app.abc123.js',
            'page' => '/en/puzzles',
            'controlled' => true,
            'retry' => false,
        ]));

        $this->assertResponseStatusCodeSame(204);
        self::assertNull($browser->getResponse()->headers->get('Set-Cookie'));
    }

    public function testGarbageBodyIsAcceptedSilently(): void
    {
        $browser = self::createClient();

        $browser->request('POST', '/-/asset-load-failure', content: 'not-json{{{');

        $this->assertResponseStatusCodeSame(204);
    }

    public function testGetIsNotAllowed(): void
    {
        $browser = self::createClient();

        $browser->request('GET', '/-/asset-load-failure');

        $this->assertResponseStatusCodeSame(405);
    }

    public function testCrawlerReportStaysOutOfSentry(): void
    {
        $record = $this->logged([
            'v' => 2,
            'url' => 'https://myspeedpuzzling.com/build/entrypoints.json',
            'healing' => false,
            'refetch' => 'unreachable',
            'rendered' => time(),
        ], 'Mozilla/5.0 (compatible; YandexRenderResourcesBot/1.0; +http://yandex.com/bots) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/81.0.4044.268');

        self::assertSame(Level::Info, $record->level);
        self::assertSame('automation', $record->context['verdict']);
    }

    public function testFreshPageMissingAnAssetReachesSentry(): void
    {
        $record = $this->logged([
            'v' => 2,
            'url' => 'https://myspeedpuzzling.com/build/app.0000dead.js',
            'healing' => true,
            'refetch' => 'http-404',
            'rendered' => time() - 3600,
        ]);

        self::assertSame(Level::Warning, $record->level);
        self::assertSame('missing_from_fresh_page', $record->context['verdict']);
        self::assertGreaterThanOrEqual(3600, $record->context['page_age_seconds']);
    }

    public function testStillBrokenAfterTheHealReachesSentry(): void
    {
        $record = $this->logged([
            'v' => 2,
            'url' => 'https://myspeedpuzzling.com/build/entrypoints.json',
            'controlled' => true,
            'retry' => true,
            'healing' => false,
            'refetch' => 'intact',
            'rendered' => time(),
        ]);

        self::assertSame(Level::Warning, $record->level);
        self::assertSame('heal_failed', $record->context['verdict']);
        self::assertStringStartsWith('Client failed to load a build asset', $record->message);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function logged(array $payload, string $userAgent = self::BROWSER): LogRecord
    {
        $browser = self::createClient();
        $handler = $this->captureLogs($browser);

        $browser->request('POST', '/-/asset-load-failure', server: ['HTTP_USER_AGENT' => $userAgent], content: (string) json_encode($payload));

        $this->assertResponseStatusCodeSame(204);

        $records = array_values(array_filter(
            $handler->getRecords(),
            static fn (LogRecord $record): bool => str_starts_with($record->message, 'Client failed to load a build asset'),
        ));

        self::assertCount(1, $records);

        return $records[0];
    }

    private function captureLogs(KernelBrowser $browser): TestHandler
    {
        $browser->disableReboot();

        $handler = new TestHandler();
        self::getContainer()->get('logger')->pushHandler($handler);

        return $handler;
    }
}
