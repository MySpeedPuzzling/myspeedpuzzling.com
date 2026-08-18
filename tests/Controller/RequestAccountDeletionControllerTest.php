<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Entity\UserAccount;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Mime\Email;

/**
 * Step one of the two-step deletion (docs/features/account-deletion.md): the
 * danger zone button. What matters here is that it mails a working link to the
 * account's own address, that it deletes nothing by itself, and that nobody
 * but the signed-in owner can trigger it.
 *
 * Accounts are seeded fresh with random user ids: the per-account rate limiter
 * (3 / 15 min) is not rolled back between runs, and a shared fixture user id
 * would trip it on the second local run.
 */
final class RequestAccountDeletionControllerTest extends WebTestCase
{
    public function testTheDangerZoneRendersOnTheProfileSettingsPage(): void
    {
        $browser = self::createClient();
        $userAccount = $this->seedNativeAccount($browser);
        $browser->loginUser($userAccount, 'main');

        $crawler = $browser->request('GET', '/en/edit-profile');

        self::assertResponseIsSuccessful();
        $dangerZone = $crawler->filter('#danger-zone');
        self::assertCount(1, $dangerZone);
        self::assertStringContainsString('Danger zone', $dangerZone->text());
        self::assertStringContainsString($userAccount->email, $dangerZone->text(), 'It tells the user where the link will go');
        self::assertCount(1, $dangerZone->filter('form[action="/request-account-deletion"]'));
        self::assertCount(1, $dangerZone->filter('a[href*="/export-puzzler-data/"]'), 'Export CTA next to the danger zone');
    }

    public function testAnonymousVisitorsAreBouncedWithoutASession(): void
    {
        $browser = self::createClient();

        $browser->request('POST', '/request-account-deletion');

        self::assertResponseStatusCodeSame(302);
        self::assertSame(
            [],
            array_filter(
                $browser->getResponse()->headers->getCookies(),
                static fn ($cookie): bool => $cookie->getName() === 'PHPSESSID',
            ),
        );
        self::assertCount(0, self::getMailerMessages());
    }

    public function testAMissingCsrfTokenIsRefused(): void
    {
        $browser = self::createClient();
        $browser->loginUser($this->seedNativeAccount($browser), 'main');

        $browser->request('POST', '/request-account-deletion');

        self::assertResponseStatusCodeSame(403);
        self::assertCount(0, self::getMailerMessages());
    }

    public function testTheButtonMailsAWorkingLinkAndDeletesNothing(): void
    {
        $browser = self::createClient();
        $userAccount = $this->seedNativeAccount($browser);
        $browser->loginUser($userAccount, 'main');

        $this->pressTheButton($browser);

        self::assertResponseRedirects('/en/edit-profile');

        $messages = self::getMailerMessages();
        self::assertCount(1, $messages);
        self::assertInstanceOf(Email::class, $messages[0]);
        self::assertSame($userAccount->email, $messages[0]->getTo()[0]->getAddress(), 'Only ever to the account address');

        $body = (string) $messages[0]->getHtmlBody();
        self::assertSame(
            1,
            preg_match('#/delete-account/([0-9a-f]{64})#', $body, $matches),
            'The mail must carry a link with a 64-hex token',
        );
        self::assertStringContainsString('/export-puzzler-data/', $body, 'The mail carries the export CTA');

        $crawler = $browser->followRedirect();
        self::assertStringContainsString('sent a confirmation link to ' . $userAccount->email, $crawler->filter('main')->text());

        // Nothing is deleted by asking, and the mailed link opens the last-chance page
        self::assertNotNull($this->reload($browser, $userAccount));

        $browser->request('GET', '/delete-account/' . $matches[1]);
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('permanently delete my account', (string) $browser->getResponse()->getContent());
        self::assertNotNull($this->reload($browser, $userAccount), 'Opening the link deletes nothing either');
    }

    /**
     * Window A: a legacy Auth0 session has no user_account row to bind a token to,
     * so the endpoint has nothing to offer it - it must redirect, not blow up.
     */
    public function testALegacyAuth0SessionIsSentBackWithoutMail(): void
    {
        $browser = self::createClient();
        TestingLogin::asAuth0Player($browser, PlayerFixture::PLAYER_REGULAR);

        $this->pressTheButton($browser);

        self::assertResponseRedirects('/en/edit-profile');
        self::assertCount(0, self::getMailerMessages());
    }

    /**
     * Through the real page: the button's form carries a session-backed CSRF token,
     * so it is read off the rendered danger zone rather than forged
     */
    private function pressTheButton(KernelBrowser $browser): void
    {
        $crawler = $browser->request('GET', '/en/edit-profile');
        self::assertResponseIsSuccessful();

        $form = $crawler->filter('#danger-zone form[action="/request-account-deletion"]')->form();
        $browser->submit($form, [], ['HTTP_ACCEPT_LANGUAGE' => 'en']);
    }

    private function reload(KernelBrowser $browser, UserAccount $userAccount): null|UserAccount
    {
        return $browser->getContainer()->get(EntityManagerInterface::class)->find(UserAccount::class, $userAccount->id);
    }

    private function seedNativeAccount(KernelBrowser $browser): UserAccount
    {
        $email = sprintf('danger.zone+%s@example.com', bin2hex(random_bytes(4)));
        $userId = 'msp|' . bin2hex(random_bytes(4));

        $userAccount = new UserAccount(Uuid::uuid7(), $userId, $email, new DateTimeImmutable());
        $userAccount->changePassword(password_hash('a-properly-long-passphrase', PASSWORD_ARGON2ID));

        $entityManager = $browser->getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist($userAccount);
        $entityManager->persist(
            new Player(Uuid::uuid7(), 'DZ' . bin2hex(random_bytes(2)), $userId, $email, 'Leaving Soon', new DateTimeImmutable()),
        );
        $entityManager->flush();

        return $userAccount;
    }
}
