<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\UserAccount;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The /login route across the Auth0 migration (issue #147). One URL, two
 * behaviours, chosen by the native_login flag: the Auth0 redirect until Stage B,
 * our own form afterwards. Both are covered here because the flag is flipped in
 * production, not in a deploy - a rollback must land on a tested path.
 */
final class LoginControllerTest extends WebTestCase
{
    protected function tearDown(): void
    {
        unset($_ENV['NATIVE_LOGIN_ENABLED'], $_SERVER['NATIVE_LOGIN_ENABLED']);

        parent::tearDown();
    }

    public function testFlagOffKeepsSendingVisitorsToAuth0(): void
    {
        $browser = $this->createClientWithNativeLogin(false);

        $browser->request('GET', '/login');

        $location = (string) $browser->getResponse()->headers->get('Location');
        self::assertResponseStatusCodeSame(302);
        self::assertStringContainsString('auth0.com', $location);
    }

    public function testFlagOnRendersTheNativeFormWithTheSignInLinkRescue(): void
    {
        $browser = $this->createClientWithNativeLogin(true);

        $crawler = $browser->request('GET', '/login');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('form#login-form input[name="email"]'));
        self::assertCount(1, $crawler->filter('form#login-form input[name="password"]'));

        // The magic-link rescue is a permanent, prominent secondary action (UX funnel §3)
        // and re-posts the login form, so it always carries the typed address
        self::assertCount(1, $crawler->filter('form#login-form button[formaction="/login-link"]'));

        // Persistent migration microcopy
        self::assertStringContainsString('same email and password', $crawler->filter('main')->text());
    }

    public function testNativeLoginPageStartsNoSessionAndStaysOutOfSharedCaches(): void
    {
        $browser = $this->createClientWithNativeLogin(true);

        $browser->request('GET', '/login');

        // #164: an anonymous GET may not start a session or set a cookie
        self::assertSame([], $browser->getResponse()->headers->getCookies());

        // ... and this page answers in six languages on one URL, so it must not be
        // shared-cacheable either
        $cacheControl = (string) $browser->getResponse()->headers->get('Cache-Control');
        self::assertStringContainsString('no-store', $cacheControl);
        self::assertStringNotContainsString('public', $cacheControl);
    }

    public function testPageIsRenderedInTheBrowserLanguage(): void
    {
        $browser = $this->createClientWithNativeLogin(true);

        // No locale in the path (bookmarks and the base.html.twig button point at
        // /login), so the language is negotiated - D17 requires all six locales
        $crawler = $browser->request('GET', '/login', server: ['HTTP_ACCEPT_LANGUAGE' => 'cs-CZ,cs;q=0.9']);

        self::assertResponseIsSuccessful();
        self::assertSame('cs', $crawler->filter('html')->attr('lang'));
    }

    public function testFailedAttemptComesBackWithTheHelperAndThePrefilledAddress(): void
    {
        $browser = $this->createClientWithNativeLogin(true);
        $email = $this->seedAccount($browser);

        $browser->request('POST', '/login', [
            'email' => $email,
            'password' => 'not-the-password',
            '_csrf_token' => 'csrf-token',
        ], [], ['HTTP_ORIGIN' => 'http://localhost']);

        self::assertResponseRedirects('/login');

        $crawler = $browser->followRedirect();

        self::assertResponseIsSuccessful();
        // UX funnel §4: the helper appears on failure, with the address still in place
        self::assertSame($email, $crawler->filter('form#login-form input[name="email"]')->attr('value'));
        self::assertStringContainsString('speedpuzzling', $crawler->filter('.alert-info')->text());
        self::assertCount(1, $crawler->filter('.alert-info button[formaction="/login-link"]'));
    }

    private function createClientWithNativeLogin(bool $enabled): KernelBrowser
    {
        // The flag is a runtime env placeholder, so a kernel booted after this sees it
        $_ENV['NATIVE_LOGIN_ENABLED'] = $enabled ? '1' : '0';
        $_SERVER['NATIVE_LOGIN_ENABLED'] = $_ENV['NATIVE_LOGIN_ENABLED'];

        return self::createClient();
    }

    private function seedAccount(KernelBrowser $browser): string
    {
        // Randomized: the login rate limiter's cache survives across tests and runs
        $email = sprintf('login.page+%s@example.com', bin2hex(random_bytes(4)));
        $userAccount = new UserAccount(Uuid::uuid7(), 'msp|' . bin2hex(random_bytes(4)), $email, new DateTimeImmutable());
        $userAccount->changePassword(password_hash('the-real-password', PASSWORD_ARGON2ID));

        $entityManager = $browser->getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist($userAccount);
        $entityManager->flush();

        return $email;
    }
}
