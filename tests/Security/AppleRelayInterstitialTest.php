<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Security;

use SpeedPuzzling\Web\Tests\OverridesFeatureFlagEnv;
use SpeedPuzzling\Web\Tests\TestDouble\AppleIdTokenFactory;
use SpeedPuzzling\Web\Tests\TestDouble\SocialLoginHttpMock;
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
    use OverridesFeatureFlagEnv;

    /** @var array<string, string|false> */
    private array $originalStringEnv = [];

    protected function setUp(): void
    {
        SocialLoginHttpMock::reset();

        $this->overrideFeatureFlagEnv('SOCIAL_LOGIN_APPLE_ENABLED', true);
        $this->overrideFeatureFlagEnv('SOCIAL_LOGIN_ADMIN_ONLY', false);
        $this->overrideStringEnv('APPLE_CLIENT_ID', AppleIdTokenFactory::CLIENT_ID);
        $this->overrideStringEnv('APPLE_TEAM_ID', 'TESTTEAM01');
        $this->overrideStringEnv('APPLE_KEY_ID', 'TESTKEY001');
        $this->overrideStringEnv('APPLE_PRIVATE_KEY', AppleIdTokenFactory::clientSecretKeyPem());
    }

    protected function tearDown(): void
    {
        $this->restoreFeatureFlagEnv();

        foreach ($this->originalStringEnv as $name => $original) {
            if ($original === false) {
                unset($_ENV[$name], $_SERVER[$name]);

                continue;
            }

            $_ENV[$name] = $original;
            $_SERVER[$name] = $original;
        }

        $this->originalStringEnv = [];

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
        self::assertSame('Create a new account?', trim($crawler->filter('h1')->text()));
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
        self::assertSame('Do you already have a MySpeedPuzzling account?', trim($crawler->filter('h1')->text()));
        self::assertStringContainsString('Apple is hiding your e-mail address', $crawler->text());

        // "I already have an account" is the primary action, "create" the secondary one
        $primary = $crawler->filter('form[action$="/register/social/sign-in"] button.btn-primary');
        self::assertCount(1, $primary);
        self::assertStringContainsString('I already have an account', $primary->text());
        self::assertCount(0, $crawler->filter('form[action$="/register/social"] button.btn-primary'));
        self::assertCount(1, $crawler->filter('form[action$="/register/social"] button.btn-outline-secondary'));
    }

    private function overrideStringEnv(string $name, string $value): void
    {
        if (!array_key_exists($name, $this->originalStringEnv)) {
            $original = $_ENV[$name] ?? false;
            $this->originalStringEnv[$name] = is_string($original) ? $original : false;
        }

        $_ENV[$name] = $value;
        $_SERVER[$name] = $value;
    }
}
