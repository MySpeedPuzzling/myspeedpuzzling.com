<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Query;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Message\RecalculateCommunityStats;
use SpeedPuzzling\Web\MessageHandler\RecalculateCommunityStatsHandler;
use SpeedPuzzling\Web\Query\GetCommunityScopeStats;
use SpeedPuzzling\Web\Results\CommunityScopeStatistics;
use SpeedPuzzling\Web\Value\CommunityScope;
use SpeedPuzzling\Web\Value\CountryCode;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * GetCommunityScopeStats::forScopeWithWorld() - the spotlight's numbers: the scope and the world in one statement.
 */
final class GetCommunityScopeStatsWithWorldTest extends KernelTestCase
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

    public function testTheWorldIsBothRows(): void
    {
        $numbers = $this->query->forScopeWithWorld(CommunityScope::world());

        self::assertTrue($numbers['scope']->scope->isWorld());
        self::assertTrue($numbers['world']->scope->isWorld());
        self::assertSame($numbers['world']->registeredPlayers, $numbers['scope']->registeredPlayers);
        self::assertSame(self::int($this->database->fetchOne('SELECT COUNT(*) FROM player')), $numbers['world']->registeredPlayers);
    }

    public function testACountryComesWithTheWorld(): void
    {
        $numbers = $this->query->forScopeWithWorld(CommunityScope::country(CountryCode::cz));

        self::assertSame(CountryCode::cz, $numbers['scope']->country());
        self::assertSame(self::int($this->database->fetchOne("SELECT COUNT(*) FROM player WHERE country = 'cz'")), $numbers['scope']->registeredPlayers);
        self::assertTrue($numbers['world']->scope->isWorld());
        self::assertSame(self::int($this->database->fetchOne('SELECT COUNT(*) FROM player')), $numbers['world']->registeredPlayers);
    }

    public function testACountryWithoutPlayersIsEmptyNextToTheRealWorld(): void
    {
        $numbers = $this->query->forScopeWithWorld(CommunityScope::country(CountryCode::fj));

        self::assertSame(CountryCode::fj, $numbers['scope']->country());
        self::assertSame(0, $numbers['scope']->registeredPlayers);
        self::assertNull($numbers['scope']->computedAt);
        self::assertGreaterThan(0, $numbers['world']->registeredPlayers);
    }

    public function testASmallCountryAndTheMedianAgainstTheWorld(): void
    {
        $world = $this->statistics(CommunityScope::world(), active30d: 900, medianBest500: 3600);
        $small = $this->statistics(CommunityScope::country(CountryCode::cz), active30d: 14, medianBest500: 3300);
        $big = $this->statistics(CommunityScope::country(CountryCode::cz), active30d: CommunityScopeStatistics::SMALL_COMMUNITY_ACTIVE, medianBest500: 3900);
        $noFiveHundreds = $this->statistics(CommunityScope::country(CountryCode::cz), active30d: 3, medianBest500: null);

        self::assertFalse($world->isSmallCommunity());
        self::assertTrue($small->isSmallCommunity());
        self::assertFalse($big->isSmallCommunity());

        self::assertSame(300, $small->medianBest500FasterThan($world));
        self::assertSame(-300, $big->medianBest500FasterThan($world));
        self::assertNull($noFiveHundreds->medianBest500FasterThan($world));
    }

    private function statistics(CommunityScope $scope, int $active30d, null|int $medianBest500): CommunityScopeStatistics
    {
        return new CommunityScopeStatistics($scope, 100, $active30d, 0, 0, 0, 0, 0, 0, $medianBest500, 0, array_fill(0, 12, 0), 0, null);
    }

    private static function int(mixed $value): int
    {
        self::assertIsNumeric($value);

        return (int) $value;
    }
}
