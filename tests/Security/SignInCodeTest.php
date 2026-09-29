<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Security;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\LoginLinkRequest;
use SpeedPuzzling\Web\Entity\UserAccount;
use SpeedPuzzling\Web\Services\SignInCodeHasher;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Mime\Email;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * The 6-digit code in the sign-in e-mail (docs/features/auth-ux-redesign.md
 * phase 2): signs in the browser that asked for it, once, together with the
 * link from the same mail; five wrong tries kill the code (not the link).
 *
 * Emails and client IPs are randomized per test - the rate limiters' cache is
 * not rolled back between tests or runs (DAMA only wraps the database).
 */
final class SignInCodeTest extends WebTestCase
{
    public function testTheMailCarriesTheCodeNextToTheLink(): void
    {
        $browser = self::createClient();
        $email = $this->seedAccount($browser, 'msp|code1', 'code.one');

        $this->requestSignIn($browser, $email);

        $message = $this->lastMail();
        $code = $this->codeFrom($message);

        // In the subject, so the notification alone is enough
        self::assertStringStartsWith($code . ' ', (string) $message->getSubject());
        self::assertStringContainsString($code, (string) $message->getHtmlBody());
        self::assertStringContainsString('/login-link/check?', (string) $message->getHtmlBody());

        // Only a salted HMAC of it is stored
        $row = $this->loginLinkRequestFor($browser, $email);
        self::assertNotNull($row->codeHash);
        self::assertNotSame($code, $row->codeHash);
        self::assertStringNotContainsString($code, $row->codeHash);
    }

    public function testTheScreenOffersTheCodeInput(): void
    {
        $browser = self::createClient();
        $email = $this->seedAccount($browser, 'msp|code2', 'code.two');

        $this->requestSignIn($browser, $email);
        $crawler = $browser->followRedirect();

        $input = $crawler->filter('form[action="/verify-code"] input[name="code"]');
        self::assertCount(1, $input);
        self::assertSame('numeric', $input->attr('inputmode'));
        self::assertSame('one-time-code', $input->attr('autocomplete'));
        self::assertSame('[0-9]*', $input->attr('pattern'));
        self::assertSame('6', $input->attr('maxlength'));
        self::assertSame('go', $input->attr('enterkeyhint'));
        self::assertSame('sign-in-code', $crawler->filter('form[action="/verify-code"]')->attr('data-controller'));

        // iOS Password AutoFill must not take this for a login form (it popped
        // "fill username" on load): no autofocus, no login wording, no other
        // text input, nothing asking for a username or password on the page
        self::assertNull($input->attr('autofocus'));
        self::assertSame('one-time-code', $input->attr('id'));
        self::assertSame('Verify code', trim($crawler->filter('form[action="/verify-code"] button[type="submit"]')->text()));
        self::assertSame('Verifying…', $crawler->filter('form[action="/verify-code"] button[type="submit"]')->attr('data-turbo-submits-with'));
        self::assertCount(1, $crawler->filter('form[action="/verify-code"] input:not([type="hidden"])'));
        self::assertCount(0, $crawler->filter('[autofocus], input[type="password"], input[type="email"], [autocomplete~="username"], [autocomplete~="email"], [autocomplete~="current-password"]'));

        // Switching to the mail app may reload the page (in-app browsers do):
        // the screen stays while the sign-in is pending
        $crawler = $browser->request('GET', '/login-link/sent');
        self::assertResponseIsSuccessful();
        self::assertSame($email, trim($crawler->filter('.auth-sent-email')->text()));
        self::assertCount(1, $crawler->filter('input[name="code"]'));
    }

    public function testTheRightCodeSignsInThisBrowserAndLandsWhereItWasHeaded(): void
    {
        $browser = self::createClient();
        $email = $this->seedAccount($browser, 'msp|code3', 'code.three');

        $this->requestSignIn($browser, $email, ['return' => '/en/puzzle']);
        $code = $this->codeFrom($this->lastMail());
        $browser->followRedirect();

        // Pasted with a space ("123 456") - normalised
        $this->submitCode($browser, substr($code, 0, 3) . ' ' . substr($code, 3), '/en/puzzle');

        self::assertResponseRedirects('/en/puzzle', 303);
        $token = $browser->getContainer()->get(TokenStorageInterface::class)->getToken();
        self::assertNotNull($token);
        self::assertSame('msp|code3', $token->getUserIdentifier());
        // Always-on remember-me, like every other sign-in
        self::assertNotNull($browser->getCookieJar()->get('REMEMBERME'));

        $row = $this->loginLinkRequestFor($browser, $email);
        self::assertTrue($row->isConsumed());
        self::assertNotNull($row->codeUsedAt);

        $events = $this->auditEvents($browser, $email);
        self::assertContains('sign_in_code_used', $events);
    }

