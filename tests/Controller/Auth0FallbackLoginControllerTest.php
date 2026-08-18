<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use SpeedPuzzling\Web\Tests\OverridesFeatureFlagEnv;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The transition-window escape hatch (Stage B -> Phase 6 of issue #147):
 * /login/auth0 starts the same hosted Universal Login redirect that /login
 * performed before the flip, and the native login page links to it. Both sides
 * of the flag are covered - turning the hatch off must be a tested path too.
 */
final class Auth0FallbackLoginControllerTest extends WebTestCase
{
    use OverridesFeatureFlagEnv;

    protected function tearDown(): void
    {
        $this->restoreFeatureFlagEnv();

        parent::tearDown();
    }

    public function testFallbackRedirectsToAuth0(): void
    {
        $browser = $this->createClientWithFallback(true);

        $browser->request('GET', '/login/auth0');

        $location = (string) $browser->getResponse()->headers->get('Location');
        self::assertResponseStatusCodeSame(302);
        self::assertStringContainsString('auth0.com', $location);
    }

    public function testFallbackIsGoneWhenFlagIsOff(): void
    {
        $browser = $this->createClientWithFallback(false);

        $browser->request('GET', '/login/auth0');

        self::assertResponseStatusCodeSame(404);
    }

    public function testLoginPageCarriesTheFallbackLink(): void
    {
        $this->overrideFeatureFlagEnv('NATIVE_LOGIN_ENABLED', true);
        $browser = $this->createClientWithFallback(true);

        $crawler = $browser->request('GET', '/login');

        self::assertResponseIsSuccessful();
        self::assertGreaterThan(0, $crawler->filter('a[href="/login/auth0"]')->count());
    }

    public function testLoginPageHidesTheFallbackLinkWhenFlagIsOff(): void
    {
        $this->overrideFeatureFlagEnv('NATIVE_LOGIN_ENABLED', true);
        $browser = $this->createClientWithFallback(false);

        $crawler = $browser->request('GET', '/login');

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('a[href="/login/auth0"]'));
    }

    private function createClientWithFallback(bool $enabled): KernelBrowser
    {
        // The flag is a runtime env placeholder, so a kernel booted after this sees it
        $this->overrideFeatureFlagEnv('AUTH0_FALLBACK_LOGIN_ENABLED', $enabled);

        return self::createClient();
    }
}
