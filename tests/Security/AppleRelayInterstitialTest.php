<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Security;

use SpeedPuzzling\Web\Tests\ConfiguresSocialLoginProviders;
use SpeedPuzzling\Web\Tests\TestDouble\AppleIdTokenFactory;
use SpeedPuzzling\Web\Tests\TestDouble\SocialLoginHttpMock;
use SpeedPuzzling\Web\Value\OauthProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Apple "Hide My Email": the relay address can never match an existing
 * account, so the rule-4 interstitial explains that and makes "I already have
 * an account" the primary action.
 */
final class AppleRelayInterstitialTest extends WebTestCase
{
    use ConfiguresSocialLoginProviders;

    protected function setUp(): void
    {
        SocialLoginHttpMock::reset();

        $this->enableSocialLoginProvider(OauthProvider::Apple);
    }

    protected function tearDown(): void
    {
        $this->restoreSocialLoginEnv();

        parent::tearDown();
    }

    public function testRelayAddressLeadsWithSignInAndConnect(): void
    {
        $browser = self::createClient();
        $suffix = bin2hex(random_bytes(4));

        $crawler = $this->interstitialFor($browser, [
            'sub' => "apple-relay-{$suffix}",
            'email' => "x{$suffix}@privaterelay.appleid.com",
            'email_verified' => 'true',
            'is_private_email' => 'true',
        ]);

        $this->assertRelayVariant($crawler);
    }

    /**
     * Only the `is_private_email` claim marks this address as a relay - the
     * relay copy can appear only if the flag survived parking in the cache.
     */
    public function testPrivateRelayFlagSurvivesParking(): void
    {
        $browser = self::createClient();
        $suffix = bin2hex(random_bytes(4));

        $crawler = $this->interstitialFor($browser, [
            'sub' => "apple-flag-{$suffix}",
            'email' => "hidden{$suffix}@example.com",
            'email_verified' => true,
            'is_private_email' => true,
        ]);

        $this->assertRelayVariant($crawler);
    }

    public function testRealAppleAddressKeepsTheStandardInterstitial(): void
    {
        $browser = self::createClient();
        $suffix = bin2hex(random_bytes(4));

        $crawler = $this->interstitialFor($browser, [
            'sub' => "apple-real-{$suffix}",
            'email' => "real{$suffix}@example.com",
            'email_verified' => 'true',
            'is_private_email' => 'false',
        ]);

        self::assertCount(0, $crawler->filter('[data-social-register-relay]'));
        self::assertSame('One more step', trim($crawler->filter('h1')->text()));
        self::assertCount(1, $crawler->filter('form[action$="/register/social"] button.btn-primary'));
    }

    /**
     * @param array<string, mixed> $claims
     */
    private function interstitialFor(KernelBrowser $browser, array $claims): Crawler
    {
        $browser->request('GET', '/login/social/apple');
        self::assertResponseRedirects();
        parse_str((string) parse_url((string) $browser->getResponse()->headers->get('Location'), PHP_URL_QUERY), $query);
        $state = $query['state'] ?? null;
        self::assertIsString($state);

        AppleIdTokenFactory::queueTokenExchange($claims);
        $browser->request('POST', '/login/social/apple/callback', ['state' => $state, 'code' => 'fake-apple-code']);

        self::assertResponseRedirects();
        $location = (string) $browser->getResponse()->headers->get('Location');
        self::assertStringContainsString('/register/social?token=', $location);

        $crawler = $browser->request('GET', $location);
        self::assertResponseIsSuccessful();

        return $crawler;
    }

    private function assertRelayVariant(Crawler $crawler): void
    {
        self::assertCount(1, $crawler->filter('[data-social-register-relay]'));
        self::assertSame('Have you used MySpeedPuzzling before?', trim($crawler->filter('h1')->text()));
        self::assertStringContainsString('Apple is hiding your email', $crawler->text());

        // "I already have an account" is the primary action, "create" the secondary one
        $primary = $crawler->filter('form[action$="/register/social/sign-in"] button.btn-primary');
        self::assertCount(1, $primary);
        self::assertStringContainsString('Yes — sign in and connect Apple', $primary->text());
        self::assertCount(0, $crawler->filter('form[action$="/register/social"] button.btn-primary'));
        self::assertCount(1, $crawler->filter('form[action$="/register/social"] button.btn-outline-secondary'));
    }
}
