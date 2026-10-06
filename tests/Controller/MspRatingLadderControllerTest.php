<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\PlayerElo;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class MspRatingLadderControllerTest extends WebTestCase
{
    public function testAnonymousUserCanAccessPage(): void
    {
        $browser = self::createClient();

        $browser->request('GET', '/en/msp-rating');

        $this->assertResponseIsSuccessful();
    }

    public function testLoggedInUserCanAccessPage(): void
    {
        $browser = self::createClient();

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $browser->request('GET', '/en/msp-rating');

        $this->assertResponseIsSuccessful();
    }

    public function testOldUrlRedirects(): void
    {
        $browser = self::createClient();

        $browser->request('GET', '/en/msp-elo');

        $this->assertResponseRedirects('/en/msp-rating', 301);
    }

    public function testLadderCardAndProfileCardShowTheSameRankAndTotal(): void
    {
        // Reported 2026-10-06: #300 of 1126 on the ladder, #303 of 1136 on the profile at the same moment - the
        // profile card still counted the players who opted out of rankings
        $browser = self::createClient();
        $container = self::getContainer();

        /** @var PlayerRepository $players */
        $players = $container->get(PlayerRepository::class);
        /** @var EntityManagerInterface $em */
        $em = $container->get(EntityManagerInterface::class);

        $ratings = [
            PlayerFixture::PLAYER_ADMIN => 1500.0,
            PlayerFixture::PLAYER_WITH_FAVORITES => 1450.0,
            PlayerFixture::PLAYER_REGULAR => 1400.0,
            PlayerFixture::PLAYER_WITH_STRIPE => 1200.0,
        ];

        foreach ($ratings as $playerId => $rating) {
            $em->persist(new PlayerElo(id: Uuid::uuid7(), player: $players->get($playerId), piecesCount: 500, eloRating: $rating));
        }

        $players->get(PlayerFixture::PLAYER_ADMIN)->changeRankingOptedOut(true);
        $em->flush();

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $ladder = $browser->request('GET', '/en/msp-rating');
        $this->assertResponseIsSuccessful();
        $ladderCard = $ladder->filter('a[href*="#my-rating-row"]')->text();

        $profile = $browser->request('GET', '/en/player-profile/' . PlayerFixture::PLAYER_REGULAR);
        $this->assertResponseIsSuccessful();
        $profileCard = $profile->filter('[data-live-name-value="PlayerRatingProfile"]')->text();

        foreach ([$ladderCard, $profileCard] as $card) {
            self::assertStringContainsString('Ranked among 3 players', $card);
            self::assertStringContainsString('#2', $card);
        }
    }
}
