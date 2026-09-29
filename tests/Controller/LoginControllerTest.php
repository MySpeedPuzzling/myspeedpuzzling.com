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

        $crawler = $browser->request('GET', '/login?return=/en/puzzle');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('form#login-form input[name="email"][autocomplete="username"]'));
        self::assertCount(1, $crawler->filter('form#login-form input[name="password"][autocomplete="current-password"]'));

        // "Create an account" is the first thing under the heading, and keeps
        // where the visitor was headed
        self::assertSame('/register?return=/en/puzzle', $crawler->filter('.auth-crosslink a')->attr('href'));

        // "Forgot password?" sits on the password label row, before the field
        self::assertSame('/password-reset', $crawler->filter('.auth-label-row a.auth-inline-link')->attr('href'));

        // The sign-in link rescue (UX funnel §3) is an honest link to its own page
        // now - the typed address follows through sessionStorage, never the URL -
        // and no hidden second form carries the address any more
        self::assertCount(1, $crawler->filter('a[href="/login-link?return=/en/puzzle"]'));
        self::assertCount(0, $crawler->filter('#sign-in-link-form, [form="sign-in-link-form"]'));

        // Nothing on the page points at the retired Auth0 stack any more
        self::assertStringNotContainsStringIgnoringCase('auth0', (string) $browser->getResponse()->getContent());
    }

    /**
     * docs/features/auth-ux-redesign.md §4.1/§5.2: 16px-friendly mobile fields
     * that password managers and phone keyboards understand, no autofocus on
     * arrival, and a show/hide toggle that is a real, labelled button.
     */
    public function testFieldsCarryTheMobileAndPasswordManagerAttributes(): void
    {
        $browser = self::createClient();

        $crawler = $browser->request('GET', '/login');

        $email = $crawler->filter('#login-email');
        self::assertSame('email', $email->attr('type'));
        self::assertSame('none', $email->attr('autocapitalize'));
        self::assertSame('off', $email->attr('autocorrect'));
        self::assertSame('false', $email->attr('spellcheck'));
        self::assertSame('next', $email->attr('enterkeyhint'));
        self::assertNull($email->attr('autofocus'));

        $password = $crawler->filter('#login-password');
        self::assertSame('go', $password->attr('enterkeyhint'));
        self::assertNull($password->attr('autofocus'));
        self::assertNull($password->attr('maxlength'));

        $toggle = $crawler->filter('.password-field button.password-toggle');
        self::assertCount(1, $toggle);
        self::assertSame('button', $toggle->attr('type'));
        self::assertSame('login-password', $toggle->attr('aria-controls'));
        self::assertSame('false', $toggle->attr('aria-pressed'));
        self::assertSame('password-toggle', $toggle->attr('data-controller'));
        self::assertSame('Show password', trim($toggle->filter('.visually-hidden')->text()));

        // Remembers the method on this device only (localStorage), never the address
        self::assertSame('password', $crawler->filter('form#login-form')->attr('data-last-sign-in-method'));
        self::assertNotNull($crawler->filter('[data-controller~="last-sign-in"]')->getNode(0));
    }

    /**
     * The retired "sign-in is moving" explainer: emails and support replies still
     * link to it, so every locale path lands on the sign-in page.
     */
    public function testRetiredExplainerPagesRedirectToTheSignInPage(): void
    {
        $browser = self::createClient();

        foreach (['/en/sign-in-is-moving', '/prihlasovani-se-stehuje', '/de/anmeldung-zieht-um'] as $path) {
            $browser->request('GET', $path);

            self::assertResponseStatusCodeSame(301);
            self::assertResponseRedirects('/login');
        }
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
            'return' => '/en/puzzle',
        ], [], ['HTTP_ORIGIN' => 'http://localhost']);

        self::assertResponseRedirects('/login?return=/en/puzzle');

        $crawler = $browser->followRedirect();

        self::assertResponseIsSuccessful();
        // UX funnel §4: the helper appears on failure, with the address still in
        // place and the focus on the password - the part that needs retyping
        self::assertSame($email, $crawler->filter('form#login-form input[name="email"]')->attr('value'));
        self::assertNotNull($crawler->filter('#login-password')->attr('autofocus'));

        $alert = $crawler->filter('.alert[role="alert"]');
        self::assertCount(1, $alert);
        self::assertStringContainsString("That email and password don't match.", $alert->text());
        self::assertStringContainsString('speedpuzzling', $alert->text());
        self::assertStringNotContainsStringIgnoringCase('auth0', $crawler->filter('main')->text());

        // One tap away from a link, the destination kept
        self::assertCount(1, $alert->filter('a[href="/login-link?return=/en/puzzle"]'));
    }

    /**
     * Wrong password and unknown address must look the same (D8)
     */
    public function testUnknownAddressFailsExactlyLikeAWrongPassword(): void
    {
        $browser = self::createClient();
        $known = $this->seedAccount($browser);

        $texts = [];

        foreach ([$known, sprintf('nobody+%s@example.com', bin2hex(random_bytes(4)))] as $email) {
            $browser->request('POST', '/login', [
                'email' => $email,
                'password' => 'not-the-password',
                '_csrf_token' => 'csrf-token',
            ], [], ['HTTP_ORIGIN' => 'http://localhost']);

            $texts[] = $browser->followRedirect()->filter('.alert[role="alert"]')->text();
        }

        self::assertSame($texts[0], $texts[1]);
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
