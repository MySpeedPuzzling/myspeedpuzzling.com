<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use SpeedPuzzling\Web\Tests\TestClock;
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

    public function testThePositionLineAndTheProfileCardShowTheSameRankAndTotal(): void
    {
        // Reported 2026-10-06: #300 of 1126 on the ladder, #303 of 1136 on the profile at the same moment - the
        // profile card still counted the players who opted out of rankings. The ladder's card became the position line
        // of the board (design-spec §4), on the unfiltered list it must still say what the profile card says
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
            $em->persist(new PlayerElo(id: Uuid::uuid7(), player: $players->get($playerId), piecesCount: 500, computedAt: TestClock::now(), eloRating: $rating));
        }

        $players->get(PlayerFixture::PLAYER_ADMIN)->changeRankingOptedOut(true);
        $em->flush();

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $ladder = $browser->request('GET', '/en/msp-rating');
        $this->assertResponseIsSuccessful();
        $positionLine = $ladder->filter('[data-testid="lb-position"]')->text();

        $profile = $browser->request('GET', '/en/player-profile/' . PlayerFixture::PLAYER_REGULAR);
        $this->assertResponseIsSuccessful();
        $profileCard = $profile->filter('[data-live-name-value="PlayerRatingProfile"]')->text();

        self::assertStringContainsString('Ranked among 3 players', $profileCard);
        self::assertStringContainsString('#2', $profileCard);
        self::assertStringContainsString('#2', $positionLine);
        self::assertStringContainsString('3', $positionLine);
        self::assertSame('2', trim($ladder->filter('#my-rating-row .lb-rank')->text()));
    }

    public function testTheBoardHasNoPageNumbersAndAnOldPageLinkLandsOnTheWindow(): void
    {
        $browser = self::createClient();

        $crawler = $browser->request('GET', '/en/msp-rating?page=13');

        $this->assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('.pagination'));
    }

    public function testAGuestIsAskedToSignInToSeeWhereTheyStand(): void
    {
        $browser = self::createClient();

        $crawler = $browser->request('GET', '/en/msp-rating');

        self::assertStringContainsString('See where you stand on this board', $crawler->filter('[data-testid="lb-not-on-board"]')->text());
        self::assertStringStartsWith('/login', (string) $crawler->filter('[data-testid="lb-not-on-board"] a')->attr('href'));
    }

    public function testAPrivateProfileIsToldWhyItIsNotListed(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_PRIVATE);

        $crawler = $browser->request('GET', '/en/msp-rating');

        self::assertStringContainsString('Your profile is private', $crawler->filter('[data-testid="lb-not-on-board"]')->text());
    }
}
