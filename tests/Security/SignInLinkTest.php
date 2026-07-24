<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Security;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\LoginLinkRequest;
use SpeedPuzzling\Web\Entity\UserAccount;
use SpeedPuzzling\Web\Repository\UserAccountRepository;
use SpeedPuzzling\Web\Security\SignInLinkPasswordPrompt;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Mime\Email;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * The magic sign-in link (D6/D18, issue #147): the rescue for everybody whose
 * password manager filed the credential under the Auth0 domain, live from
 * Stage A. What has to hold: the link signs the right person in, works exactly
 * once, and every way of failing looks the same from the outside.
 *
 * Emails are randomized per run - the sign-in link rate limiter's cache is not
 * rolled back between tests or runs (DAMA only wraps the database).
 */
final class SignInLinkTest extends WebTestCase
{
    public function testRequestedLinkSignsTheUserInAndIsBookedAsSingleUse(): void
    {
        $browser = self::createClient();
        $email = $this->seedAccount($browser, 'msp|signin1', 'signin.one');

        $this->requestSignInLink($browser, $email);

        self::assertResponseRedirects('/login-link');

        $signInUrl = $this->lastSignInLinkUrl();
        self::assertStringContainsString('/login-link/check', $signInUrl);

        $browser->request('GET', $signInUrl);

        // Native (non-legacy) account: straight to the profile, no password prompt
        self::assertResponseRedirects('/en/my-profile');

        $token = $browser->getContainer()->get(TokenStorageInterface::class)->getToken();
        self::assertNotNull($token);
        self::assertInstanceOf(UserAccount::class, $token->getUser());
        self::assertSame('msp|signin1', $token->getUserIdentifier());

        $loginLinkRequest = $this->reloadLoginLinkRequest($browser, $signInUrl);
        self::assertNotNull($loginLinkRequest);
        self::assertTrue($loginLinkRequest->isConsumed());
    }

    public function testTheSameLinkCannotBeUsedTwice(): void
    {
        $browser = self::createClient();
        $email = $this->seedAccount($browser, 'msp|signin2', 'signin.two');

        $this->requestSignInLink($browser, $email);
        $signInUrl = $this->lastSignInLinkUrl();

        $browser->request('GET', $signInUrl);
        self::assertResponseRedirects('/en/my-profile');

        // Somebody replaying the link later (forwarded mail, shared device, proxy log)
        $browser->getCookieJar()->clear();
        $browser->request('GET', $signInUrl);

        self::assertResponseRedirects('/login-link');
        self::assertNull($browser->getContainer()->get(TokenStorageInterface::class)->getToken());
    }

    public function testLinkWithoutABookedRequestIsRejected(): void
    {
        $browser = self::createClient();
        $email = $this->seedAccount($browser, 'msp|signin3', 'signin.three');

        $this->requestSignInLink($browser, $email);
        $signInUrl = $this->lastSignInLinkUrl();

        // Correct signature, but no consumption row - garbage collected, or a link
        // signed by something that is not our issuing path
        $entityManager = $browser->getContainer()->get(EntityManagerInterface::class);
        $entityManager->createQuery('DELETE FROM ' . LoginLinkRequest::class)->execute();

        $browser->request('GET', $signInUrl);

        self::assertResponseRedirects('/login-link');
        self::assertNull($browser->getContainer()->get(TokenStorageInterface::class)->getToken());
    }

    public function testTamperedSignatureIsRejectedTheSameWay(): void
    {
        $browser = self::createClient();
        $email = $this->seedAccount($browser, 'msp|signin4', 'signin.four');

        $this->requestSignInLink($browser, $email);
        $signInUrl = $this->lastSignInLinkUrl();

        $browser->request('GET', str_replace('hash=', 'hash=x', $signInUrl));

        self::assertResponseRedirects('/login-link');
        self::assertNull($browser->getContainer()->get(TokenStorageInterface::class)->getToken());

        // A rejected attempt must not burn the real link
        $loginLinkRequest = $this->reloadLoginLinkRequest($browser, $signInUrl);
        self::assertNotNull($loginLinkRequest);
        self::assertFalse($loginLinkRequest->isConsumed());
    }

    public function testUnknownAddressAnswersIdenticallyAndSendsNothing(): void
    {
        $browser = self::createClient();

        $this->requestSignInLink($browser, sprintf('nobody+%s@example.com', bin2hex(random_bytes(4))));

        // Anti-enumeration: same redirect, same flash, no mail
        self::assertResponseRedirects('/login-link');
        self::assertCount(0, self::getMailerMessages());
    }

    public function testRequestPageStaysSessionFreeAndOutOfSharedCaches(): void
    {
        $browser = self::createClient();

        $browser->request('GET', '/login-link');

        self::assertResponseIsSuccessful();
        self::assertSame([], $browser->getResponse()->headers->getCookies());

        // Answers in six languages on one URL (Accept-Language), so it must never
        // be shared-cached - and #164 must not mark it public either
        $cacheControl = (string) $browser->getResponse()->headers->get('Cache-Control');
        self::assertStringContainsString('no-store', $cacheControl);
        self::assertStringNotContainsString('public', $cacheControl);
        self::assertStringContainsString('Accept-Language', (string) $browser->getResponse()->headers->get('Vary'));
    }

    public function testLegacyAccountIsOfferedThePasswordPromptExactlyOnce(): void
    {
        $browser = self::createClient();
        $browser->setServerParameter('HTTP_ORIGIN', 'http://localhost');
        $email = $this->seedAccount($browser, 'auth0|signin5', 'signin.five', legacyAuth0: true);

        $this->requestSignInLink($browser, $email);
        $browser->request('GET', $this->lastSignInLinkUrl());

        // UX funnel §5: users who came from Auth0 get the one-time offer to store a
        // password their manager will file under myspeedpuzzling.com
        self::assertResponseRedirects('/set-password');

        $crawler = $browser->request('GET', '/set-password');
        self::assertResponseIsSuccessful();

        $form = $crawler->filter('form[name="set_password_form"]')->form();
        $form['set_password_form[plainPassword]'] = 'quite-a-long-fresh-password';
        $browser->submit($form);

        self::assertResponseRedirects('/en/my-profile');

        $password = $this->reloadAccountPassword($browser, 'auth0|signin5');
        self::assertNotNull($password);
        self::assertStringStartsWith('$argon2id$', $password);
        self::assertTrue(password_verify('quite-a-long-fresh-password', $password));

        // Offered once: the prompt is not a standing "set a password without the old
        // one" door once its session flag is consumed
        $browser->request('GET', '/set-password');
        self::assertResponseRedirects('/en/my-profile');
    }

    public function testSkippingThePromptKeepsTheOldPassword(): void
    {
        $browser = self::createClient();
        $email = $this->seedAccount($browser, 'auth0|signin6', 'signin.six', legacyAuth0: true);

        $this->requestSignInLink($browser, $email);
        $browser->request('GET', $this->lastSignInLinkUrl());
        self::assertResponseRedirects('/set-password');

        $browser->request('GET', '/set-password?skip=1');

        self::assertResponseRedirects('/en/my-profile');
        self::assertNull($this->reloadAccountPassword($browser, 'auth0|signin6'));
        self::assertNull($browser->getRequest()->getSession()->get(SignInLinkPasswordPrompt::SESSION_KEY));
    }

    public function testPasswordPromptIsUnreachableWithoutAFreshLinkLogin(): void
    {
        $browser = self::createClient();
        $email = $this->seedAccount($browser, 'auth0|signin7', 'signin.seven', legacyAuth0: true);

        $this->requestSignInLink($browser, $email);
        $browser->request('GET', $this->lastSignInLinkUrl());

        // Consume the one-time flag, then come back as a plain logged-in user
        $browser->request('GET', '/set-password?skip=1');

        $browser->request('POST', '/set-password', [
            'set_password_form' => [
                'plainPassword' => 'another-long-password-attempt',
                '_token' => 'csrf-token',
            ],
        ], [], ['HTTP_ORIGIN' => 'http://localhost']);

        self::assertResponseRedirects('/en/my-profile');
        self::assertNull($this->reloadAccountPassword($browser, 'auth0|signin7'));
    }

    private function requestSignInLink(KernelBrowser $browser, string $email): void
    {
        $browser->request('POST', '/login-link', [
            'email' => $email,
            // Stateless CSRF: the rendered token value is the cookie name itself
            '_token' => 'csrf-token',
        ], [], [
            // Stateless CSRF validates same-origin requests via the Origin header -
            // BrowserKit does not send one on its own
            'HTTP_ORIGIN' => 'http://localhost',
        ]);
    }

    private function lastSignInLinkUrl(): string
    {
        $messages = self::getMailerMessages();
        self::assertCount(1, $messages);

        $message = $messages[0];
        self::assertInstanceOf(Email::class, $message);

        // The rendered body, not toString() - quoted-printable would mangle the signature
        $body = (string) $message->getHtmlBody();

        self::assertSame(
            1,
            preg_match('#(https?://[^"\s]*/login-link/check\?[^"\s]+)#', $body, $matches),
            'The sign-in link email must contain a check URL',
        );

        return html_entity_decode($matches[1]);
    }

    private function seedAccount(
        KernelBrowser $browser,
        string $userId,
        string $emailPrefix,
        bool $legacyAuth0 = false,
    ): string {
        $email = sprintf('%s+%s@example.com', $emailPrefix, bin2hex(random_bytes(4)));
        $userAccount = new UserAccount(Uuid::uuid7(), $userId, $email, new DateTimeImmutable());

        if ($legacyAuth0) {
            // Imported account whose hash never made it into the export: exactly the
            // cohort the sign-in link exists for
            $userAccount->applyAuth0Import($email, null, true, new DateTimeImmutable());
        }

        $entityManager = $browser->getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist($userAccount);
        $entityManager->flush();

        return $email;
    }

    private function reloadLoginLinkRequest(KernelBrowser $browser, string $signInUrl): null|LoginLinkRequest
    {
        $query = (string) parse_url($signInUrl, PHP_URL_QUERY);
        parse_str($query, $parameters);
        $hash = $parameters['hash'];
        self::assertIsString($hash);

        $container = $browser->getContainer();
        $container->get(EntityManagerInterface::class)->clear();

        return $container->get(EntityManagerInterface::class)
            ->getRepository(LoginLinkRequest::class)
            ->findOneBy(['hashedToken' => LoginLinkRequest::hashToken($hash)]);
    }

    private function reloadAccountPassword(KernelBrowser $browser, string $userId): null|string
    {
        $container = $browser->getContainer();
        $container->get(EntityManagerInterface::class)->clear();

        $userAccount = $container->get(UserAccountRepository::class)->findByUserId($userId);
        self::assertNotNull($userAccount);

        return $userAccount->password;
    }
}
