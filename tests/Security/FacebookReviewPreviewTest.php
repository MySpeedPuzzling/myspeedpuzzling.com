<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Security;

use SpeedPuzzling\Web\Services\SocialLogin\SocialLoginSettings;
use SpeedPuzzling\Web\Tests\ConfiguresSocialLoginProviders;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use SpeedPuzzling\Web\Value\OauthProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\Cookie;

/**
 * Meta App Review preview: /login?facebook_preview=1 shows the Facebook button
 * while SOCIAL_LOGIN_FACEBOOK_ENABLED still hides it, and a one-day cookie
 * carries that to /register and edit profile. Removed with the flag.
 */
final class FacebookReviewPreviewTest extends WebTestCase
{
    use ConfiguresSocialLoginProviders;

    protected function tearDown(): void
    {
        $this->restoreSocialLoginEnv();

        parent::tearDown();
    }

    public function testFlagOffHidesFacebookWithoutPreview(): void
    {
        $this->enableSocialLoginProvider(OauthProvider::Facebook);
        $this->hideFacebookButtons();
        $browser = self::createClient();

        $crawler = $browser->request('GET', '/login');
        self::assertResponseIsSuccessful();

        self::assertCount(0, $crawler->filter('a.btn-facebook-signin'));
        self::assertNull($browser->getCookieJar()->get(SocialLoginSettings::FACEBOOK_PREVIEW_COOKIE));
    }

    public function testPreviewParameterShowsFacebookAndSetsTheCookie(): void
    {
        $this->enableSocialLoginProvider(OauthProvider::Facebook);
        $this->hideFacebookButtons();
        $browser = self::createClient();

        $crawler = $browser->request('GET', '/login?facebook_preview=1');
        self::assertResponseIsSuccessful();

        self::assertCount(1, $crawler->filter('a.btn-facebook-signin[href^="/login/social/facebook"]'));
        $cookie = $browser->getCookieJar()->get(SocialLoginSettings::FACEBOOK_PREVIEW_COOKIE);
        self::assertNotNull($cookie);
        self::assertSame('1', $cookie->getValue());
        $expiresIn = (int) $cookie->getExpiresTime() - time();
        self::assertGreaterThan(23 * 3600, $expiresIn);
        self::assertLessThanOrEqual(24 * 3600, $expiresIn);

        // The cookie carries the preview to the next auth page
        $crawler = $browser->request('GET', '/register');
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('a.btn-facebook-signin[href^="/login/social/facebook"]'));
    }

    public function testCookieShowsFacebookConnectInEditProfile(): void
    {
        $this->enableSocialLoginProvider(OauthProvider::Facebook);
        $this->hideFacebookButtons();
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);
        $browser->getCookieJar()->set(new Cookie(SocialLoginSettings::FACEBOOK_PREVIEW_COOKIE, '1'));

        $crawler = $browser->request('GET', '/en/edit-profile');
        self::assertResponseIsSuccessful();

        self::assertCount(1, $crawler->filter('a[href="/account/social/facebook/connect"]'));
    }

    public function testPreviewShowsNothingWithoutFacebookCredentials(): void
    {
        $this->disableSocialLoginProvider(OauthProvider::Facebook);
        $this->hideFacebookButtons();
        $browser = self::createClient();

        $crawler = $browser->request('GET', '/login?facebook_preview=1');
        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('a.btn-facebook-signin'));

        $crawler = $browser->request('GET', '/register');
        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('a.btn-facebook-signin'));

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);
        $crawler = $browser->request('GET', '/en/edit-profile');
        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('a[href="/account/social/facebook/connect"]'));
    }
}
