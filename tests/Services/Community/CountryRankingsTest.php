<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services\Community;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Results\CommunityScopeStatistics;
use SpeedPuzzling\Web\Results\CountryCupRow;
use SpeedPuzzling\Web\Services\Community\CountryRankings;
use SpeedPuzzling\Web\Value\CommunityScope;
use SpeedPuzzling\Web\Value\CountryCode;
use SpeedPuzzling\Web\Value\CountryCupMeasure;
use SpeedPuzzling\Web\Value\CountryCupPeriod;

final class CountryRankingsTest extends TestCase
{
    private CountryRankings $rankings;

    protected function setUp(): void
    {
        $this->rankings = new CountryRankings();
    }

    public function testMostActiveOrdersByActivePuzzlersThenTheBiggerCommunity(): void
    {
        $countries = [
            self::country('cz', registered: 100, active30d: 20),
            self::country('de', registered: 300, active30d: 40),
            self::country('gb', registered: 200, active30d: 20),
        ];

        self::assertSame(['de', 'gb', 'cz'], self::codes($this->rankings->mostActive($countries)));
    }

    public function testMostPuzzlersOrdersByRegisteredPlayers(): void
    {
        $countries = [
            self::country('cz', registered: 100, active30d: 90),
            self::country('de', registered: 300, active30d: 10),
            self::country('gb', registered: 300, active30d: 20),
        ];

        self::assertSame(['gb', 'de', 'cz'], self::codes($this->rankings->mostPuzzlers($countries)));
    }

    public function testThereAreTwelveTiles(): void
    {
        $countries = [];
        foreach (['cz', 'de', 'gb', 'us', 'fr', 'es', 'it', 'pl', 'sk', 'at', 'nl', 'be', 'dk', 'se', 'no'] as $index => $code) {
            $countries[] = self::country($code, registered: 100 + $index, active30d: $index, solves30d: 20 + $index, solvesPrev30d: 20);
        }

        self::assertCount(CountryRankings::TILES, $this->rankings->mostActive($countries));
        self::assertCount(CountryRankings::TILES, $this->rankings->mostPuzzlers($countries));
        self::assertCount(CountryRankings::TILES, $this->rankings->rising($countries));
        self::assertSame('no', self::codes($this->rankings->mostActive($countries))[0]);
    }

    public function testRisingNeedsTenSolvesInTheThirtyDaysBefore(): void
    {
        $countries = [
            // +1011 %, but from 9 solves - not a rising community
            self::country('cz', solves30d: 100, solvesPrev30d: 9),
            // +100 %
            self::country('de', solves30d: 20, solvesPrev30d: 10),
            // -20 %
            self::country('gb', solves30d: 40, solvesPrev30d: 50),
            // +100 % as well, more solves - before Germany
            self::country('us', solves30d: 200, solvesPrev30d: 100),
        ];

        $rising = $this->rankings->rising($countries);

        self::assertSame(['us', 'de', 'gb'], self::codes($rising));
        self::assertSame(100, $rising[0]->solvesChangePercent());
        self::assertSame(-20, $rising[2]->solvesChangePercent());
    }

    public function testTheCupPerActivePuzzlerRanksOnlyCountriesWithTenActivePuzzlers(): void
    {
        $countries = [
            // 10,000 pieces per puzzler - but only 9 active
            self::country('cz', activeThisMonth: 9, piecesThisMonth: 90_000),
            self::country('de', activeThisMonth: 10, piecesThisMonth: 10_000),
            self::country('gb', activeThisMonth: 20, piecesThisMonth: 30_000),
        ];

        $standings = $this->rankings->cup($countries, CountryCupMeasure::PerActivePuzzler, CountryCupPeriod::ThisMonth, null, null);

        self::assertSame(['gb', 'de'], self::rowCodes($standings->top));
        self::assertSame([1, 2], array_map(static fn (CountryCupRow $row): null|int => $row->position, $standings->top));
        self::assertSame([1500, 1000], array_map(static fn (CountryCupRow $row): null|int => $row->value, $standings->top));
        self::assertSame(20, $standings->top[0]->active);
        self::assertSame([], $standings->marked);
    }

