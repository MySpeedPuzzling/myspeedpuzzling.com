<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Security;

use SpeedPuzzling\Web\Tests\ConfiguresSocialLoginProviders;
use SpeedPuzzling\Web\Value\OauthProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Inside Instagram's / Facebook's own browser Google refuses to sign anybody
 * in (403 disallowed_useragent). The sign-in pages say so up front and point
 * the Google button at that explanation instead of into the dead end
 * (docs/features/auth-ux-redesign.md §4.8).
 */
final class InAppBrowserNoticeTest extends WebTestCase
{
    use ConfiguresSocialLoginProviders;

    private const string INSTAGRAM_IOS = 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Mobile/22F76 Instagram 385.0.0.28.93 (iPhone15,3; iOS 18_5; en_US; en; scale=3.00; 1290x2796; 745621391; IABMV/1)';
    private const string FACEBOOK_ANDROID = 'Mozilla/5.0 (Linux; Android 13; SM-S911B Build/TP1A.220624.014; wv) AppleWebKit/537.36 (KHTML, like Gecko) Version/4.0 Chrome/127.0.6533.103 Mobile Safari/537.36 [FB_IAB/FB4A;FBAV/478.0.0.41.86;]';
    private const string SAFARI_IOS = 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.5 Mobile/15E148 Safari/604.1';

    protected function tearDown(): void
    {
        $this->restoreSocialLoginEnv();

        parent::tearDown();
    }

    public function testInstagramOnIphoneGetsTheNoticeAndAGoogleButtonThatExplains(): void
    {
        $this->enableSocialLoginProvider(OauthProvider::Google, OauthProvider::Apple);
        $browser = self::createClient();

        foreach (['/login', '/register'] as $path) {
            $crawler = $browser->request('GET', $path, server: ['HTTP_USER_AGENT' => self::INSTAGRAM_IOS]);
            self::assertResponseIsSuccessful();

            $notice = $crawler->filter('#in-app-browser-notice');
            self::assertCount(1, $notice, $path);
            self::assertStringContainsString("You're in Instagram's browser", $notice->text());
            self::assertStringContainsString("Google sign-in doesn't work here.", $notice->text());
            self::assertStringContainsString('Open in external browser', $notice->text());
            // No Chrome intent on an iPhone - it would do nothing
            self::assertCount(0, $notice->filter('a[href^="intent://"]'));
            self::assertCount(1, $notice->filter('button[data-action="copy-link#copy"]'));

            // Google is re-targeted at the notice, never removed; Apple works in web views
            $google = $crawler->filter('a.btn-google-signin');
            self::assertCount(1, $google, $path);
            self::assertSame('#in-app-browser-notice', $google->attr('href'));
            self::assertSame('in-app-browser-notice', $google->attr('aria-describedby'));
            self::assertStringStartsWith('/login/social/apple', (string) $crawler->filter('a.btn-apple-signin')->attr('href'));

            // The page depends on the User-Agent - it must never be shared-cached
            self::assertStringContainsString('no-store', (string) $browser->getResponse()->headers->get('Cache-Control'));
        }
    }

    public function testFacebookOnAndroidIsOfferedChrome(): void
    {
        $this->enableSocialLoginProvider(OauthProvider::Google);
        $browser = self::createClient();

        $crawler = $browser->request('GET', '/login?return=/en/puzzle', server: ['HTTP_USER_AGENT' => self::FACEBOOK_ANDROID]);

        $notice = $crawler->filter('#in-app-browser-notice');
        self::assertStringContainsString("You're in Facebook's browser", $notice->text());
        self::assertSame(
            'intent://localhost/login?return=/en/puzzle#Intent;scheme=http;package=com.android.chrome;end',
            $notice->filter('a[href^="intent://"]')->attr('href'),
        );
        self::assertStringNotContainsString('Open in external browser', $notice->text());
    }

    public function testRealBrowsersSeeNoNoticeAndTheRealGoogleButton(): void
    {
        $this->enableSocialLoginProvider(OauthProvider::Google);
        $browser = self::createClient();

        $crawler = $browser->request('GET', '/login', server: ['HTTP_USER_AGENT' => self::SAFARI_IOS]);

        self::assertCount(0, $crawler->filter('#in-app-browser-notice'));
        self::assertStringStartsWith('/login/social/google', (string) $crawler->filter('a.btn-google-signin')->attr('href'));
    }

    public function testWithoutGoogleTheNoticeStillSaysWhereSigningInWorksBest(): void
    {
        $this->disableSocialLoginProvider(OauthProvider::Google, OauthProvider::Apple, OauthProvider::Facebook);
        $browser = self::createClient();

        $crawler = $browser->request('GET', '/login', server: ['HTTP_USER_AGENT' => self::INSTAGRAM_IOS]);

        $notice = $crawler->filter('#in-app-browser-notice');
        self::assertCount(1, $notice);
        self::assertStringNotContainsString('Google', $notice->text());
        self::assertStringContainsString("Signing in works best in your phone's own browser.", $notice->text());
    }
}
