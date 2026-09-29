<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class PlayersPerCountryControllerTest extends WebTestCase
{
    public function testAnonymousUserCanAccessPage(): void
    {
        $browser = self::createClient();

        $browser->request('GET', '/en/players-from-country/cz');

        $this->assertResponseIsSuccessful();
    }

    public function testLoggedInUserCanAccessPage(): void
    {
        $browser = self::createClient();

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $browser->request('GET', '/en/players-from-country/cz');

        $this->assertResponseIsSuccessful();
    }

    public function testCountryWithPlayersIsIndexable(): void
    {
        $browser = self::createClient();

        $crawler = $browser->request('GET', '/en/players-from-country/cz');

        $this->assertResponseIsSuccessful();
        self::assertSame('index, follow', $crawler->filter('meta[name="robots"]')->attr('content'));
    }

    public function testCountryWithoutPublicPlayersIsNoindexButStillAPage(): void
    {
        $browser = self::createClient();
        // PLAYER_PRIVATE is from "us" - make sure nobody public is; "aq" has nobody at all
        self::getContainer()->get(Connection::class)->executeStatement("UPDATE player SET is_private = true WHERE country = 'us'");

        foreach (['/en/players-from-country/us', '/en/players-from-country/aq'] as $path) {
            $crawler = $browser->request('GET', $path);

            $this->assertResponseIsSuccessful();
            self::assertSame('noindex, follow', $crawler->filter('meta[name="robots"]')->attr('content'), $path);
            self::assertStringContainsString('has a public profile yet', $crawler->filter('body')->text(), $path);
        }
    }
}
