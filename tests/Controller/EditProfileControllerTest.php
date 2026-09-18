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

/**
 * Profile settings: the credential cards (issue #147). The #161 Auth0
 * "send password change email" button is gone with the Auth0 stack (Phase 6).
 */
final class EditProfileControllerTest extends WebTestCase
{
    public function testAccountGetsTheCredentialCards(): void
    {
        $browser = self::createClient();
        $userAccount = $this->seedNativeAccount($browser);
        $browser->loginUser($userAccount, 'main');

        $crawler = $browser->request('GET', '/en/edit-profile');

        self::assertResponseIsSuccessful();

        self::assertCount(1, $crawler->filter('a[href$="/edit-profile/change-password"]'));
        self::assertCount(1, $crawler->filter('a[href$="/edit-profile/change-email"]'));
        self::assertCount(1, $crawler->filter('a[href$="/account/recent-activity"]'));

        // The danger zone names the address the deletion link goes to
        self::assertStringContainsString($userAccount->email, $crawler->filter('#danger-zone')->text());
    }

    /**
     * Fixture players carry auth0|... ids - accounts imported from Auth0. Their
     * settings page must render just the same.
     */
    public function testImportedAuth0AccountGetsTheSameCards(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $crawler = $browser->request('GET', '/en/edit-profile');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('a[href$="/edit-profile/change-email"]'));
        self::assertCount(1, $crawler->filter('a[href$="/account/recent-activity"]'));
    }

    private function seedNativeAccount(KernelBrowser $browser): UserAccount
    {
        $email = sprintf('editprofile+%s@example.com', bin2hex(random_bytes(4)));
        $userId = 'msp|' . bin2hex(random_bytes(4));

        $userAccount = new UserAccount(Uuid::uuid7(), $userId, $email, new DateTimeImmutable());
        $userAccount->changePassword(password_hash('a-properly-long-passphrase', PASSWORD_ARGON2ID));

        $entityManager = $browser->getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist($userAccount);
        $entityManager->persist(
            new Player(Uuid::uuid7(), 'EDPR' . bin2hex(random_bytes(2)), $userId, $email, null, new DateTimeImmutable()),
        );
        $entityManager->flush();

        return $userAccount;
    }
}
