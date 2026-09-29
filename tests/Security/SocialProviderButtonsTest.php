<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Security;

use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\OverridesFeatureFlagEnv;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Every social button follows its provider's brand rules (a condition of
 * using Google's, Apple's and Meta's sign-in) and is drawn by ONE partial, so
 * /login and the settings connect buttons look the same: Google's light
 * button with the four-colour G, Apple's black button with Apple's own logo
 * artwork, Meta's round logo on Facebook Blue. Never a monochrome icon font
 * glyph in the site's colours.
 */
final class SocialProviderButtonsTest extends WebTestCase
{
    use OverridesFeatureFlagEnv;

    protected function tearDown(): void
    {
        $this->restoreFeatureFlagEnv();

        parent::tearDown();
    }

    public function testLoginPageRendersProviderBrandedButtons(): void
    {
        $this->enableAllProvidersPublicly();
        $browser = self::createClient();

        $crawler = $browser->request('GET', '/login');
        self::assertResponseIsSuccessful();

        $this->assertBrandedButtons($crawler, '/login/social/');
        self::assertStringContainsString('also for Instagram users', $crawler->text());
    }

    public function testSettingsConnectButtonsAreTheSameBrandedButtons(): void
    {
        $this->enableAllProvidersPublicly();
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $crawler = $browser->request('GET', '/en/edit-profile');
        self::assertResponseIsSuccessful();

        $this->assertBrandedButtons($crawler, '/account/social/');
        self::assertCount(0, $crawler->filter('.bi-google, .bi-apple, .bi-facebook'), 'Icon-font glyphs are not the providers\' logos');
    }

    private function assertBrandedButtons(Crawler $crawler, string $hrefPrefix): void
    {
        $google = $crawler->filter(sprintf('a.btn-google-signin[href^="%sgoogle"]', $hrefPrefix));
        self::assertCount(1, $google);
        self::assertSame('Continue with Google', trim($google->text()));
        $fills = $google->filter('svg.btn-google-signin-logo path')->each(
            static fn (Crawler $path): string => (string) $path->attr('fill'),
        );
        self::assertSame(['#EA4335', '#4285F4', '#FBBC05', '#34A853'], $fills);

        $apple = $crawler->filter(sprintf('a.btn-apple-signin[href^="%sapple"]', $hrefPrefix));
        self::assertCount(1, $apple);
        // Apple allows only "Sign in / Sign up / Continue with Apple"
        self::assertSame('Continue with Apple', trim($apple->text()));
        // Apple's "Left-aligned - Medium" artwork, uncropped
        self::assertSame('0 0 31 44', $apple->filter('svg.btn-apple-signin-logo')->attr('viewBox'));

        $facebook = $crawler->filter(sprintf('a.btn-facebook-signin[href^="%sfacebook"]', $hrefPrefix));
        self::assertCount(1, $facebook);
        self::assertSame('Continue with Facebook', trim($facebook->text()));
        self::assertCount(1, $facebook->filter('svg.btn-facebook-signin-logo'));

        foreach ([$google, $apple, $facebook] as $button) {
            self::assertStringNotContainsString('btn-outline', (string) $button->attr('class'));
        }
    }

    private function enableAllProvidersPublicly(): void
    {
        $this->overrideFeatureFlagEnv('SOCIAL_LOGIN_GOOGLE_ENABLED', true);
        $this->overrideFeatureFlagEnv('SOCIAL_LOGIN_APPLE_ENABLED', true);
        $this->overrideFeatureFlagEnv('SOCIAL_LOGIN_FACEBOOK_ENABLED', true);
        $this->overrideFeatureFlagEnv('SOCIAL_LOGIN_ADMIN_ONLY', false);
    }
}
