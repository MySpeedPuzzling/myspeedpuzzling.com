<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use Psr\Log\AbstractLogger;
use SpeedPuzzling\Web\Controller\StaleDocumentController;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;

final class StaleDocumentControllerTest extends WebTestCase
{
    public function testValidBeaconIsAcceptedWithoutSession(): void
    {
        $browser = self::createClient();

        $browser->request('POST', '/-/stale-document', content: (string) json_encode(self::beacon()));

        $this->assertResponseStatusCodeSame(204);
        self::assertNull($browser->getResponse()->headers->get('Set-Cookie'));
    }

    public function testGarbageBodyIsAcceptedSilently(): void
    {
        $browser = self::createClient();

        $browser->request('POST', '/-/stale-document', content: 'not-json{{{');

        $this->assertResponseStatusCodeSame(204);
    }

    public function testGetIsNotAllowed(): void
    {
        $browser = self::createClient();

        $browser->request('GET', '/-/stale-document');

        $this->assertResponseStatusCodeSame(405);
    }

    public function testEveryPageCarriesTheRenderTimestampAndTheBeaconUrl(): void
    {
        $browser = self::createClient();

        $before = time();
        $crawler = $browser->request('GET', '/en/hub');
        $after = time();

        $this->assertResponseIsSuccessful();

        $renderedAt = (int) $crawler->filter('meta[name="msp-rendered-at"]')->attr('content');

        self::assertGreaterThanOrEqual($before, $renderedAt);
        self::assertLessThanOrEqual($after, $renderedAt);
        self::assertStringContainsString("sendBeacon('/-/stale-document'", (string) $browser->getResponse()->getContent());
    }

    public function testDocumentServedByTheWorkerIsAWarning(): void
    {
        self::assertSame(['warning'], self::levelsLoggedFor(self::beacon()));
    }

    /**
     * Restoring a closed or discarded tab reuses the HTTP-cache copy whatever
     * Cache-Control says. That is the browser's decision, not a bug to page on.
     */
    public function testDocumentRestoredFromTheHttpCacheIsOnlyInfo(): void
    {
        self::assertSame(['info'], self::levelsLoggedFor(self::beacon(['delivery' => 'cache'])));
    }

    public function testStillStaleAfterHealingIsAlwaysAWarning(): void
    {
        self::assertSame(['warning'], self::levelsLoggedFor(self::beacon(['delivery' => 'cache', 'retry' => true])));
    }

    public function testBeaconWithoutAnAgeIsIgnored(): void
    {
        self::assertSame([], self::levelsLoggedFor(['page' => '/en/hub']));
        self::assertSame([], self::levelsLoggedFor(self::beacon(['age' => 'yesterday'])));
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private static function beacon(array $overrides = []): array
    {
        return [
            'page' => '/en/hub',
            'age' => 86400,
            'type' => 'reload',
            'delivery' => '',
            'transferSize' => 0,
            'throughWorker' => true,
            'discarded' => false,
            'retry' => false,
            ...$overrides,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return list<string>
     */
    private static function levelsLoggedFor(array $payload): array
    {
        $logger = new class extends AbstractLogger {
            /** @var list<string> */
            public array $levels = [];

            /**
             * @param array<mixed> $context
             */
            public function log(mixed $level, string|\Stringable $message, array $context = []): void
            {
                assert(is_string($level));
                $this->levels[] = $level;
            }
        };

        new StaleDocumentController($logger)(Request::create('/-/stale-document', 'POST', content: (string) json_encode($payload)));

        return $logger->levels;
    }
}
