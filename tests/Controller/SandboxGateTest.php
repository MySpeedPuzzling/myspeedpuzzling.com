<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use SpeedPuzzling\Web\EventSubscriber\SandboxNoIndexSubscriber;
use SpeedPuzzling\Web\Services\Sandbox\SandboxGate;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The sandbox's welcome page (docs/features/sandbox/README.md "Search engines"): every page answers it with 401 until
 * "Enter" - a POST no crawler makes - and what machines call stays open. Off - production - none of it.
 */
final class SandboxGateTest extends WebTestCase
{
    protected function tearDown(): void
    {
        unset($_ENV['SANDBOX'], $_SERVER['SANDBOX']);
        parent::tearDown();
    }

    public function testEveryPageShowsTheWelcomePageUntilEntered(): void
    {
        $client = self::sandboxClient();

        $crawler = $client->request('GET', '/en/for-developers?tab=api');

        self::assertResponseStatusCodeSame(401);
        self::assertSame(SandboxNoIndexSubscriber::HEADER_VALUE, $client->getResponse()->headers->get('X-Robots-Tag'));
        self::assertStringContainsString('no-store', (string) $client->getResponse()->headers->get('Cache-Control'));
        self::assertCount(1, $crawler->filter('[data-testid="sandbox-gate"]'));
        self::assertCount(1, $crawler->filter('meta[name="robots"][content="noindex, nofollow, noarchive"]'));
        self::assertSame('/en/for-developers?tab=api', $crawler->filter('input[name="return"]')->attr('value'));
        self::assertSame('en', $crawler->filter('html')->attr('lang'));
    }

    public function testEnteringRemembersItAndContinuesToThePageAskedFor(): void
    {
        $client = self::sandboxClient();
        $crawler = $client->request('GET', '/de/fuer-entwickler');
        self::assertSame('de', $crawler->filter('html')->attr('lang'));

        $client->submit($crawler->filter('form')->form());

        self::assertResponseStatusCodeSame(303);
        self::assertResponseRedirects('/de/fuer-entwickler');
        $cookie = $client->getCookieJar()->get(SandboxGate::COOKIE, '/', 'sandbox.myspeedpuzzling.com');
        self::assertNotNull($cookie);
        self::assertTrue($cookie->isHttpOnly());
        self::assertTrue($cookie->isSecure());

        $client->followRedirect();

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-testid="sandbox-banner"]');
    }

    public function testEnteringNeverRedirectsOffTheSite(): void
    {
        $client = self::sandboxClient();

        $client->request('POST', '/sandbox/enter', ['return' => '//evil.example/']);

        self::assertResponseRedirects('/');
    }

    public function testUnknownUrlsShowTheWelcomePageToo(): void
    {
        $client = self::sandboxClient();

        $client->request('GET', '/en/no-such-page-at-all');

        self::assertResponseStatusCodeSame(401);
        self::assertSelectorExists('[data-testid="sandbox-gate"]');
    }

    public function testWhatMachinesCallStaysOpen(): void
    {
        $client = self::sandboxClient();

        $client->request('GET', '/api/v1/me');

        // The API's own answer (no token), not the welcome page
        self::assertResponseStatusCodeSame(401);
        self::assertSelectorNotExists('[data-testid="sandbox-gate"]');
        self::assertSame('Bearer', $client->getResponse()->headers->get('WWW-Authenticate'));

        $client->request('GET', '/-/health-check/liveness');

        self::assertResponseIsSuccessful();
    }

    public function testProductionHasNoWelcomePage(): void
    {
        $client = self::createClient([], ['HTTP_HOST' => 'myspeedpuzzling.com', 'HTTPS' => 'on']);

        $client->request('GET', '/en/for-developers');

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('[data-testid="sandbox-gate"]');

        $client->request('POST', '/sandbox/enter');

        self::assertResponseStatusCodeSame(404);
    }

    private static function sandboxClient(): KernelBrowser
    {
        $_ENV['SANDBOX'] = $_SERVER['SANDBOX'] = '1';

        return self::createClient([], ['HTTP_HOST' => 'sandbox.myspeedpuzzling.com', 'HTTPS' => 'on']);
    }
}