    /**
     * Production 2026-09-30: iOS filled the code from Mail and the form went out
     * twice with the same pre-login session cookie. The second POST found nothing
     * pending and showed "no longer valid" although the first had signed in.
     */
    public function testASecondSubmitOfTheSameCodeLandsWhereTheFirstDid(): void
    {
        $browser = self::createClient();
        $email = $this->seedAccount($browser, 'msp|code14', 'code.fourteen');

        $this->requestSignIn($browser, $email, ['return' => '/en/puzzle']);
        $code = $this->codeFrom($this->lastMail());
        $browser->followRedirect();

        // Both submits leave before the first answer arrives: same cookies
        $cookiesBeforeSignIn = $browser->getCookieJar()->all();

        $this->submitCode($browser, $code, '/en/puzzle');
        self::assertResponseRedirects('/en/puzzle', 303);

        $browser->getCookieJar()->clear();

        foreach ($cookiesBeforeSignIn as $cookie) {
            $browser->getCookieJar()->set($cookie);
        }

        $this->submitCode($browser, $code, '/en/puzzle');
        self::assertResponseRedirects('/en/puzzle', 303);
        // No failure: the fresh remember-me cookie must survive the duplicate
        foreach ($browser->getResponse()->headers->getCookies() as $cookie) {
            self::assertNotSame('REMEMBERME', $cookie->getName());
        }

        $events = $this->auditEvents($browser, $email);
        self::assertContains('sign_in_code_used', $events);
        self::assertNotContains('sign_in_code_failed', $events);

        // Another browser without that marker still gets the ordinary answer
        $this->inAnotherBrowser($browser, function () use ($browser, $code): void {
            $this->submitCode($browser, $code, '/en/puzzle');

            self::assertResponseRedirects('/login-link?return=/en/puzzle', 303);
            self::assertNull($browser->getContainer()->get(TokenStorageInterface::class)->getToken());
        });
    }

    public function testASubmitAfterTheSignInHasLandedRedirectsToo(): void
    {
        $browser = self::createClient();
        $email = $this->seedAccount($browser, 'msp|code15', 'code.fifteen');

        $this->requestSignIn($browser, $email, ['return' => '/en/puzzle']);
        $code = $this->codeFrom($this->lastMail());
        $browser->followRedirect();

        $this->submitCode($browser, $code, '/en/puzzle');
        self::assertResponseRedirects('/en/puzzle', 303);

        // The straggler carries the new, signed-in cookies
        $this->submitCode($browser, $code, '/en/puzzle');
        self::assertResponseRedirects('/en/puzzle', 303);
        self::assertNotNull($browser->getCookieJar()->get('REMEMBERME'));
        self::assertNotContains('sign_in_code_failed', $this->auditEvents($browser, $email));
    }

    public function testEmailOnlyPagesOfferEmailsNotPasswords(): void
    {
        $browser = self::createClient();

        // iOS offers saved passwords for autocomplete="username" - there is no password here
        foreach (['/login-link', '/password-reset'] as $page) {
            $crawler = $browser->request('GET', $page);
            self::assertResponseIsSuccessful();
            self::assertSame('email', $crawler->filter('input[name="email"]')->attr('autocomplete'), $page);
            self::assertCount(0, $crawler->filter('input[autocomplete="username"], input[autocomplete="current-password"], input[type="password"]'), $page);
        }

        $email = $this->seedAccount($browser, 'msp|code16', 'code.sixteen');
        $this->requestSignIn($browser, $email);
        $crawler = $browser->followRedirect();

        self::assertCount(1, $crawler->filter('input[autocomplete="one-time-code"]'));
        self::assertCount(0, $crawler->filter('input[autocomplete="username"], input[autocomplete="current-password"], input[type="password"]'));
    }

