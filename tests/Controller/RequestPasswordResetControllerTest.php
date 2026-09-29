<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\UserAccount;
use SpeedPuzzling\Web\Repository\UserAccountRepository;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Mime\Email;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * "Forgot password?" (issue #147). The load-bearing property is that the page
 * gives nothing away: a known address, an unknown address and a throttled
 * repeat must be indistinguishable from the outside (D8), even though only one
 * of them actually sends mail.
 *
 * Success is its own "Check your email" screen (/password-reset/sent), and a
 * completed reset signs the browser in (docs/features/auth-ux-redesign.md).
 *
 * Addresses and client IPs are randomized - the reset limiter's cache is not
 * rolled back between tests or runs (DAMA only wraps the database).
 */
final class RequestPasswordResetControllerTest extends WebTestCase
{
    public function testKnownAddressGetsAResetLinkCarryingAUsableToken(): void
    {
        $browser = self::createClient();
        $email = $this->seedAccount($browser);

        $this->requestReset($browser, $email);

        self::assertResponseRedirects('/password-reset/sent', 303);

        $messages = self::getMailerMessages();
        self::assertCount(1, $messages);
        self::assertInstanceOf(Email::class, $messages[0]);

        $body = (string) $messages[0]->getHtmlBody();
        self::assertSame(
            1,
            preg_match('#/password-reset/([0-9a-f]{64})#', $body, $matches),
            'The reset email must carry a link with a 64-hex token',
        );

        // The token page opens, so the token the mail carries is the one we stored
        $browser->request('GET', '/password-reset/' . $matches[1]);
        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('does not work', (string) $browser->getResponse()->getContent());
    }

    public function testTheMailedLinkActuallyResetsThePasswordAndThenDies(): void
    {
        $browser = self::createClient();
        $email = $this->seedAccount($browser);

        $this->requestReset($browser, $email);

        $messages = self::getMailerMessages();
        self::assertInstanceOf(Email::class, $messages[0]);
        self::assertSame(
            1,
            preg_match('#/password-reset/([0-9a-f]{64})#', (string) $messages[0]->getHtmlBody(), $matches),
        );
        $resetUrl = '/password-reset/' . $matches[1];

        $crawler = $browser->request('GET', $resetUrl);

        // The account's address rides along as the form's username, so the password
        // manager files the new password under the right account
        $username = $crawler->filter('form input[type="email"][autocomplete="username"]');
        self::assertSame($email, $username->attr('value'));
        self::assertCount(1, $crawler->filter('.password-field button.password-toggle'));

        $form = $crawler->selectButton('Save and sign in')->form();
        $browser->submit($form, [$form->getName() . '[plainPassword]' => 'a-brand-new-passphrase']);

        // D4: whoever holds a live token controls the mailbox - the same proof a
        // sign-in link accepts - so the browser is signed in right away
        self::assertResponseRedirects('/en/my-profile', 303);
        $token = $browser->getContainer()->get(TokenStorageInterface::class)->getToken();
        self::assertNotNull($token);
        self::assertInstanceOf(UserAccount::class, $token->getUser());
        self::assertSame($email, $token->getUser()->email);

        // ... with the always-on remember-me, like every other sign-in
        self::assertNotNull($browser->getCookieJar()->get('REMEMBERME'));

        // ... and recorded next to the reset itself
        $events = $browser->getContainer()->get(Connection::class)->fetchFirstColumn(
            'SELECT log.event_type FROM auth_audit_log log JOIN user_account account ON account.id = log.user_account_id WHERE account.email = ?',
            [$email],
        );
        self::assertContains('password_reset_completed', $events);
        self::assertContains('login_success', $events);

        $hasher = $browser->getContainer()->get(UserPasswordHasherInterface::class);
        $userAccount = $browser->getContainer()->get(UserAccountRepository::class)->findByEmail($email);
        self::assertNotNull($userAccount);
        self::assertTrue($hasher->isPasswordValid($userAccount, 'a-brand-new-passphrase'));
        self::assertFalse($hasher->isPasswordValid($userAccount, 'the-real-password'));

        // Single use: the same link must not open a second time
        $browser->request('GET', $resetUrl);
        self::assertStringContainsString('does not work', (string) $browser->getResponse()->getContent());
    }

    public function testUnknownAddressLooksExactlyLikeAKnownOne(): void
    {
        $browser = self::createClient();
        $known = $this->seedAccount($browser);

        $unknown = sprintf('nobody+%s@example.com', bin2hex(random_bytes(4)));

        $this->requestReset($browser, $known);
        $knownResponse = $browser->followRedirect()->filter('main')->text();

        $this->requestReset($browser, $unknown);
        $unknownResponse = $browser->followRedirect()->filter('main')->text();

        // The screen repeats the typed address back - everything else is identical
        self::assertStringContainsString($known, $knownResponse);
        self::assertSame(str_replace($known, '', $knownResponse), str_replace($unknown, '', $unknownResponse));
    }

    public function testUnknownAddressSendsNoMail(): void
    {
        $browser = self::createClient();

        $this->requestReset($browser, sprintf('nobody+%s@example.com', bin2hex(random_bytes(4))));

        self::assertResponseRedirects('/password-reset/sent', 303);
        self::assertCount(0, self::getMailerMessages());
    }

    /**
     * "Send a new link" on the check-your-email screen must really send one
     * (the rate limiters cap the volume); the earlier link keeps working until
     * any of them is used
     */
    public function testResendMintsAnotherWorkingLink(): void
    {
        $browser = self::createClient();
        $email = $this->seedAccount($browser);

        $this->requestReset($browser, $email);
        self::assertCount(1, self::getMailerMessages());
        $browser->followRedirect();

        $this->requestReset($browser, $email, resend: true);
        self::assertCount(1, self::getMailerMessages());

        $crawler = $browser->followRedirect();
        self::assertSame('We sent you a new link.', trim($crawler->filter('.alert-success[role="status"]')->text()));
        self::assertSame($email, trim($crawler->filter('.auth-sent-email')->text()));
    }

    public function testEmptyOrInvalidAddressIsAnsweredWith422(): void
    {
        $browser = self::createClient();

        foreach (['', 'not-an-email'] as $email) {
            $this->requestReset($browser, $email);

            self::assertResponseStatusCodeSame(422);
            self::assertSame('true', $browser->getCrawler()->filter('#password-reset-email')->attr('aria-invalid'));
        }

        self::assertCount(0, self::getMailerMessages());
    }

    /**
     * "sent" also matches the loose token requirement of /password-reset/{token}
     */
    public function testTheSentRouteIsNotMistakenForAToken(): void
    {
        $browser = self::createClient();

        $browser->request('GET', '/password-reset/sent');

        // No flash: back to the form - not the "this link does not work" page
        self::assertResponseRedirects('/password-reset', 303);
    }

    public function testAnInvalidNewPasswordIsAnsweredWith422(): void
    {
        $browser = self::createClient();
        $email = $this->seedAccount($browser);

        $this->requestReset($browser, $email);
        $messages = self::getMailerMessages();
        self::assertInstanceOf(Email::class, $messages[0]);
        self::assertSame(1, preg_match('#/password-reset/([0-9a-f]{64})#', (string) $messages[0]->getHtmlBody(), $matches));

        $crawler = $browser->request('GET', '/password-reset/' . $matches[1]);
        $form = $crawler->selectButton('Save and sign in')->form();
        $browser->submit($form, [$form->getName() . '[plainPassword]' => 'short']);

        self::assertResponseStatusCodeSame(422);
        self::assertNull($browser->getContainer()->get(TokenStorageInterface::class)->getToken());
    }

    public function testTheFormPageStartsNoSessionAndStaysOutOfSharedCaches(): void
    {
        $browser = self::createClient();

        $browser->request('GET', '/password-reset');

        // #164: rendering the CSRF token here must not reach for the session, which
        // is why 'request_password_reset' is in stateless_token_ids
        self::assertSame([], $browser->getResponse()->headers->getCookies());

        $cacheControl = (string) $browser->getResponse()->headers->get('Cache-Control');
        self::assertStringContainsString('no-store', $cacheControl);
        self::assertStringNotContainsString('public', $cacheControl);
    }

    public function testAMangledLinkGetsTheFriendlyPageRatherThanA404(): void
    {
        $browser = self::createClient();

        // A mail client wrapped the URL and cut the token in half
        $browser->request('GET', '/password-reset/' . str_repeat('a', 30));

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('does not work', (string) $browser->getResponse()->getContent());
    }

    public function testTheTokenPageDoesNotLeakTheTokenThroughTheReferer(): void
    {
        $browser = self::createClient();

        $browser->request('GET', '/password-reset/' . str_repeat('a', 64));

        self::assertSame('no-referrer', $browser->getResponse()->headers->get('Referrer-Policy'));
    }

    private function requestReset(KernelBrowser $browser, string $email, bool $resend = false): void
    {
        // Fresh client IP per request: the per-IP limiter's cache outlives the test
        $browser->setServerParameter('REMOTE_ADDR', sprintf('203.0.113.%d', random_int(1, 254)));

        if ($resend) {
            // What the check-your-email screen's "Send a new link" posts
            $browser->request('POST', '/password-reset', [
                'email' => $email,
                'resend' => '1',
                '_token' => 'csrf-token',
            ], [], ['HTTP_ORIGIN' => 'http://localhost']);

            return;
        }

        $crawler = $browser->request('GET', '/password-reset');
        $form = $crawler->selectButton('Email me a reset link')->form();

        $browser->submit($form, ['email' => $email]);
    }

    private function seedAccount(KernelBrowser $browser): string
    {
        $email = sprintf('reset.page+%s@example.com', bin2hex(random_bytes(4)));
        $userAccount = new UserAccount(Uuid::uuid7(), 'msp|' . bin2hex(random_bytes(4)), $email, new DateTimeImmutable());
        $userAccount->changePassword(password_hash('the-real-password', PASSWORD_ARGON2ID));

        $entityManager = $browser->getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist($userAccount);
        $entityManager->flush();

        return $email;
    }
}
