<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Value;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Value\PlayerResultsCriteria;
use SpeedPuzzling\Web\Value\PlayerResultsSort;
use SpeedPuzzling\Web\Value\PlayerResultsView;

/**
 * The URL of a player's Results is its state (docs/features/navigation PROF-05a): parsing normalizes anything a crafted
 * link can carry, members-only values of a non-member are dropped (no filter, no chip), and toQuery() gives back the
 * canonical URL - the non-defaults, in the grammar's order - which parses to the same state.
 */
final class PlayerResultsCriteriaTest extends TestCase
{
    private const string TEAM = '0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5b';

    public function testDefaults(): void
    {
        $criteria = PlayerResultsCriteria::fromQuery([], isMember: true);

        self::assertNull($criteria->category, 'The default tab is decided by the history (Solo, else the first tab with results)');
        self::assertSame(PlayerResultsView::Puzzles, $criteria->view);
        self::assertSame(PlayerResultsSort::Newest, $criteria->sort, 'D11: Newest is the default');
        self::assertSame(1, $criteria->page);
        self::assertFalse($criteria->hasFilters());
        self::assertSame([], $criteria->toQuery());
        self::assertSame([], $criteria->chips());
    }

    public function testEveryValueRoundTripsInTheGrammarsOrder(): void
    {
        $query = [
            'page' => '3',
            'difficultyTiers' => ['3', '0'],
            'speed_unit' => 'min',
            'speed_cmp' => 'slower',
            'speed' => '12.5',
            'to' => '2026-09-30',
            'from' => '2026-01-01',
            'relax' => '1',
            'unboxed' => '1',
            'first' => '1',
            'team' => strtoupper(self::TEAM),
            'pieces' => '500',
            'brand' => 'Ravensburger',
            'q' => '  Magic garden ',
            'sort' => 'hardest',
            'view' => 'every',
            'category' => 'duo',
        ];

        $criteria = PlayerResultsCriteria::fromQuery($query, isMember: true);

        self::assertSame([
            'category' => 'duo',
            'view' => 'every',
            'sort' => 'hardest',
            'q' => 'Magic garden',
            'brand' => 'Ravensburger',
            'pieces' => '500',
            'team' => self::TEAM,
            'first' => '1',
            'unboxed' => '1',
            'relax' => '1',
            'from' => '2026-01-01',
            'to' => '2026-09-30',
            'speed' => '12.5',
            'speed_cmp' => 'slower',
            'speed_unit' => 'min',
            'difficultyTiers' => ['0', '3'],
            'page' => '3',
        ], $criteria->toQuery());

        self::assertEquals($criteria, PlayerResultsCriteria::fromQuery($criteria->toQuery(), isMember: true));
        self::assertSame(12, $criteria->activeFiltersCount(), 'search, brand, pieces, team, 3 switches, 2 dates, speed, 2 tiers');
    }

    public function testANonMembersMembersOnlyValuesAreDropped(): void
    {
        $criteria = PlayerResultsCriteria::fromQuery([
            'q' => 'garden',
            'brand' => 'Trefl',
            'pieces' => '1000',
            'team' => self::TEAM,
            'first' => '1',
            'unboxed' => '1',
            'relax' => '1',
            'from' => '2026-01-01',
            'to' => '2026-02-01',
            'speed' => '30',
            'speed_cmp' => 'slower',
            'speed_unit' => 'min',
            'difficultyTiers' => ['1', '2'],
            'sort' => 'easiest',
        ], isMember: false);

        self::assertFalse($criteria->onlyFirstTries);
        self::assertFalse($criteria->onlyUnboxed);
        self::assertFalse($criteria->onlyRelax);
        self::assertNull($criteria->dateFrom);
        self::assertNull($criteria->dateTo);
        self::assertNull($criteria->speedValue);
        self::assertSame([], $criteria->difficultyTiers);
        self::assertSame(PlayerResultsSort::Newest, $criteria->sort, 'A difficulty sort falls back to the default');

        // The free filters stay - and are the only chips
        self::assertSame(['q' => 'garden', 'brand' => 'Trefl', 'pieces' => '1000', 'team' => self::TEAM], $criteria->toQuery());
        self::assertSame(['q', 'brand', 'pieces', 'team'], array_column($criteria->chips(), 'id'));
    }