    public function testWrongCodesCountDownThenKillTheCodeButNotTheLink(): void
    {
        $browser = self::createClient();
        $email = $this->seedAccount($browser, 'msp|code4', 'code.four');

        $this->requestSignIn($browser, $email);
        $message = $this->lastMail();
        $code = $this->codeFrom($message);
        $wrong = $this->otherCode($code);
        $browser->followRedirect();

        foreach ([4, 3, 2, 1] as $left) {
            $crawler = $this->submitCode($browser, $wrong);

            // Turbo Drive drops a 200 answer to a form POST
            self::assertResponseStatusCodeSame(422);
            $expected = $left === 1 ? "That code isn't right. 1 try left." : sprintf("That code isn't right. %d tries left.", $left);
            self::assertSame($expected, trim($crawler->filter('#one-time-code-error')->text()));
            self::assertSame('true', $crawler->filter('input[name="code"]')->attr('aria-invalid'));
        }

        $crawler = $this->submitCode($browser, $wrong);
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('Too many tries', $crawler->filter('.alert[role="alert"]')->text());
        self::assertCount(0, $crawler->filter('input[name="code"]'));

        // Even the right code is dead now - this browser has nothing pending any more
        $this->submitCode($browser, $code);
        self::assertResponseRedirects('/login-link', 303);
        self::assertNull($browser->getContainer()->get(TokenStorageInterface::class)->getToken());

        $row = $this->loginLinkRequestFor($browser, $email);
        self::assertSame(5, $row->codeFailedAttempts);
        self::assertFalse($row->isConsumed());

        // ... the link from the same mail still signs in
        $this->followSignInLink($browser, $this->linkFrom($message));
        self::assertResponseRedirects('/en/my-profile');
        self::assertNotNull($browser->getContainer()->get(TokenStorageInterface::class)->getToken());

        // A wrong code is audited with its reason, never with the typed value
        $metadata = $browser->getContainer()->get(Connection::class)->fetchFirstColumn(
            "SELECT metadata FROM auth_audit_log WHERE event_type = 'sign_in_code_failed' AND email = ?",
            [$email],
        );
        self::assertCount(5, $metadata);
        foreach ($metadata as $json) {
            self::assertIsString($json);
            self::assertStringNotContainsString($wrong, $json);
            self::assertStringNotContainsString($code, $json);
        }
        self::assertStringContainsString('locked_out', implode(' ', array_filter($metadata, is_string(...))));
    }

    public function testTheCodeIsSingleUseAndTakesTheLinkWithIt(): void
    {
        $browser = self::createClient();
        $email = $this->seedAccount($browser, 'msp|code5', 'code.five');

        $this->requestSignIn($browser, $email);
        $message = $this->lastMail();
        $browser->followRedirect();

        $this->submitCode($browser, $this->codeFrom($message));
        self::assertResponseRedirects('/en/my-profile', 303);

        // The link from the same mail, seconds later, in another browser: no
        // scanner grace window once the code has signed in
        $this->inAnotherBrowser($browser, function () use ($browser, $message): void {
            $this->followSignInLink($browser, $this->linkFrom($message));
            self::assertResponseRedirects('/login-link');
            self::assertNull($browser->getContainer()->get(TokenStorageInterface::class)->getToken());
        });
    }

    public function testAUsedLinkTakesTheCodeWithIt(): void
    {
        $browser = self::createClient();
        $email = $this->seedAccount($browser, 'msp|code6', 'code.six');

        $this->requestSignIn($browser, $email);
        $message = $this->lastMail();
        $browser->followRedirect();

        // The link opened in the phone's own browser...
        $this->inAnotherBrowser($browser, function () use ($browser, $message): void {
            $this->followSignInLink($browser, $this->linkFrom($message));
            self::assertResponseRedirects('/en/my-profile');
        });

        // ... so the code in the in-app browser is spent
        $crawler = $this->submitCode($browser, $this->codeFrom($message));
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('already been used', $crawler->filter('.alert[role="alert"]')->text());
        self::assertNull($browser->getContainer()->get(TokenStorageInterface::class)->getToken());
    }

    public function testAnExpiredCodeSaysSo(): void
    {
        $browser = self::createClient();
        $email = $this->seedAccount($browser, 'msp|code7', 'code.seven');

        $this->requestSignIn($browser, $email);
        $code = $this->codeFrom($this->lastMail());
        $browser->followRedirect();

        $row = $this->loginLinkRequestFor($browser, $email);
        $entityManager = $browser->getContainer()->get(EntityManagerInterface::class);
        $entityManager->createQueryBuilder()
            ->update(LoginLinkRequest::class, 'l')
            ->set('l.expiresAt', ':expiresAt')
            ->where('l.id = :id')
            ->setParameter('expiresAt', new DateTimeImmutable('-1 minute'))
            ->setParameter('id', $row->id)
            ->getQuery()
            ->execute();

        $crawler = $this->submitCode($browser, $code);

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('expired', $crawler->filter('.alert[role="alert"]')->text());
        self::assertNull($browser->getContainer()->get(TokenStorageInterface::class)->getToken());
    }

