<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Security;

use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\OverridesFeatureFlagEnv;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Google's Sign in with Google branding guidelines are a condition of using
 * the API: light button (.btn-google-signin, white with a #747775 border) and
 * the standard four-colour "G" - never a monochrome icon.
 */
final class GoogleSignInButtonTest extends WebTestCase
{
    use OverridesFeatureFlagEnv;

    protected function tearDown(): void
    {
        $this->restoreFeatureFlagEnv();

        parent::tearDown();
    }

    public function testLoginPageRendersTheBrandedGoogleButton(): void
    {
        $this->enableGooglePublicly();
        $browser = self::createClient();

        $crawler = $browser->request('GET', '/login');
        self::assertResponseIsSuccessful();

        $button = $crawler->filter('a.btn-google-signin[href^="/login/social/google"]');
        self::assertCount(1, $button);
        self::assertStringNotContainsString('btn-outline', (string) $button->attr('class'));
        self::assertStringContainsString('Continue with Google', $button->text());
        $this->assertStandardGoogleLogo($button);
    }

    public function testConnectButtonInSettingsUsesTheColouredLogo(): void
    {
        $this->enableGooglePublicly();
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $crawler = $browser->request('GET', '/en/edit-profile');
        self::assertResponseIsSuccessful();

        $button = $crawler->filter('a.btn-google-signin[href="/account/social/google/connect"]');
        self::assertCount(1, $button);
        self::assertCount(0, $button->filter('.bi-google'), 'A monochrome G is against the branding rules');
        $this->assertStandardGoogleLogo($button);
    }

    private function assertStandardGoogleLogo(Crawler $button): void
    {
        $fills = $button->filter('svg.btn-google-signin-logo path')->each(
            static fn (Crawler $path): string => (string) $path->attr('fill'),
        );

        self::assertSame(['#EA4335', '#4285F4', '#FBBC05', '#34A853'], $fills);
    }

    private function enableGooglePublicly(): void
    {
        $this->overrideFeatureFlagEnv('SOCIAL_LOGIN_GOOGLE_ENABLED', true);
        $this->overrideFeatureFlagEnv('SOCIAL_LOGIN_ADMIN_ONLY', false);
    }
}