    public function testTheCupInTotalPiecesRanksEveryCountryWithPieces(): void
    {
        $countries = [
            self::country('cz', activeThisMonth: 9, piecesThisMonth: 90_000),
            self::country('de', activeThisMonth: 10, piecesThisMonth: 10_000),
            self::country('gb', activeThisMonth: 20, piecesThisMonth: 30_000),
            self::country('us', activeThisMonth: 0, piecesThisMonth: 0),
        ];

        $standings = $this->rankings->cup($countries, CountryCupMeasure::TotalPieces, CountryCupPeriod::ThisMonth, null, null);

        self::assertSame(['cz', 'gb', 'de'], self::rowCodes($standings->top));
        self::assertSame(90_000, $standings->top[0]->value);
    }

    public function testLastMonthReadsLastMonthsNumbers(): void
    {
        $countries = [
            self::country('cz', activeThisMonth: 50, piecesThisMonth: 50_000, activeLastMonth: 10, piecesLastMonth: 30_000),
            self::country('de', activeThisMonth: 10, piecesThisMonth: 1_000, activeLastMonth: 20, piecesLastMonth: 40_000),
        ];

        $perPuzzler = $this->rankings->cup($countries, CountryCupMeasure::PerActivePuzzler, CountryCupPeriod::LastMonth, null, null);
        self::assertSame(['cz', 'de'], self::rowCodes($perPuzzler->top));
        self::assertSame([3000, 2000], array_map(static fn (CountryCupRow $row): null|int => $row->value, $perPuzzler->top));

        $total = $this->rankings->cup($countries, CountryCupMeasure::TotalPieces, CountryCupPeriod::LastMonth, null, null);
        self::assertSame(['de', 'cz'], self::rowCodes($total->top));
        self::assertSame(CountryCupPeriod::LastMonth, $total->period);
        self::assertSame(CountryCupMeasure::TotalPieces, $total->measure);
    }

    public function testTheBarsAreToTheLeadersScale(): void
    {
        $countries = [
            self::country('cz', activeThisMonth: 10, piecesThisMonth: 30_000),
            self::country('de', activeThisMonth: 10, piecesThisMonth: 20_000),
        ];

        $standings = $this->rankings->cup($countries, CountryCupMeasure::TotalPieces, CountryCupPeriod::ThisMonth, null, null);

        self::assertSame(100.0, $standings->top[0]->barPercent);
        self::assertSame(66.7, $standings->top[1]->barPercent);
    }

    public function testTheViewersCountryOutsideTheTopTenIsAddedWithItsRealPosition(): void
    {
        $standings = $this->rankings->cup(self::twelveCupCountries(), CountryCupMeasure::PerActivePuzzler, CountryCupPeriod::ThisMonth, CountryCode::cz, null);

        self::assertCount(CountryRankings::CUP_ROWS, $standings->top);
        self::assertNotContains('cz', self::rowCodes($standings->top));
        self::assertCount(1, $standings->marked);
        self::assertSame(CountryCode::cz, $standings->marked[0]->country);
        self::assertSame(12, $standings->marked[0]->position);
        self::assertTrue($standings->marked[0]->isViewerCountry);
        self::assertGreaterThan(0.0, $standings->marked[0]->barPercent);
    }

    public function testTheViewersCountryInTheTopTenIsHighlightedThere(): void
    {
        $standings = $this->rankings->cup(self::twelveCupCountries(), CountryCupMeasure::PerActivePuzzler, CountryCupPeriod::ThisMonth, CountryCode::de, null);

        $viewerRows = array_values(array_filter($standings->top, static fn (CountryCupRow $row): bool => $row->isViewerCountry));
        self::assertCount(1, $viewerRows);
        self::assertSame(CountryCode::de, $viewerRows[0]->country);
        self::assertSame([], $standings->marked);
    }

