<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\UserAccount;
use SpeedPuzzling\Web\Repository\UserAccountRepository;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Mime\Email;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * "Forgot password?" (issue #147). The load-bearing property is that the page
 * gives nothing away: a known address, an unknown address and a throttled
 * repeat must be indistinguishable from the outside (D8), even though only one
 * of them actually sends mail.
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

        self::assertResponseRedirects('/password-reset');

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
        $form = $crawler->selectButton('Save new password')->form();
        $browser->submit($form, [$form->getName() . '[plainPassword]' => 'a-brand-new-passphrase']);

        // Proving control of the mailbox resets the password; it does not sign the
        // browser in - the user lands on the login page and uses the new password
        self::assertResponseRedirects('/login');

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

        $this->requestReset($browser, $known);
        $knownResponse = $browser->followRedirect()->filter('main')->text();

        $this->requestReset($browser, sprintf('nobody+%s@example.com', bin2hex(random_bytes(4))));
        $unknownResponse = $browser->followRedirect()->filter('main')->text();

        self::assertSame($knownResponse, $unknownResponse);
    }

    public function testUnknownAddressSendsNoMail(): void
    {
        $browser = self::createClient();

        $this->requestReset($browser, sprintf('nobody+%s@example.com', bin2hex(random_bytes(4))));

        self::assertResponseRedirects('/password-reset');
        self::assertCount(0, self::getMailerMessages());
    }

    /**
     * A second request while one is still live is silently refused by the handler.
     * The page must not say so - "we already sent you one" is a positive answer to
     * "does this address have an account".
     */
    public function testThrottledRepeatLooksLikeSuccessAndSendsNothingExtra(): void
    {
        $browser = self::createClient();
        $email = $this->seedAccount($browser);

        // Asserted before following the redirect: getMailerMessages() reads the
        // profile of the LAST request, so the redirect target would report zero
        $this->requestReset($browser, $email);
        self::assertCount(1, self::getMailerMessages());
        $firstResponse = $browser->followRedirect()->filter('main')->text();

        $this->requestReset($browser, $email);
        self::assertCount(0, self::getMailerMessages());
        $secondResponse = $browser->followRedirect()->filter('main')->text();

        self::assertSame($firstResponse, $secondResponse);
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

    private function requestReset(KernelBrowser $browser, string $email): void
    {
        // Fresh client IP per request: the per-IP limiter's cache outlives the test
        $browser->setServerParameter('REMOTE_ADDR', sprintf('203.0.113.%d', random_int(1, 254)));

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
