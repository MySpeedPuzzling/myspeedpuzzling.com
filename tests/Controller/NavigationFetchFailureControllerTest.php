<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use Psr\Log\AbstractLogger;
use SpeedPuzzling\Web\Controller\NavigationFetchFailureController;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;

final class NavigationFetchFailureControllerTest extends WebTestCase
{
    public function testValidReportIsAcceptedWithoutSession(): void
    {
        $browser = self::createClient();

        $browser->request('POST', '/-/navigation-fetch-failure', content: (string) json_encode(self::report()));

        $this->assertResponseStatusCodeSame(204);
        self::assertNull($browser->getResponse()->headers->get('Set-Cookie'));
    }

    public function testGarbageBodyIsAcceptedSilently(): void
    {
        $browser = self::createClient();

        $browser->request('POST', '/-/navigation-fetch-failure', content: 'not-json{{{');

        $this->assertResponseStatusCodeSame(204);
    }

    public function testGetIsNotAllowed(): void
    {
        $browser = self::createClient();

        $browser->request('GET', '/-/navigation-fetch-failure');

        $this->assertResponseStatusCodeSame(405);
    }

    /**
     * The worker posts to this path - the two must not drift apart.
     */
    public function testServiceWorkerReportsToThisEndpoint(): void
    {
        self::assertStringContainsString(
            "const NAVIGATION_FAILURE_REPORT_URL = '/-/navigation-fetch-failure';",
            (string) file_get_contents(__DIR__ . '/../../public/service-worker.js'),
        );
    }

    public function testRecoveredNavigationIsOnlyInfo(): void
    {
        self::assertSame(['info'], self::levelsLoggedFor(self::report()));
    }

    /**
     * The report reaching the server proves the device was online when it was
     * shown "You are offline".
     */
    public function testOfflinePageShownToAnOnlineClientIsAWarning(): void
    {
        self::assertSame(['warning'], self::levelsLoggedFor(self::report(['outcome' => 'offline-page', 'retryError' => 'TypeError: Load failed'])));
    }

    public function testReportWithoutAKnownOutcomeIsIgnored(): void
    {
        self::assertSame([], self::levelsLoggedFor(self::report(['outcome' => 'something'])));
        self::assertSame([], self::levelsLoggedFor(self::report(['page' => null])));
        self::assertSame([], self::levelsLoggedFor(['page' => '/en/hub']));
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private static function report(array $overrides = []): array
    {
        return [
            'outcome' => 'recovered',
            'page' => '/en/hub',
            'error' => 'TypeError: Load failed',
            'retryError' => null,
            'retryDelayMs' => 500,
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

        new NavigationFetchFailureController($logger)(Request::create('/-/navigation-fetch-failure', 'POST', content: (string) json_encode($payload)));

        return $logger->levels;
    }
}
