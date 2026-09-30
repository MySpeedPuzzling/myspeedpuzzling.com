<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Security;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\OauthIdentity;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Repository\UserAccountRepository;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\ConfiguresSocialLoginProviders;
use SpeedPuzzling\Web\Tests\TestingLogin;
use SpeedPuzzling\Web\Value\OauthProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Every social button follows its provider's brand rules (a condition of
 * using Google's, Microsoft's, Apple's and Meta's sign-in) and is drawn by
 * ONE partial, so /login and the settings connect buttons look the same:
 * Google's light button with the four-colour G, Microsoft's light button with
 * the four squares, Apple's black button with Apple's own logo artwork, Meta's
 * round logo on Facebook Blue. Never a monochrome icon font
 * glyph in the site's colours.
 */
final class SocialProviderButtonsTest extends WebTestCase
{
    use ConfiguresSocialLoginProviders;

    protected function tearDown(): void
    {
        $this->restoreSocialLoginEnv();

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
        self::assertStringContainsString('Outlook.com, Hotmail, Live or Xbox account', $crawler->text());
    }

    /**
     * Owner decision 2026-09-30: Google, Microsoft, Apple, Facebook - on
     * /login (under the email form), on /register (after "Continue with
     * email") and in settings.
     */
    public function testProvidersAreInTheSameOrderEverywhere(): void
    {
        $this->enableAllProvidersPublicly();
        $browser = self::createClient();

        foreach (['/login', '/register'] as $path) {
            $browser->request('GET', $path);
            self::assertResponseIsSuccessful();
            $this->assertProviderOrder((string) $browser->getResponse()->getContent(), $path);
        }

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);
        $browser->request('GET', '/en/edit-profile');
        self::assertResponseIsSuccessful();
        $this->assertProviderOrder((string) $browser->getResponse()->getContent(), 'settings');
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

    public function testSettingsListsEveryProviderConnectedOnesFirstWithTheirLogo(): void
    {
        $this->enableAllProvidersPublicly();
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $container = $browser->getContainer();
        $player = $container->get(PlayerRepository::class)->get(PlayerFixture::PLAYER_REGULAR);
        assert($player->userId !== null);
        $userAccount = $container->get(UserAccountRepository::class)->findByUserId($player->userId);
        assert($userAccount !== null);
        $entityManager = $container->get(EntityManagerInterface::class);
        $entityManager->persist(new OauthIdentity(
            id: Uuid::uuid7(),
            userAccount: $userAccount,
            provider: OauthProvider::Apple,
            providerUserId: 'apple-list-test',
            emailAtLink: 'x7k2m9q4pz@privaterelay.appleid.com',
            linkedAt: new DateTimeImmutable('2026-09-20 10:00:00'),
        ));
        $entityManager->flush();

        $crawler = $browser->request('GET', '/en/edit-profile');
        self::assertResponseIsSuccessful();

        $rows = $crawler->filter('.social-identity-list > li.social-identity');
        self::assertCount(4, $rows);

        // Connected first: Apple's own logo artwork in the badge, email, Disconnect with CSRF
        $apple = $rows->eq(0);
        self::assertSame('0 0 31 44', $apple->filter('.social-identity-badge-apple svg.social-identity-logo')->attr('viewBox'));
        self::assertStringContainsString('x7k2m9q4pz@privaterelay.appleid.com', $apple->filter('.social-identity-email')->text());
        self::assertCount(1, $apple->filter('form[action="/account/social/apple/disconnect"] input[name="_token"]'));
        self::assertSame('Disconnect', trim($apple->filter('button[type="submit"]')->text()));
        self::assertCount(0, $apple->filter('a.btn-apple-signin'));

        // Then the not connected ones, each with its branded connect button
        self::assertCount(1, $rows->eq(1)->filter('.social-identity-badge-google svg.social-identity-logo path[fill="#4285F4"]'));
        self::assertCount(1, $rows->eq(1)->filter('a.btn-google-signin[href^="/account/social/google"]'));
        self::assertCount(1, $rows->eq(2)->filter('.social-identity-badge-microsoft svg.social-identity-logo rect[fill="#F25022"]'));
        self::assertCount(1, $rows->eq(2)->filter('a.btn-microsoft-signin[href^="/account/social/microsoft"]'));
        self::assertStringContainsString('Outlook.com, Hotmail, Live or Xbox account', $rows->eq(2)->text());
        self::assertCount(1, $rows->eq(3)->filter('.social-identity-badge-facebook svg.social-identity-logo'));
        self::assertCount(1, $rows->eq(3)->filter('a.btn-facebook-signin[href^="/account/social/facebook"]'));
        self::assertStringContainsString('also for Instagram users', $rows->eq(3)->text());
    }