    public function testANewRequestReplacesTheCodeInThisBrowser(): void
    {
        $browser = self::createClient();
        $email = $this->seedAccount($browser, 'msp|code8', 'code.eight');

        $this->requestSignIn($browser, $email);
        $firstCode = $this->codeFrom($this->lastMail());
        $browser->followRedirect();

        $this->requestSignIn($browser, $email, ['resend' => '1']);
        $secondCode = $this->codeFrom($this->lastMail());
        $crawler = $browser->followRedirect();
        self::assertSame('We sent a new code. The old one no longer works here.', trim($crawler->filter('.alert-success[role="status"]')->text()));

        if ($firstCode !== $secondCode) {
            // The first mail's code belongs to another pending request
            $this->submitCode($browser, $firstCode);
            self::assertResponseStatusCodeSame(422);
            self::assertNull($browser->getContainer()->get(TokenStorageInterface::class)->getToken());
        }

        $this->submitCode($browser, $secondCode);
        self::assertResponseRedirects('/en/my-profile', 303);
    }

    public function testTheCodeOnlyWorksInTheBrowserThatAskedForIt(): void
    {
        $browser = self::createClient();
        $email = $this->seedAccount($browser, 'msp|code9', 'code.nine');

        $this->requestSignIn($browser, $email);
        $code = $this->codeFrom($this->lastMail());

        $this->inAnotherBrowser($browser, function () use ($browser, $code): void {
            $this->submitCode($browser, $code);

            self::assertResponseRedirects('/login-link', 303);
            self::assertNull($browser->getContainer()->get(TokenStorageInterface::class)->getToken());
        });

        self::assertFalse($this->loginLinkRequestFor($browser, $email)->isConsumed());
    }

    public function testAnUnknownAddressCountsDownExactlyLikeAKnownOne(): void
    {
        $browser = self::createClient();

        $this->requestSignIn($browser, sprintf('nobody+%s@example.com', bin2hex(random_bytes(4))));
        self::assertCount(0, self::getMailerMessages());
        $crawler = $browser->followRedirect();
        self::assertCount(1, $crawler->filter('input[name="code"]'));

        $crawler = $this->submitCode($browser, '123456');

        self::assertResponseStatusCodeSame(422);
        self::assertSame("That code isn't right. 4 tries left.", trim($crawler->filter('#one-time-code-error')->text()));
    }

    public function testSomethingThatIsNotSixDigitsCostsNoTry(): void
    {
        $browser = self::createClient();
        $email = $this->seedAccount($browser, 'msp|code10', 'code.ten');

        $this->requestSignIn($browser, $email);
        $browser->followRedirect();

        foreach (['', '12345', '12a456', '1234567'] as $input) {
            $crawler = $this->submitCode($browser, $input);

            self::assertResponseStatusCodeSame(422);
            self::assertSame('Enter the 6 digits from the email.', trim($crawler->filter('#one-time-code-error')->text()));
        }

        self::assertSame(0, $this->loginLinkRequestFor($browser, $email)->codeFailedAttempts);
    }

    public function testGuessingAcrossFreshCodesIsThrottledPerAddress(): void
    {
        $browser = self::createClient();
        $email = $this->seedAccount($browser, 'msp|code11', 'code.eleven');

        // Two requests x 5 wrong codes = the per-address budget (10 / 15 min)
        for ($round = 0; $round < 2; $round++) {
            $this->requestSignIn($browser, $email);
            $code = $this->codeFrom($this->lastMail());

            for ($attempt = 0; $attempt < 5; $attempt++) {
                $this->submitCode($browser, $this->otherCode($code), clientIp: sprintf('203.0.113.%d', random_int(1, 254)));
            }
        }

        // A third, fresh code - the right one even - waits for the limiter
        $this->requestSignIn($browser, $email);
        $code = $this->codeFrom($this->lastMail());
        $crawler = $this->submitCode($browser, $code, clientIp: sprintf('203.0.113.%d', random_int(1, 254)));

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('Too many tries', $crawler->filter('#one-time-code-error')->text());
        self::assertNull($browser->getContainer()->get(TokenStorageInterface::class)->getToken());
        self::assertFalse($this->loginLinkRequestFor($browser, $email)->isConsumed());
    }

