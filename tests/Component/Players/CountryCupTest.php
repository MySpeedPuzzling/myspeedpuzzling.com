<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Component\Players;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Component\Players\CountryCup;
use SpeedPuzzling\Web\Query\GetCommunityScopeStats;
use SpeedPuzzling\Web\Services\Community\CountryRankings;
use SpeedPuzzling\Web\Services\ViewerCountry;
use SpeedPuzzling\Web\Value\CountryCupMeasure;
use SpeedPuzzling\Web\Value\CountryCupPeriod;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;

final class CountryCupTest extends KernelTestCase
{
    protected function setUp(): void
    {
        self::bootKernel();
        self::getContainer()->get(Connection::class)->executeStatement('DELETE FROM community_scope_stats');
    }

    public function testInAMonthsFirstSevenDaysTheCupOpensOnLastMonth(): void
    {
        self::assertSame(CountryCupPeriod::LastMonth, $this->cup('2026-10-01 00:05:00')->getDefaultPeriod());
        self::assertSame(CountryCupPeriod::LastMonth, $this->cup('2026-10-07 23:59:59')->getDefaultPeriod());
        self::assertSame(CountryCupPeriod::ThisMonth, $this->cup('2026-10-08 00:00:00')->getDefaultPeriod());
    }

    public function testTheMonthsAreTheMonthsTheNumbersWereComputedIn(): void
    {
        // The cron last ran before midnight on the 1st: "this month" in the numbers is still September
        $this->insertCountry('cz', '2026-09-30 23:45:00');

        $cup = $this->cup('2026-10-01 00:05:00');

        self::assertSame('2026-09-01', $cup->getMonth(CountryCupPeriod::ThisMonth)->format('Y-m-d'));
        self::assertSame('2026-08-01', $cup->getMonth(CountryCupPeriod::LastMonth)->format('Y-m-d'));
    }

    public function testWithoutNumbersTheMonthsFollowTheClock(): void
    {
        $cup = $this->cup('2026-10-03 12:00:00');

        self::assertFalse($cup->hasNumbers());
        self::assertSame('2026-10-01', $cup->getMonth(CountryCupPeriod::ThisMonth)->format('Y-m-d'));
        self::assertSame('2026-09-01', $cup->getMonth(CountryCupPeriod::LastMonth)->format('Y-m-d'));
    }

    public function testEveryMeasureInBothMonths(): void
    {
        $this->insertCountry('cz', '2026-10-03 11:45:00');

        $standings = $this->cup('2026-10-03 12:00:00')->getStandings();

        self::assertCount(4, $standings);
        $boards = array_map(static fn ($board): string => $board->period->value . '/' . $board->measure->value, $standings);
        self::assertSame([
            CountryCupPeriod::LastMonth->value . '/' . CountryCupMeasure::PerActivePuzzler->value,
            CountryCupPeriod::LastMonth->value . '/' . CountryCupMeasure::TotalPieces->value,
            CountryCupPeriod::ThisMonth->value . '/' . CountryCupMeasure::PerActivePuzzler->value,
            CountryCupPeriod::ThisMonth->value . '/' . CountryCupMeasure::TotalPieces->value,
        ], $boards);
    }

    private function cup(string $now): CountryCup
    {
        $container = self::getContainer();
        $query = $container->get(GetCommunityScopeStats::class);
        $query->reset();

        return new CountryCup(
            $query,
            new CountryRankings(),
            $container->get(ViewerCountry::class),
            new MockClock(new DateTimeImmutable($now)),
        );
    }

    private function insertCountry(string $code, string $computedAt): void
    {
        self::getContainer()->get(Connection::class)->insert('community_scope_stats', [
            'scope' => $code,
            'registered_players' => 20,
            'active30d' => 12,
            'solves30d' => 40,
            'solves_prev30d' => 30,
            'active_this_month' => 12,
            'pieces_this_month' => 12_000,
            'active_last_month' => 11,
            'pieces_last_month' => 22_000,
            'median_best500_seconds' => null,
            'puzzlers_with500' => 0,
            'monthly_solves' => '[0,0,0,0,0,0,0,0,0,0,0,0]',
            'new_faces14d' => 0,
            'computed_at' => $computedAt,
        ]);
    }
}