    /**
     * Every provider is offered to every anonymous visitor as soon as its
     * credentials are configured - Google and Apple since 2026-09-29,
     * Facebook since the Meta app was published (2026-09-30).
     */
    public function testAnonymousVisitorsSeeConfiguredProvidersOnLoginAndRegister(): void
    {
        $this->enableSocialLoginProvider(OauthProvider::Google, OauthProvider::Apple, OauthProvider::Facebook);
        $this->disableSocialLoginProvider(OauthProvider::Microsoft);
        $browser = self::createClient();

        foreach (['/login', '/register'] as $path) {
            $crawler = $browser->request('GET', $path);
            self::assertResponseIsSuccessful();

            self::assertCount(1, $crawler->filter('a.btn-google-signin[href^="/login/social/google"]'), $path);
            self::assertCount(1, $crawler->filter('a.btn-apple-signin[href^="/login/social/apple"]'), $path);
            self::assertCount(1, $crawler->filter('a.btn-facebook-signin[href^="/login/social/facebook"]'), $path);
            self::assertCount(0, $crawler->filter('a.btn-microsoft-signin'), $path . ': Microsoft is not configured');
            self::assertStringContainsString('also for Instagram users', $crawler->text());

            // The buttons depend on configuration only, and the auth pages are
            // never shared-cached anyway
            self::assertStringContainsString('no-store', (string) $browser->getResponse()->headers->get('Cache-Control'));
        }
    }

    /**
     * Facebook follows the credentials rule like everybody else: without
     * FACEBOOK_APP_ID + FACEBOOK_APP_SECRET no Facebook button anywhere.
     */
    public function testFacebookIsHiddenEverywhereWithoutItsCredentials(): void
    {
        $this->enableSocialLoginProvider(OauthProvider::Google);
        $this->disableSocialLoginProvider(OauthProvider::Facebook);
        $browser = self::createClient();

        foreach (['/login', '/register'] as $path) {
            $crawler = $browser->request('GET', $path);
            self::assertResponseIsSuccessful();
            self::assertCount(1, $crawler->filter('a.btn-google-signin'), $path);
            self::assertCount(0, $crawler->filter('a.btn-facebook-signin'), $path);
            self::assertStringNotContainsString('also for Instagram users', $crawler->text());
        }

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);
        $crawler = $browser->request('GET', '/en/edit-profile');
        self::assertResponseIsSuccessful();

