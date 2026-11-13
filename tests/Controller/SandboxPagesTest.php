<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use SpeedPuzzling\Web\EventSubscriber\SandboxNoIndexSubscriber;
use SpeedPuzzling\Web\Services\Sandbox\SandboxGate;
use Symfony\Component\BrowserKit\Cookie;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The sandbox switch on a real page (docs/features/sandbox/README.md): banner, noindex meta + header, and the For
 * developers page naming the installation it is read on. Off - production - none of it.
 */
final class SandboxPagesTest extends WebTestCase
{
    protected function tearDown(): void
    {
        unset($_ENV['SANDBOX'], $_SERVER['SANDBOX'], $_ENV['SANDBOX_URL'], $_SERVER['SANDBOX_URL']);
        parent::tearDown();
    }

    public function testTheSandboxSaysWhatItIsAndKeepsSearchEnginesOut(): void
    {
        $_ENV['SANDBOX'] = $_SERVER['SANDBOX'] = '1';
        $_ENV['SANDBOX_URL'] = $_SERVER['SANDBOX_URL'] = 'https://sandbox.myspeedpuzzling.com';
        $client = self::createClient([], ['HTTP_HOST' => 'sandbox.myspeedpuzzling.com', 'HTTPS' => 'on']);
        $client->getCookieJar()->set(new Cookie(SandboxGate::COOKIE, '1', null, '/', 'sandbox.myspeedpuzzling.com'));

        $crawler = $client->request('GET', '/en/for-developers');

        self::assertResponseIsSuccessful();
        self::assertSame(SandboxNoIndexSubscriber::HEADER_VALUE, $client->getResponse()->headers->get('X-Robots-Tag'));
        self::assertCount(1, $crawler->filter('[data-testid="sandbox-banner"]'));
        self::assertCount(1, $crawler->filter('meta[name="robots"]'), "One robots tag: the sandbox one replaces the page's own");
        self::assertCount(1, $crawler->filter('meta[name="robots"][content="noindex, nofollow, noarchive"]'));
        self::assertCount(1, $crawler->filter('[data-testid="for-developers-sandbox"]'));
        self::assertStringContainsString('sandbox.myspeedpuzzling.com', $crawler->filter('[data-testid="for-developers-sandbox"]')->text());
        // The examples name the sandbox itself, not production
        self::assertStringContainsString('https://sandbox.myspeedpuzzling.com/api/v1/me', (string) $client->getResponse()->getContent());
        self::assertStringNotContainsString('https://myspeedpuzzling.com/api/v1/me', (string) $client->getResponse()->getContent());
    }

    public function testProductionShowsNoneOfIt(): void
    {
        $client = self::createClient([], ['HTTP_HOST' => 'myspeedpuzzling.com', 'HTTPS' => 'on']);

        $crawler = $client->request('GET', '/en/for-developers');

        self::assertResponseIsSuccessful();
        // (the test environment sets a plain noindex of its own - the sandbox's is not there)
        self::assertNotSame(SandboxNoIndexSubscriber::HEADER_VALUE, $client->getResponse()->headers->get('X-Robots-Tag'));
        self::assertCount(0, $crawler->filter('[data-testid="sandbox-banner"]'));
        self::assertCount(0, $crawler->filter('meta[name="robots"][content="noindex, nofollow, noarchive"]'));
        self::assertCount(0, $crawler->filter('[data-testid="for-developers-sandbox"]'));
        self::assertStringContainsString('https://myspeedpuzzling.com/api/v1/me', (string) $client->getResponse()->getContent());
    }
}