    /**
     * @return iterable<string, array{array<string, mixed>, array<string, string|list<string>>}>
     */
    public static function provideInvalidValues(): iterable
    {
        yield 'unknown tab, view and sort' => [['category' => 'trio', 'view' => 'grid', 'sort' => 'random'], []];
        yield 'not a uuid' => [['team' => 'not-a-team'], []];
        yield 'empty values of a submitted form' => [['q' => '', 'brand' => '', 'pieces' => '', 'pieces_min' => '', 'pieces_max' => '', 'speed' => '', 'from' => '', 'to' => ''], []];
        yield 'impossible dates' => [['from' => '2026-02-31', 'to' => '26.02.2026'], []];
        yield 'dates out of range' => [['from' => '1899-01-01', 'to' => '2999-01-01'], []];
        yield 'swapped dates are put in order' => [['from' => '2026-05-01', 'to' => '2026-01-01'], ['from' => '2026-01-01', 'to' => '2026-05-01']];
        yield 'speed not a number, zero, negative' => [['speed' => 'fast'], []];
        yield 'speed zero' => [['speed' => '0'], []];
        yield 'speed with a decimal comma' => [['speed' => '7,5'], ['speed' => '7.5']];
        yield 'a speed comparison without a speed' => [['speed_cmp' => 'slower', 'speed_unit' => 'min'], []];
        yield 'unknown difficulty values' => [['difficultyTiers' => ['9', 'x', '3', '3', '0', '-1', '12']], ['difficultyTiers' => ['0', '3']]];
        yield 'difficulty not a list' => [['difficultyTiers' => '3'], []];
        yield 'page below 1 and not a number' => [['page' => '0'], []];
        yield 'page not a number' => [['page' => 'two'], []];
        yield 'page 1 is the default' => [['page' => '1'], []];
        yield 'unknown pieces' => [['pieces' => 'lots'], []];
        yield 'legacy pieces bucket' => [['pieces' => '1001+'], ['pieces' => '1001-']];
        yield 'flags other than 1' => [['first' => 'yes', 'relax' => '0'], []];
        yield 'a search longer than 100 characters is cut' => [['q' => str_repeat('a', 150)], ['q' => str_repeat('a', 100)]];
        yield 'arrays where strings belong' => [['q' => ['x'], 'brand' => ['y'], 'sort' => ['fastest']], []];
    }

    /**
     * @param array<string, mixed> $query
     * @param array<string, string|list<string>> $expected
     */
    #[DataProvider('provideInvalidValues')]
    public function testInvalidValuesFallBack(array $query, array $expected): void
    {
        self::assertSame($expected, PlayerResultsCriteria::fromQuery($query, isMember: true)->toQuery());
    }

    public function testThePieceBoundsTheVisitorChangedWin(): void
    {
        // A chip from a link
        self::assertSame('500', PlayerResultsCriteria::fromQuery(['pieces' => '500'], true)->pieces?->toParam());
        // Bounds from a link
        self::assertSame('501-', PlayerResultsCriteria::fromQuery(['pieces_min' => '501'], true)->pieces?->toParam());
        // The form: "All" checked, bounds typed
        self::assertSame('200-400', PlayerResultsCriteria::fromQuery(['pieces' => '', 'pieces_min' => '200', 'pieces_max' => '400', 'pieces_was' => ''], true)->pieces?->toParam());
        // The form: a chip was applied, bounds typed now
        self::assertSame('200-400', PlayerResultsCriteria::fromQuery(['pieces' => '500', 'pieces_min' => '200', 'pieces_max' => '400', 'pieces_was' => '500'], true)->pieces?->toParam());
        // The form: custom bounds were applied (shown back), a chip picked now
        self::assertSame('500', PlayerResultsCriteria::fromQuery(['pieces' => '500', 'pieces_min' => '300', 'pieces_max' => '600', 'pieces_was' => '300-600'], true)->pieces?->toParam());
        // The form: custom bounds applied, "All" picked now
        self::assertNull(PlayerResultsCriteria::fromQuery(['pieces' => '', 'pieces_min' => '300', 'pieces_max' => '600', 'pieces_was' => '300-600'], true)->pieces);
        // The form: custom bounds applied and left alone (no chip checked), another filter changed
        self::assertSame('300-600', PlayerResultsCriteria::fromQuery(['pieces_min' => '300', 'pieces_max' => '600', 'pieces_was' => '300-600', 'brand' => 'Trefl'], true)->pieces?->toParam());
        // Swapped bounds are put in order
        self::assertSame('300-600', PlayerResultsCriteria::fromQuery(['pieces_min' => '600', 'pieces_max' => '300'], true)->pieces?->toParam());
    }

