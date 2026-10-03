<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Query;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Message\RecalculateCommunityStats;
use SpeedPuzzling\Web\MessageHandler\RecalculateCommunityStatsHandler;
use SpeedPuzzling\Web\Query\GetCommunityScopeStats;
use SpeedPuzzling\Web\Value\CommunityScope;
use SpeedPuzzling\Web\Value\CountryCode;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class GetCommunityScopeStatsTest extends KernelTestCase
{
    private GetCommunityScopeStats $query;
    private Connection $database;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->query = self::getContainer()->get(GetCommunityScopeStats::class);
        $this->database = self::getContainer()->get(Connection::class);
        (self::getContainer()->get(RecalculateCommunityStatsHandler::class))(new RecalculateCommunityStats());
    }

    public function testTheWorldAndACountry(): void
    {
        $world = $this->query->forScope(CommunityScope::world());
        self::assertTrue($world->scope->isWorld());
        self::assertSame(self::int($this->database->fetchOne('SELECT COUNT(*) FROM player')), $world->registeredPlayers);
        self::assertCount(12, $world->monthlySolves);
        self::assertNotNull($world->computedAt);

        $czechia = $this->query->forScope(CommunityScope::country(CountryCode::cz));
        self::assertSame(CountryCode::cz, $czechia->country());
        self::assertSame(self::int($this->database->fetchOne("SELECT COUNT(*) FROM player WHERE country = 'cz'")), $czechia->registeredPlayers);
    }

    public function testACountryWithoutPlayersIsEmptyNotAnError(): void
    {
        $fiji = $this->query->forScope(CommunityScope::country(CountryCode::fj));

        self::assertSame(0, $fiji->registeredPlayers);
        self::assertNull($fiji->computedAt);
        self::assertNull($fiji->solvesChangePercent());
        self::assertNull($fiji->piecesPerActivePuzzlerThisMonth());
    }

    public function testCountriesAreTheKnownCountriesWithPlayers(): void
    {
        $codes = array_map(static fn ($statistics): null|CountryCode => $statistics->country(), $this->query->countries());

        self::assertContains(CountryCode::cz, $codes);
        self::assertNotContains(null, $codes);
    }

    private static function int(mixed $value): int
    {
        self::assertIsNumeric($value);

        return (int) $value;
    }
}
