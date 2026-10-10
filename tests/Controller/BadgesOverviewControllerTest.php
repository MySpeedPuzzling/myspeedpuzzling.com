<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The achievements catalogue is public since the launch (no feature flag) - guests, free players and members alike.
 */
final class BadgesOverviewControllerTest extends WebTestCase
{
    public function testAnonymousVisitorSeesTheCatalogue(): void
    {
        $browser = self::createClient();

        $browser->request('GET', '/en/achievements');

        self::assertResponseIsSuccessful();
    }

    public function testFreePlayerSeesTheCatalogue(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $browser->request('GET', '/en/achievements');

        self::assertResponseIsSuccessful();
    }

    public function testMemberSeesTheCatalogue(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);

        $browser->request('GET', '/en/achievements');

        self::assertResponseIsSuccessful();
    }

    public function testAMembersProfileShowsTheirAchievementsShowcase(): void
    {
        $browser = self::createClient();
        self::getContainer()->get(Connection::class)->executeStatement(
            "INSERT INTO badge (id, player_id, type, earned_at, tier, revealed_at) VALUES (:id, :player, 'streak', NOW(), 1, NOW())",
            ['id' => Uuid::uuid7()->toString(), 'player' => PlayerFixture::PLAYER_WITH_STRIPE],
        );
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);

        $crawler = $browser->request('GET', '/en/player-profile/' . PlayerFixture::PLAYER_WITH_STRIPE);

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('.achievements-showcase .achievements-showcase-item'));
        self::assertCount(1, $crawler->filter('.achievements-showcase a.achievements-showcase-summary[href="/en/player-achievements/' . PlayerFixture::PLAYER_WITH_STRIPE . '"]'));
    }
}
