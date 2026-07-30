<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Entity\UserAccount;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Repository\UserAccountRepository;
use SpeedPuzzling\Web\Tests\OverridesFeatureFlagEnv;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Mime\Email;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * Native registration (Stage A of issue #147). Both flag states are covered
 * because the flag is flipped in production rather than in a deploy - a
 * rollback must land on a tested path.
 *
 * Emails are randomized per test: the registration rate limiter's cache is not
 * rolled back between tests or runs (DAMA only wraps the database).
 */
final class RegisterControllerTest extends WebTestCase
{
    use OverridesFeatureFlagEnv;

    protected function tearDown(): void
    {
        $this->restoreFeatureFlagEnv();

        parent::tearDown();
    }

    public function testFlagOffSendsVisitorsBackToTheAuth0Signup(): void
    {
        $browser = $this->createClientWithNativeRegistration(false);

        $browser->request('GET', '/register');

        self::assertResponseRedirects('/login');
    }

    public function testRegistrationCreatesAccountAndPlayerAndSignsTheUserIn(): void
    {
        $browser = $this->createClientWithNativeRegistration(true);
        $email = $this->randomEmail('register.happy');

        $this->submitRegistration($browser, $email, 'a-properly-long-passphrase');

        self::assertResponseRedirects('/welcome');

        // The whole point of naming the authenticator in Security::login(): the `main`
        // firewall carries three of them through window A, and an unnamed call throws
        $token = $browser->getContainer()->get(TokenStorageInterface::class)->getToken();
        self::assertNotNull($token);
        self::assertInstanceOf(UserAccount::class, $token->getUser());

        $userId = $token->getUserIdentifier();
        self::assertStringStartsWith('msp|', $userId);

        $userAccount = $browser->getContainer()->get(UserAccountRepository::class)->findByUserId($userId);
        self::assertNotNull($userAccount);
        self::assertSame($email, $userAccount->email);
        self::assertFalse($userAccount->legacyAuth0);
        self::assertNull($userAccount->emailVerifiedAt);

        $player = $browser->getContainer()->get(PlayerRepository::class)->findByUserId($userId);
        self::assertNotNull($player);
        self::assertSame($email, $player->email);

        // The verification mail goes out, and carries the window-A sign-in-link rescue
        $messages = self::getMailerMessages();
        self::assertCount(1, $messages);
        self::assertInstanceOf(Email::class, $messages[0]);

        $body = (string) $messages[0]->getHtmlBody();
        self::assertStringContainsString('/verify-email?token=', $body);
        self::assertStringContainsString('/login-link', $body);
    }

    public function testWelcomeScreenCarriesTheSignInLinkRescue(): void
    {
        // Window-A behavior: the rescue only shows while /login is still the Auth0
        // redirect (it suppresses itself once native_login is ON), so pin the flag
        $this->overrideFeatureFlagEnv('NATIVE_LOGIN_ENABLED', false);
        $browser = $this->createClientWithNativeRegistration(true);
        $email = $this->randomEmail('register.welcome');

        $this->submitRegistration($browser, $email, 'a-properly-long-passphrase');
        $crawler = $browser->followRedirect();

        self::assertResponseIsSuccessful();
        // While /login is still the Auth0 redirect, this link is the only way back in
        // for somebody who gets signed out (implementation-plan §2c)
        self::assertGreaterThan(0, $crawler->filter('a[href^="/login-link"]')->count());
    }

    public function testAddressAlreadyOnAUserAccountIsRefused(): void
    {
        $browser = $this->createClientWithNativeRegistration(true);
        $email = $this->randomEmail('register.taken');

        $entityManager = $browser->getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist(
            new UserAccount(Uuid::uuid7(), 'msp|' . bin2hex(random_bytes(4)), $email, new DateTimeImmutable()),
        );
        $entityManager->flush();

        $crawler = $this->submitRegistration($browser, strtoupper($email), 'a-properly-long-passphrase');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('already has an account', $crawler->filter('form')->text());
    }

    /**
     * The window-A collision (implementation-plan §2c): a legacy Auth0 user has a
     * player row but no user_account yet. Letting them register a second account on
     * the same address would make the Stage B import skip their Auth0 identity and
     * strand their profile and every solving time on it.
     */
    public function testAddressBelongingToALegacyPlayerWithoutAnAccountIsRefused(): void
    {
        $browser = $this->createClientWithNativeRegistration(true);
        $email = $this->randomEmail('register.legacy');

        $entityManager = $browser->getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist(
            new Player(
                Uuid::uuid7(),
                'RGST' . bin2hex(random_bytes(2)),
                'auth0|' . bin2hex(random_bytes(4)),
                // Stored with different casing than the visitor types it
                strtoupper($email),
                null,
                new DateTimeImmutable(),
            ),
        );
        $entityManager->flush();

        $crawler = $this->submitRegistration($browser, $email, 'a-properly-long-passphrase');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('already has an account', $crawler->filter('form')->text());
        self::assertNull($browser->getContainer()->get(TokenStorageInterface::class)->getToken());
    }

    public function testWeakPasswordIsRefusedBeforeAnythingIsCreated(): void
    {
        $browser = $this->createClientWithNativeRegistration(true);
        $email = $this->randomEmail('register.weak');

        $this->submitRegistration($browser, $email, 'short');

        self::assertResponseIsSuccessful();
        self::assertNull($browser->getContainer()->get(UserAccountRepository::class)->findByEmail($email));
    }

    public function testRegistrationPageStartsNoSessionAndStaysOutOfSharedCaches(): void
    {
        $browser = $this->createClientWithNativeRegistration(true);

        $browser->request('GET', '/register');

        // #164: an anonymous GET may not start a session or set a cookie. The form's
        // CSRF token id ('submit') is in stateless_token_ids, so rendering it does not
        // reach for the session
        self::assertSame([], $browser->getResponse()->headers->getCookies());

        $cacheControl = (string) $browser->getResponse()->headers->get('Cache-Control');
        self::assertStringContainsString('no-store', $cacheControl);
        self::assertStringNotContainsString('public', $cacheControl);
    }

    public function testPageIsRenderedInTheBrowserLanguage(): void
    {
        $browser = $this->createClientWithNativeRegistration(true);

        $crawler = $browser->request('GET', '/register', server: ['HTTP_ACCEPT_LANGUAGE' => 'de-DE,de;q=0.9']);

        self::assertResponseIsSuccessful();
        self::assertSame('de', $crawler->filter('html')->attr('lang'));
    }

    private function submitRegistration(KernelBrowser $browser, string $email, string $password): Crawler
    {
        // A fresh client IP per submit: registration is throttled per IP and the
        // limiter's cache is not rolled back between tests or runs (DAMA only wraps
        // the database), so a shared 127.0.0.1 would starve later tests
        $browser->setServerParameter('REMOTE_ADDR', sprintf('198.51.100.%d', random_int(1, 254)));

        $crawler = $browser->request('GET', '/register');
        $form = $crawler->selectButton('Create account')->form();

        return $browser->submit($form, [
            $form->getName() . '[email]' => $email,
            $form->getName() . '[plainPassword]' => $password,
        ]);
    }

    private function createClientWithNativeRegistration(bool $enabled): KernelBrowser
    {
        // The flag is a runtime env placeholder, so a kernel booted after this sees it
        $this->overrideFeatureFlagEnv('NATIVE_REGISTRATION_ENABLED', $enabled);

        return self::createClient();
    }

    private function randomEmail(string $prefix): string
    {
        return sprintf('%s+%s@example.com', $prefix, bin2hex(random_bytes(4)));
    }
}
