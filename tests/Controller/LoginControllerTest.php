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
 * The /login page (issue #147): our own form on one locale-free URL.
 */
final class LoginControllerTest extends WebTestCase
{
    public function testRendersTheFormWithTheSignInLinkRescue(): void
    {
        $browser = self::createClient();

        $crawler = $browser->request('GET', '/login');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('form#login-form input[name="email"]'));
        self::assertCount(1, $crawler->filter('form#login-form input[name="password"]'));

        // The magic-link rescue is a permanent, prominent secondary action (UX funnel §3).
        // It submits its own form, pre-filled with the address, so the typed password
        // never travels to a second endpoint
        self::assertCount(1, $crawler->filter('button[form="sign-in-link-form"]'));
        self::assertSame('/login-link', $crawler->filter('form#sign-in-link-form')->attr('action'));
        self::assertCount(0, $crawler->filter('form#sign-in-link-form input[name="password"]'));

        // The sign-in migration notice lives here now, as a footnote under the card
        // (it used to be a strip above the navbar on every page)
        self::assertStringContainsString('Sign-in has moved to myspeedpuzzling.com', $crawler->filter('main')->text());
        self::assertCount(1, $crawler->filter('main a[href="/en/sign-in-is-moving"]'));
    }

    public function testNativeLoginPageStartsNoSessionAndStaysOutOfSharedCaches(): void
    {
        $browser = self::createClient();

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
        $browser = self::createClient();

        // No locale in the path (bookmarks and the base.html.twig button point at
        // /login), so the language is negotiated - D17 requires all six locales
        $crawler = $browser->request('GET', '/login', server: ['HTTP_ACCEPT_LANGUAGE' => 'cs-CZ,cs;q=0.9']);

        self::assertResponseIsSuccessful();
        self::assertSame('cs', $crawler->filter('html')->attr('lang'));
    }

    public function testFailedAttemptComesBackWithTheHelperAndThePrefilledAddress(): void
    {
        $browser = self::createClient();
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
        self::assertCount(1, $crawler->filter('.alert-info button[form="sign-in-link-form"]'));

        // One click away from a link, with nothing to retype
        self::assertSame($email, $crawler->filter('form#sign-in-link-form input[name="email"]')->attr('value'));
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
