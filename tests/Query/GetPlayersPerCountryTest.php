<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Query;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Query\GetPlayersPerCountry;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestingViewer;
use SpeedPuzzling\Web\Value\CountryCode;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The people of a country page are listed by GetPlayersDirectory (GetPlayersDirectoryTest); this covers what decides
 * whether the page is for the index and in the sitemap. PLAYER_PRIVATE is the only player from "us".
 */
final class GetPlayersPerCountryTest extends KernelTestCase
{
    private GetPlayersPerCountry $query;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->query = self::getContainer()->get(GetPlayersPerCountry::class);
    }

    public function testCountriesWithPublicPlayersLeaveOutCountriesOfPrivatePlayersOnly(): void
    {
        $database = self::getContainer()->get(Connection::class);
        // PLAYER_PRIVATE is from "us" - make sure nobody public is
        $database->executeStatement("UPDATE player SET is_private = true WHERE country = 'us'");

        $countries = $this->query->countriesWithPublicPlayers();
        self::assertContains(CountryCode::cz, $countries);
        self::assertNotContains(CountryCode::us, $countries);

        $database->executeStatement(
            'UPDATE player SET is_private = false WHERE id = :id',
            ['id' => PlayerFixture::PLAYER_PRIVATE],
        );

        self::assertContains(CountryCode::us, $this->query->countriesWithPublicPlayers());
    }

    public function testHasPublicPlayersFollowsTheSameRuleForEveryViewer(): void
    {
        self::assertTrue($this->query->hasPublicPlayers(CountryCode::cz));
        self::assertFalse($this->query->hasPublicPlayers(CountryCode::us));
        self::assertFalse($this->query->hasPublicPlayers(CountryCode::aq));

        self::getContainer()->get(Connection::class)->executeStatement(
            'UPDATE player SET is_private = false WHERE id = :id',
            ['id' => PlayerFixture::PLAYER_PRIVATE],
        );

        self::assertTrue($this->query->hasPublicPlayers(CountryCode::us));

        // PLAYER_REGULAR blocks PLAYER_PRIVATE - robots must not depend on who looks
        TestingViewer::signIn(self::getContainer(), PlayerFixture::PLAYER_REGULAR);
        self::assertTrue($this->query->hasPublicPlayers(CountryCode::us));
    }
}
