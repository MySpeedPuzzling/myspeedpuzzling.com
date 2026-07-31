<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Entity\UserAccount;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class AccountRecentActivityControllerTest extends WebTestCase
{
    public function testShowsOwnEventsFilteredToVisibleTypes(): void
    {
        $browser = self::createClient();
        $userAccount = $this->seedNativeAccount($browser);

        $connection = $browser->getContainer()->get(Connection::class);
        $this->insertEvent($connection, $userAccount, 'login_success', ipAddress: '203.0.113.77');
        $this->insertEvent($connection, $userAccount, 'password_changed', ipAddress: '203.0.113.78');
        // Not in the visible subset - must not render
        $this->insertEvent($connection, $userAccount, 'sign_in_link_requested', ipAddress: '203.0.113.79');

        // Another account's events must never leak into the page
        $otherAccount = $this->seedNativeAccount($browser);
        $this->insertEvent($connection, $otherAccount, 'login_success', ipAddress: '198.51.100.99');

        $browser->loginUser($userAccount, 'main');
        $crawler = $browser->request('GET', '/en/account/recent-activity');

        self::assertResponseIsSuccessful();

        $pageText = $crawler->text();
        self::assertStringContainsString('203.0.113.77', $pageText);
        self::assertStringContainsString('203.0.113.78', $pageText);
        self::assertStringNotContainsString('203.0.113.79', $pageText, 'Invisible event types must be filtered out');
        self::assertStringNotContainsString('198.51.100.99', $pageText, 'Other accounts events must not leak');
        self::assertStringContainsString('Chrome on Windows', $pageText);
    }

    public function testEmptyStateRenders(): void
    {
        $browser = self::createClient();
        $userAccount = $this->seedNativeAccount($browser);

        $browser->loginUser($userAccount, 'main');
        $browser->request('GET', '/en/account/recent-activity');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('No account activity recorded yet', (string) $browser->getResponse()->getContent());
    }

    public function testLegacyAuth0SessionIsRedirectedToEditProfile(): void
    {
        $browser = self::createClient();
        TestingLogin::asAuth0Player($browser, PlayerFixture::PLAYER_REGULAR);

        $browser->request('GET', '/en/account/recent-activity');

        self::assertResponseRedirects('/en/edit-profile');
    }

    public function testAnonymousVisitorCannotAccessThePage(): void
    {
        $browser = self::createClient();

        $browser->request('GET', '/en/account/recent-activity');

        self::assertResponseStatusCodeSame(302);
    }

    private function seedNativeAccount(KernelBrowser $browser): UserAccount
    {
        $email = sprintf('activity+%s@example.com', bin2hex(random_bytes(4)));
        $userId = 'msp|' . bin2hex(random_bytes(4));

        $userAccount = new UserAccount(Uuid::uuid7(), $userId, $email, new DateTimeImmutable());

        $entityManager = $browser->getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist($userAccount);
        $entityManager->persist(
            new Player(Uuid::uuid7(), 'ACRA' . bin2hex(random_bytes(2)), $userId, $email, null, new DateTimeImmutable()),
        );
        $entityManager->flush();

        return $userAccount;
    }

    private function insertEvent(
        Connection $connection,
        UserAccount $userAccount,
        string $eventType,
        string $ipAddress,
    ): void {
        $connection->insert('auth_audit_log', [
            'id' => Uuid::uuid7()->toString(),
            'user_account_id' => $userAccount->id->toString(),
            'email' => $userAccount->email,
            'event_type' => $eventType,
            'ip_address' => $ipAddress,
            'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36',
            'occurred_at' => new DateTimeImmutable()->format('Y-m-d H:i:sP'),
        ]);
    }
}