    public function testTheViewersCountryBelowTheMinimumIsAddedWithoutAPosition(): void
    {
        $countries = self::twelveCupCountries();
        $countries[] = self::country('sk', activeThisMonth: 3, piecesThisMonth: 9_000);

        $standings = $this->rankings->cup($countries, CountryCupMeasure::PerActivePuzzler, CountryCupPeriod::ThisMonth, CountryCode::sk, null);

        self::assertCount(1, $standings->marked);
        self::assertSame(CountryCode::sk, $standings->marked[0]->country);
        self::assertNull($standings->marked[0]->position);
        self::assertNull($standings->marked[0]->value);
        self::assertSame(3, $standings->marked[0]->active);
        self::assertSame(0.0, $standings->marked[0]->barPercent);
    }

    public function testTheScopesCountryIsAddedTooInOrderOfPosition(): void
    {
        $standings = $this->rankings->cup(self::twelveCupCountries(), CountryCupMeasure::PerActivePuzzler, CountryCupPeriod::ThisMonth, CountryCode::cz, CountryCode::gb);

        self::assertSame(['gb', 'cz'], self::rowCodes($standings->marked));
        self::assertSame([11, 12], array_map(static fn (CountryCupRow $row): null|int => $row->position, $standings->marked));
        self::assertTrue($standings->marked[0]->isScopeCountry);
        self::assertFalse($standings->marked[0]->isViewerCountry);
    }

    public function testTheSameCountryForViewerAndScopeIsAddedOnce(): void
    {
        $standings = $this->rankings->cup(self::twelveCupCountries(), CountryCupMeasure::PerActivePuzzler, CountryCupPeriod::ThisMonth, CountryCode::cz, CountryCode::cz);

        self::assertCount(1, $standings->marked);
        self::assertTrue($standings->marked[0]->isViewerCountry);
        self::assertTrue($standings->marked[0]->isScopeCountry);
    }

    public function testACountryWithoutNumbersIsNotAdded(): void
    {
        $standings = $this->rankings->cup(self::twelveCupCountries(), CountryCupMeasure::PerActivePuzzler, CountryCupPeriod::ThisMonth, CountryCode::fj, CountryCode::fj);

        self::assertSame([], $standings->marked);
    }

    /**
     * Twelve countries with at least ten active puzzlers, per puzzler descending in this order: Germany first,
     * Czechia last (12th), Great Britain 11th.
     *
     * @return list<CommunityScopeStatistics>
     */
    private static function twelveCupCountries(): array
    {
        $countries = [];
        foreach (['de', 'us', 'fr', 'es', 'it', 'pl', 'at', 'nl', 'be', 'dk', 'gb', 'cz'] as $index => $code) {
            $countries[] = self::country($code, activeThisMonth: 10, piecesThisMonth: (20 - $index) * 1000);
        }

        return $countries;
    }

    private static function country(
        string $code,
        int $registered = 50,
        int $active30d = 10,
        int $solves30d = 0,
        int $solvesPrev30d = 0,
        int $activeThisMonth = 0,
        int $piecesThisMonth = 0,
        int $activeLastMonth = 0,
        int $piecesLastMonth = 0,
    ): CommunityScopeStatistics {
        return new CommunityScopeStatistics(
            scope: CommunityScope::fromQuery($code),
            registeredPlayers: $registered,
            active30d: $active30d,
            solves30d: $solves30d,
            solvesPrev30d: $solvesPrev30d,
            activeThisMonth: $activeThisMonth,
            piecesThisMonth: $piecesThisMonth,
            activeLastMonth: $activeLastMonth,
            piecesLastMonth: $piecesLastMonth,
            medianBest500Seconds: null,
            puzzlersWith500: 0,
            monthlySolves: array_fill(0, 12, 0),
            newFaces14d: 0,
            computedAt: new DateTimeImmutable('2026-10-03 12:00:00'),
        );
    }

    /**
     * @param list<CommunityScopeStatistics> $countries
     * @return list<string>
     */
    private static function codes(array $countries): array
    {
        return array_map(static fn (CommunityScopeStatistics $statistics): string => $statistics->scope->key(), $countries);
    }

    /**
     * @param list<CountryCupRow> $rows
     * @return list<string>
     */
    private static function rowCodes(array $rows): array
    {
        return array_map(static fn (CountryCupRow $row): string => $row->country->name, $rows);
    }
}
