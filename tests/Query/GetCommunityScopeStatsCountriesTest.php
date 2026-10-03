<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Query;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Message\RecalculateCommunityStats;
use SpeedPuzzling\Web\MessageHandler\RecalculateCommunityStatsHandler;
use SpeedPuzzling\Web\Query\GetCommunityScopeStats;
use SpeedPuzzling\Web\Results\CommunityScopeStatistics;
use SpeedPuzzling\Web\Value\CountryCode;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * countries() feeds the scope switch, the country tiles and the Country Cup - one statement per request, so it is
 * remembered until the request ends (FrankenPHP worker mode: reset between requests).
 */
final class GetCommunityScopeStatsCountriesTest extends KernelTestCase
{
    public function testTheCountriesAreReadOnceUntilTheRequestEnds(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        ($container->get(RecalculateCommunityStatsHandler::class))(new RecalculateCommunityStats());

        $query = $container->get(GetCommunityScopeStats::class);

        $countries = $query->countries();
        self::assertNotContains(CountryCode::fj, self::codes($countries));

        $container->get(Connection::class)->executeStatement(
            "INSERT INTO community_scope_stats (scope, registered_players, active30d, solves30d, solves_prev30d, active_this_month, pieces_this_month, active_last_month, pieces_last_month, median_best500_seconds, puzzlers_with500, monthly_solves, new_faces14d, computed_at)
            VALUES ('fj', 1, 0, 0, 0, 0, 0, 0, 0, NULL, 0, '[0,0,0,0,0,0,0,0,0,0,0,0]', 0, NOW())",
        );

        self::assertSame($countries, $query->countries());

        $query->reset();

        self::assertContains(CountryCode::fj, self::codes($query->countries()));
    }

    /**
     * @param list<CommunityScopeStatistics> $countries
     * @return list<null|CountryCode>
     */
    private static function codes(array $countries): array
    {
        return array_map(static fn (CommunityScopeStatistics $statistics): null|CountryCode => $statistics->country(), $countries);
    }
}
