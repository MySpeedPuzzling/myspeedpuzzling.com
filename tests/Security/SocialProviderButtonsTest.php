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
use SpeedPuzzling\Web\Tests\OverridesFeatureFlagEnv;
use SpeedPuzzling\Web\Tests\TestingLogin;
use SpeedPuzzling\Web\Value\OauthProvider;
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
        self::assertCount(3, $rows);

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
        self::assertCount(1, $rows->eq(2)->filter('.social-identity-badge-facebook svg.social-identity-logo'));
        self::assertCount(1, $rows->eq(2)->filter('a.btn-facebook-signin[href^="/account/social/facebook"]'));
        self::assertStringContainsString('also for Instagram users', $rows->eq(2)->text());
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
