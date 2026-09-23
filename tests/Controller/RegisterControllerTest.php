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
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Mime\Email;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * Registration (issue #147).
 *
 * Emails are randomized per test: the registration rate limiter's cache is not
 * rolled back between tests or runs (DAMA only wraps the database).
 */
final class RegisterControllerTest extends WebTestCase
{
    public function testRegistrationCreatesAccountAndPlayerAndSignsTheUserIn(): void
    {
        $browser = self::createClient();
        $email = $this->randomEmail('register.happy');

        $this->submitRegistration($browser, $email, 'a-properly-long-passphrase');

        self::assertResponseRedirects('/welcome');

        // The whole point of naming the authenticator in Security::login(): the `main`
        // firewall carries several of them, and an unnamed call throws
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

        // The verification mail goes out - and carries nothing but the verification link
        $messages = self::getMailerMessages();
        self::assertCount(1, $messages);
        self::assertInstanceOf(Email::class, $messages[0]);

        $body = (string) $messages[0]->getHtmlBody();
        self::assertStringContainsString('/verify-email?token=', $body);
        self::assertStringNotContainsString('/login-link', $body);
    }

    public function testWelcomeScreenNamesTheAddressTheVerificationWentTo(): void
    {
        $browser = self::createClient();
        $email = $this->randomEmail('register.welcome');

        $this->submitRegistration($browser, $email, 'a-properly-long-passphrase');
        $crawler = $browser->followRedirect();

        self::assertResponseIsSuccessful();
        self::assertStringContainsString($email, $crawler->filter('main')->text());
    }

    public function testNameGivenAtRegistrationIsOnThePlayerAndGreetsThemOnTheWelcomeScreen(): void
    {
        $browser = self::createClient();

        $this->submitRegistration($browser, $this->randomEmail('register.named'), 'a-properly-long-passphrase', 'Jane Puzzler');
        $crawler = $browser->followRedirect();

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Welcome, Jane Puzzler!', $crawler->filter('h1')->text());

        $token = $browser->getContainer()->get(TokenStorageInterface::class)->getToken();
        self::assertNotNull($token);

        $player = $browser->getContainer()->get(PlayerRepository::class)->findByUserId($token->getUserIdentifier());
        self::assertNotNull($player);
        self::assertSame('Jane Puzzler', $player->name);
    }

    public function testWelcomeScreenOffersTheFirstThingsToDo(): void
    {
        $browser = self::createClient();

        $this->submitRegistration($browser, $this->randomEmail('register.choices'), 'a-properly-long-passphrase');
        $crawler = $browser->followRedirect();

        $links = $crawler->filter('main a')->each(static fn (Crawler $link): string => (string) $link->attr('href'));

        self::assertContains('/en/puzzle-add', $links);
        self::assertContains('/en/stopwatch', $links);
        self::assertContains('/en/finish-profile', $links);
        self::assertContains('/en/getting-started', $links);
        self::assertContains('/en/hub', $links);
    }

    public function testAddressAlreadyOnAUserAccountIsRefused(): void
    {
        $browser = self::createClient();
        $email = $this->randomEmail('register.taken');

        $entityManager = $browser->getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist(
            new UserAccount(Uuid::uuid7(), 'msp|' . bin2hex(random_bytes(4)), $email, new DateTimeImmutable()),
        );
        $entityManager->flush();

        $crawler = $this->submitRegistration($browser, strtoupper($email), 'a-properly-long-passphrase');

        // 422, not 200: Turbo Drive discards a 200 answer to a form submission and the error with it
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('already has an account', $crawler->filter('form')->text());
    }

    /**
     * user_account.email is the single source of truth: a player row without an account
     * (an Auth0-era leftover) has no e-mail at all, so it reserves no address - only
     * another ACCOUNT can hold one.
     */
    public function testALegacyPlayerWithoutAnAccountReservesNoAddress(): void
    {
        $browser = self::createClient();
        $email = $this->randomEmail('register.legacy');

        $entityManager = $browser->getContainer()->get(EntityManagerInterface::class);
        $legacyPlayer = new Player(
            Uuid::uuid7(),
            'RGST' . bin2hex(random_bytes(2)),
            'auth0|' . bin2hex(random_bytes(4)),
            null,
            new DateTimeImmutable(),
        );
        $entityManager->persist($legacyPlayer);
        $entityManager->flush();

        $this->submitRegistration($browser, $email, 'a-properly-long-passphrase');

        self::assertResponseRedirects();

        $userAccount = $browser->getContainer()->get(UserAccountRepository::class)->findByEmail($email);
        self::assertNotNull($userAccount);

        $player = $browser->getContainer()->get(PlayerRepository::class)->findByUserId($userAccount->userId);
        self::assertNotNull($player);
        self::assertNotSame($legacyPlayer->id->toString(), $player->id->toString());
    }

    public function testWeakPasswordIsRefusedBeforeAnythingIsCreated(): void
    {
        $browser = self::createClient();
        $email = $this->randomEmail('register.weak');

        $this->submitRegistration($browser, $email, 'short');

        // 422, not 200: Turbo Drive discards a 200 answer to a form submission and the error with it
        self::assertResponseStatusCodeSame(422);
        self::assertNull($browser->getContainer()->get(UserAccountRepository::class)->findByEmail($email));
    }

    public function testRegistrationPageStartsNoSessionAndStaysOutOfSharedCaches(): void
    {
        $browser = self::createClient();

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
        $browser = self::createClient();

        $crawler = $browser->request('GET', '/register', server: ['HTTP_ACCEPT_LANGUAGE' => 'de-DE,de;q=0.9']);

        self::assertResponseIsSuccessful();
        self::assertSame('de', $crawler->filter('html')->attr('lang'));
    }

    private function submitRegistration(KernelBrowser $browser, string $email, string $password, string $name = ''): Crawler
    {
        // A fresh client IP per submit: registration is throttled per IP and the
        // limiter's cache is not rolled back between tests or runs (DAMA only wraps
        // the database), so a shared 127.0.0.1 would starve later tests
        $browser->setServerParameter('REMOTE_ADDR', sprintf('198.51.100.%d', random_int(1, 254)));

        $crawler = $browser->request('GET', '/register');
        $form = $crawler->selectButton('Create account')->form();

        return $browser->submit($form, [
            $form->getName() . '[name]' => $name,
            $form->getName() . '[email]' => $email,
            $form->getName() . '[plainPassword]' => $password,
        ]);
    }

    private function randomEmail(string $prefix): string
    {
        return sprintf('%s+%s@example.com', $prefix, bin2hex(random_bytes(4)));
    }
}