    public function testAnotherListStartsFromItsFirstPage(): void
    {
        $criteria = PlayerResultsCriteria::fromQuery(['q' => 'garden', 'brand' => 'Trefl', 'first' => '1', 'difficultyTiers' => ['2', '4'], 'page' => '3', 'sort' => 'fastest'], true);

        self::assertSame(3, $criteria->page);
        self::assertSame(1, $criteria->withSort(PlayerResultsSort::Name)->page);
        self::assertSame(1, $criteria->withView(PlayerResultsView::Every)->page);
        self::assertSame(1, $criteria->withCategory('duo')->page);
        self::assertSame(1, $criteria->without('brand')->page);
        self::assertSame(1, $criteria->withoutFilters()->page);
        // Paging keeps everything else
        self::assertSame(['sort' => 'fastest', 'q' => 'garden', 'brand' => 'Trefl', 'first' => '1', 'difficultyTiers' => ['2', '4'], 'page' => '4'], $criteria->withPage(4)->toQuery());
    }

    public function testAChipIsRemovedAloneAndClearAllKeepsTheList(): void
    {
        $criteria = PlayerResultsCriteria::fromQuery(['category' => 'group', 'view' => 'every', 'sort' => 'oldest', 'q' => 'garden', 'brand' => 'Trefl', 'speed' => '20', 'speed_cmp' => 'slower', 'difficultyTiers' => ['2', '4']], true);

        self::assertSame(['q', 'brand', 'speed', 'difficultyTiers:2', 'difficultyTiers:4'], array_column($criteria->chips(), 'id'));

        $withoutTier = $criteria->without('difficultyTiers:2');
        self::assertSame(['4'], $withoutTier->difficultyTiers);
        self::assertSame('Trefl', $withoutTier->brand);

        // The speed chip takes its comparison and unit with it
        self::assertSame(['category' => 'group', 'view' => 'every', 'sort' => 'oldest', 'q' => 'garden', 'brand' => 'Trefl', 'difficultyTiers' => ['2', '4']], $criteria->without('speed')->toQuery());

        self::assertSame(['category' => 'group', 'view' => 'every', 'sort' => 'oldest'], $criteria->withoutFilters()->toQuery());
        self::assertSame(['view' => 'every', 'sort' => 'oldest', 'q' => 'garden', 'brand' => 'Trefl', 'speed' => '20', 'speed_cmp' => 'slower', 'difficultyTiers' => ['2', '4']], $criteria->withCategory(null)->toQuery());
    }

    public function testBestRankAndAttemptsOrderOnlyTheRowsTheyMeanSomethingFor(): void
    {
        self::assertTrue(PlayerResultsSort::Rank->appliesTo('solo', PlayerResultsView::Puzzles));
        self::assertFalse(PlayerResultsSort::Rank->appliesTo('duo', PlayerResultsView::Puzzles));
        self::assertFalse(PlayerResultsSort::Rank->appliesTo('solo', PlayerResultsView::Every));
        self::assertTrue(PlayerResultsSort::Attempts->appliesTo('group', PlayerResultsView::Puzzles));
        self::assertFalse(PlayerResultsSort::Attempts->appliesTo('solo', PlayerResultsView::Every));
        self::assertTrue(PlayerResultsSort::Name->appliesTo('solo', PlayerResultsView::Every));
    }
}
