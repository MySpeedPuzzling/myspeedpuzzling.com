<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\UserAccount;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class MyProfileControllerTest extends WebTestCase
{
    public function testAnonymousUserIsRedirectedToLogin(): void
    {
        $browser = self::createClient();
        $browser->request('GET', '/en/my-profile');
        $this->assertResponseRedirects();
    }

    public function testLoggedInUserIsRedirectedToPlayerProfile(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);
        $browser->request('GET', '/en/my-profile');
        $this->assertResponseRedirects('/en/player-profile/' . PlayerFixture::PLAYER_REGULAR);
    }

    /**
     * Production holds a handful of accounts imported from Auth0 that never got a
     * player (registered on Auth0, never came back). Their first sign-in must
     * still land on a profile - RetrieveLoggedUserProfile creates the player.
     */
    public function testAccountWithoutAPlayerGetsOneOnFirstVisit(): void
    {
        $browser = self::createClient();
        $userId = 'auth0|' . bin2hex(random_bytes(6));
        $email = sprintf('noplayer+%s@example.com', bin2hex(random_bytes(4)));

        $userAccount = new UserAccount(Uuid::uuid7(), $userId, $email, new DateTimeImmutable());
        $userAccount->applyAuth0Import($email, null, true, new DateTimeImmutable());

        $entityManager = $browser->getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist($userAccount);
        $entityManager->flush();

        $browser->loginUser($userAccount, 'main');
        $browser->request('GET', '/en/my-profile');

        $player = $browser->getContainer()->get(PlayerRepository::class)->findByUserId($userId);
        self::assertNotNull($player);
        $this->assertResponseRedirects('/en/player-profile/' . $player->id->toString());
    }
}