        self::assertCount(1, $crawler->filter('a[href="/account/social/google/connect"]'));
        self::assertCount(0, $crawler->filter('a[href="/account/social/facebook/connect"]'));
        self::assertCount(0, $crawler->filter('.social-identity-badge-facebook'));
    }

    public function testFacebookConnectIsOfferedInSettingsWhenConfigured(): void
    {
        $this->enableSocialLoginProvider(OauthProvider::Facebook);
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $crawler = $browser->request('GET', '/en/edit-profile');
        self::assertResponseIsSuccessful();

        self::assertCount(1, $crawler->filter('a.btn-facebook-signin[href="/account/social/facebook/connect"]'));
    }

    /**
     * A player who already linked Facebook keeps seeing (and can disconnect)
     * that row even when Facebook's credentials are gone and no provider is
     * configured at all.
     */
    public function testLinkedFacebookStaysVisibleWhenFacebookIsNotConfigured(): void
    {
        $this->disableSocialLoginProvider(OauthProvider::Google, OauthProvider::Microsoft, OauthProvider::Apple, OauthProvider::Facebook);
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $container = $browser->getContainer();
        $player = $container->get(PlayerRepository::class)->get(PlayerFixture::PLAYER_REGULAR);
        assert($player->userId !== null);
        $userAccount = $container->get(UserAccountRepository::class)->findByUserId($player->userId);
        assert($userAccount !== null);
        $entityManager = $container->get(EntityManagerInterface::class);
        $entityManager->persist(new OauthIdentity(
            id: Uuid::uuid7(),
            userAccount: $userAccount,
            provider: OauthProvider::Facebook,
            providerUserId: 'facebook-hidden-list-test',
            emailAtLink: 'hidden-list@facebook.example.com',
            linkedAt: new DateTimeImmutable('2026-09-20 10:00:00'),
        ));
        $entityManager->flush();

        $crawler = $browser->request('GET', '/en/edit-profile');
        self::assertResponseIsSuccessful();

        $rows = $crawler->filter('.social-identity-list > li.social-identity');
        self::assertCount(1, $rows);
        self::assertCount(1, $rows->eq(0)->filter('.social-identity-badge-facebook'));
        self::assertCount(1, $rows->eq(0)->filter('form[action="/account/social/facebook/disconnect"]'));
        self::assertCount(0, $crawler->filter('a[href="/account/social/facebook/connect"]'));
    }

    public function testNoButtonsWhenNoProviderIsConfigured(): void
    {
        $this->disableSocialLoginProvider(OauthProvider::Google, OauthProvider::Microsoft, OauthProvider::Apple, OauthProvider::Facebook);
        $browser = self::createClient();

        $crawler = $browser->request('GET', '/login');
        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('a.btn-google-signin, a.btn-microsoft-signin, a.btn-apple-signin, a.btn-facebook-signin'));
        self::assertCount(0, $crawler->filter('a[href^="/login/social/"]'));

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);
        $crawler = $browser->request('GET', '/en/edit-profile');
        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('a[href^="/account/social/"]'));
    }

    public function testEveryLoggedInPlayerGetsTheConnectedSignInMethodsCard(): void
    {
        $this->enableSocialLoginProvider(OauthProvider::Google, OauthProvider::Apple);
        $browser = self::createClient();
        // A regular (non-admin) player - the admin-only stage is gone
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $crawler = $browser->request('GET', '/en/edit-profile');
        self::assertResponseIsSuccessful();

        self::assertCount(1, $crawler->filter('a[href="/account/social/google/connect"]'));
        self::assertCount(1, $crawler->filter('a[href="/account/social/apple/connect"]'));
        self::assertCount(0, $crawler->filter('a[href="/account/social/facebook/connect"]'));
    }

    private function assertBrandedButtons(Crawler $crawler, string $hrefPrefix): void
    {
        $google = $crawler->filter(sprintf('a.btn-google-signin[href^="%sgoogle"]', $hrefPrefix));
        self::assertCount(1, $google);
        self::assertSame('Continue with Google', self::buttonLabel($google));
        $fills = $google->filter('svg.btn-google-signin-logo path')->each(
            static fn (Crawler $path): string => (string) $path->attr('fill'),
        );
        self::assertSame(['#EA4335', '#4285F4', '#FBBC05', '#34A853'], $fills);

        $apple = $crawler->filter(sprintf('a.btn-apple-signin[href^="%sapple"]', $hrefPrefix));
        self::assertCount(1, $apple);
        // Apple allows only "Sign in / Sign up / Continue with Apple"
        self::assertSame('Continue with Apple', self::buttonLabel($apple));
        // Apple's "Left-aligned - Medium" artwork, uncropped
        self::assertSame('0 0 31 44', $apple->filter('svg.btn-apple-signin-logo')->attr('viewBox'));

        $microsoft = $crawler->filter(sprintf('a.btn-microsoft-signin[href^="%smicrosoft"]', $hrefPrefix));
        self::assertCount(1, $microsoft);
        self::assertSame('Continue with Microsoft', self::buttonLabel($microsoft));
        // Microsoft's four squares, unmodified
        $squares = $microsoft->filter('svg.btn-microsoft-signin-logo rect')->each(
            static fn (Crawler $rect): string => (string) $rect->attr('fill'),
        );
        self::assertSame(['#F25022', '#7FBA00', '#00A4EF', '#FFB900'], $squares);

        $facebook = $crawler->filter(sprintf('a.btn-facebook-signin[href^="%sfacebook"]', $hrefPrefix));
        self::assertCount(1, $facebook);
        self::assertSame('Continue with Facebook', self::buttonLabel($facebook));
        self::assertCount(1, $facebook->filter('svg.btn-facebook-signin-logo'));

        foreach ([$google, $microsoft, $apple, $facebook] as $button) {
            self::assertStringNotContainsString('btn-outline', (string) $button->attr('class'));
        }
    }

    /**
     * The visible label - without the "Last used" tag the sign-in page tucks
     * (hidden until the browser remembers a method) into each button
     */
    private static function buttonLabel(Crawler $button): string
    {
        $badge = $button->filter('.auth-last-used');

        return trim(str_replace($badge->count() > 0 ? $badge->text() : '', '', $button->text()));
    }

    private function assertProviderOrder(string $html, string $where): void
    {
        $previous = -1;

        foreach (['google', 'microsoft', 'apple', 'facebook'] as $provider) {
            $position = strpos($html, 'btn-' . $provider . '-signin');
            self::assertNotFalse($position, "{$where}: {$provider} button missing");
            self::assertGreaterThan($previous, $position, "{$where}: {$provider} is out of order");
            $previous = $position;
        }
    }

    private function enableAllProvidersPublicly(): void
    {
        $this->enableSocialLoginProvider(OauthProvider::Google, OauthProvider::Microsoft, OauthProvider::Apple, OauthProvider::Facebook);
    }
}