    public function testCodesAreSixDigitsAndPastedSeparatorsAreIgnored(): void
    {
        for ($i = 0; $i < 50; $i++) {
            self::assertMatchesRegularExpression('/^\d{6}$/', SignInCodeHasher::generate());
        }

        self::assertSame('123456', SignInCodeHasher::normalize(' 123 456 '));
        self::assertSame('012345', SignInCodeHasher::normalize("012\u{00A0}345"));
        self::assertSame('123456', SignInCodeHasher::normalize('123-456'));
        self::assertNull(SignInCodeHasher::normalize('12345'));
        self::assertNull(SignInCodeHasher::normalize('１２３４５６'));
    }

    /**
     * @param array<string, string> $extra
     */
    private function requestSignIn(KernelBrowser $browser, string $email, array $extra = []): void
    {
        $browser->setServerParameter('REMOTE_ADDR', sprintf('198.51.100.%d', random_int(1, 254)));

        $browser->request('POST', '/login-link', [
            'email' => $email,
            '_token' => 'csrf-token',
            ...$extra,
        ], [], ['HTTP_ORIGIN' => 'http://localhost']);

        self::assertResponseStatusCodeSame(303);
    }

    private function submitCode(KernelBrowser $browser, string $code, null|string $return = null, null|string $clientIp = null): Crawler
    {
        $browser->setServerParameter('REMOTE_ADDR', $clientIp ?? sprintf('192.0.2.%d', random_int(1, 254)));

        $parameters = ['code' => $code, '_token' => 'csrf-token'];

        if ($return !== null) {
            $parameters['return'] = $return;
        }

        return $browser->request('POST', '/verify-code', $parameters, [], ['HTTP_ORIGIN' => 'http://localhost']);
    }

    /**
     * Another browser, same database: the cookie jar (session, pending sign-in)
     * is set aside while $act runs, and restored afterwards.
     */
    private function inAnotherBrowser(KernelBrowser $browser, callable $act): void
    {
        $cookieJar = $browser->getCookieJar();
        $saved = $cookieJar->all();
        $cookieJar->clear();

        $act();

        $cookieJar->clear();

        foreach ($saved as $cookie) {
            $cookieJar->set($cookie);
        }
    }

    private function followSignInLink(KernelBrowser $browser, string $signInUrl): void
    {
        $crawler = $browser->request('GET', $signInUrl);
        self::assertResponseIsSuccessful();

        $browser->submit($crawler->filter('#sign-in-link-check-form')->form());
    }

    private function lastMail(): Email
    {
        $messages = self::getMailerMessages();
        self::assertNotEmpty($messages);

        $message = end($messages);
        self::assertInstanceOf(Email::class, $message);

        return $message;
    }

    private function codeFrom(Email $message): string
    {
        self::assertSame(1, preg_match('/^(\d{6}) /', (string) $message->getSubject(), $matches));

        return $matches[1];
    }

    private function linkFrom(Email $message): string
    {
        self::assertSame(1, preg_match('#(https?://[^"\s]*/login-link/check\?[^"\s]+)#', (string) $message->getHtmlBody(), $matches));

        return html_entity_decode($matches[1]);
    }

    private function otherCode(string $code): string
    {
        return str_pad((string) ((((int) $code) + 1) % 1000000), 6, '0', STR_PAD_LEFT);
    }

    private function seedAccount(KernelBrowser $browser, string $userId, string $emailPrefix): string
    {
        $email = sprintf('%s+%s@example.com', $emailPrefix, bin2hex(random_bytes(4)));

        $entityManager = $browser->getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist(new UserAccount(Uuid::uuid7(), $userId, $email, new DateTimeImmutable()));
        $entityManager->flush();

        return $email;
    }

    private function loginLinkRequestFor(KernelBrowser $browser, string $email): LoginLinkRequest
    {
        $entityManager = $browser->getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();

        $rows = $entityManager->createQueryBuilder()
            ->select('l')
            ->from(LoginLinkRequest::class, 'l')
            ->join('l.userAccount', 'a')
            ->where('a.email = :email')
            ->orderBy('l.requestedAt', 'DESC')
            ->addOrderBy('l.id', 'DESC')
            ->setParameter('email', $email)
            ->setMaxResults(1)
            ->getQuery()
            ->getResult();

        self::assertCount(1, $rows);

        return $rows[0];
    }

    /**
     * @return array<mixed>
     */
    private function auditEvents(KernelBrowser $browser, string $email): array
    {
        return $browser->getContainer()->get(Connection::class)->fetchFirstColumn(
            'SELECT event_type FROM auth_audit_log WHERE email = ?',
            [$email],
        );
    }
}
