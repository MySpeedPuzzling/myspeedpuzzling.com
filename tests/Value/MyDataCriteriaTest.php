<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Value;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Entity\Collection;
use SpeedPuzzling\Web\Value\MyDataColumns;
use SpeedPuzzling\Web\Value\MyDataCriteria;
use SpeedPuzzling\Web\Value\MyDataSort;
use SpeedPuzzling\Web\Value\MyDataTab;

/**
 * The address of "My data" is its state (docs/features/my-data.md "The address"): whatever a link carries becomes a
 * valid state, and a state writes one canonical address.
 */
final class MyDataCriteriaTest extends TestCase
{
    public function testNothingInTheAddressIsTheDefaultState(): void
    {
        $criteria = MyDataCriteria::fromUserInput([]);

        self::assertSame(MyDataTab::Results, $criteria->tab);
        self::assertSame(MyDataSort::Date, $criteria->sort);
        self::assertTrue($criteria->descending);
        self::assertSame(1, $criteria->page);
        self::assertSame(0, $criteria->offset());
        self::assertFalse($criteria->hasFilters());
        self::assertSame([], $criteria->query());
    }

    /**
     * @return iterable<string, array{array<mixed>, array<string, string>}>
     */
    public static function provideAddresses(): iterable
    {
        yield 'everything of the results' => [
            ['tab' => 'results', 'q' => "  cats \n and\tdogs ", 'sort' => 'time', 'dir' => 'desc', 'pieces' => '500-1000', 'type' => 'duo', 'year' => '2024', 'first' => '1', 'imported' => '1', 'comment' => '1', 'photo' => '1', 'page' => '3'],
            ['q' => 'cats and dogs', 'sort' => 'time', 'dir' => 'desc', 'pieces' => '500-1000', 'type' => 'duo', 'year' => '2024', 'first' => '1', 'imported' => '1', 'comment' => '1', 'photo' => '1', 'page' => '3'],
        ];
        yield 'defaults are left out' => [['tab' => 'results', 'sort' => 'date', 'dir' => 'desc', 'page' => '1'], []];
        yield 'the default direction of another sort' => [['sort' => 'name', 'dir' => 'asc'], ['sort' => 'name']];
        yield 'unknown values' => [['tab' => 'all', 'sort' => 'price', 'dir' => 'up', 'type' => 'quartet', 'year' => '12', 'pieces' => 'lots', 'first' => 'yes', 'page' => '0'], []];
        yield 'arrays and numbers' => [['tab' => ['results'], 'q' => ['x'], 'sort' => 5, 'page' => ['2'], 'first' => 1], []];
        yield 'a year out of range' => [['year' => '1850'], []];
        yield 'a sort of another tab' => [['tab' => 'puzzles', 'sort' => 'time'], ['tab' => 'puzzles']];
        yield 'filters of the results mean nothing on the puzzles' => [
            ['tab' => 'puzzles', 'type' => 'duo', 'year' => '2024', 'first' => '1', 'imported' => '1', 'photo' => '1', 'comment' => '1', 'collection' => 'main', 'sort' => 'brand'],
            ['tab' => 'puzzles', 'sort' => 'brand', 'comment' => '1', 'collection' => 'main'],
        ];
        yield 'a collection by its id' => [
            ['tab' => 'puzzles', 'collection' => '018D0008-0000-0000-0000-000000000001'],
            ['tab' => 'puzzles', 'collection' => '018d0008-0000-0000-0000-000000000001'],
        ];
        yield 'a collection that is no id' => [['tab' => 'puzzles', 'collection' => "' OR 1=1"], ['tab' => 'puzzles']];
        yield 'the wish list has no collection and no comment' => [['tab' => 'wishlist', 'collection' => 'main', 'comment' => '1', 'pieces' => '1000'], ['tab' => 'wishlist', 'pieces' => '1000']];
        yield 'broken bytes in the search' => [['q' => "\xC3\x28"], []];
        yield 'an empty search' => [['q' => "  \t "], []];
    }

    /**
     * @param array<mixed> $input
     * @param array<string, string> $canonical
     */
    #[DataProvider('provideAddresses')]
    public function testAnAddressBecomesAValidStateAndOneCanonicalAddress(array $input, array $canonical): void
    {
        $criteria = MyDataCriteria::fromUserInput($input);

        self::assertEquals($canonical, $criteria->query());
        // Reading the canonical address gives the same state
        self::assertEquals($criteria, MyDataCriteria::fromUserInput($criteria->query()));
    }

    public function testTheSearchIsCutAtAHundredCharacters(): void
    {
        $criteria = MyDataCriteria::fromUserInput(['q' => str_repeat('ž', 250)]);

        self::assertSame(100, mb_strlen((string) $criteria->search));
    }

    public function testTheMainCollectionIsTheSystemCollection(): void
    {
        $criteria = MyDataCriteria::fromUserInput(['tab' => 'puzzles', 'collection' => 'main']);

        self::assertSame(Collection::SYSTEM_ID, $criteria->collectionId);
        self::assertSame('main', $criteria->collectionParam());
    }

    public function testAChangeStartsAtTheFirstPageAndAPageKeepsTheRest(): void
    {
        $criteria = MyDataCriteria::fromUserInput(['type' => 'solo', 'page' => '4']);

        self::assertSame(150, $criteria->offset());
        self::assertSame(['type' => 'solo', 'page' => '4'], $criteria->query());
        self::assertSame(['pieces' => '500', 'type' => 'solo'], $criteria->query(['pieces' => '500']));
        self::assertSame(['type' => 'solo', 'page' => '5'], $criteria->query(['page' => 5]));
        self::assertSame([], $criteria->query(['type' => null]));
        self::assertTrue($criteria->hasFilters());
    }

    public function testAColumnHeaderSortsByItAndTurnsTheSortedOneRound(): void
    {
        $criteria = MyDataCriteria::fromUserInput(['sort' => 'time', 'year' => '2024', 'page' => '2']);

        self::assertSame(['sort' => 'time', 'dir' => 'desc', 'year' => '2024'], $criteria->sortQuery('time'));
        self::assertSame(['sort' => 'pieces', 'year' => '2024'], $criteria->sortQuery('pieces'));
        self::assertSame(['year' => '2024'], $criteria->sortQuery('date'));
        // No sort of this tab: the state as it is
        self::assertSame($criteria->query(), $criteria->sortQuery('added'));

        $byDate = MyDataCriteria::fromUserInput([]);
        self::assertSame(['dir' => 'asc'], $byDate->sortQuery('date'));
    }

    public function testTheColumnsAPlayerSwitchedOff(): void
    {
        $columns = MyDataColumns::fromStored(null);

        self::assertNull($columns->toStored());
        self::assertSame(['photo', 'ppm'], $columns->hiddenOn(MyDataTab::Results));
        self::assertTrue($columns->shows(MyDataTab::Results, 'time'));
        self::assertFalse($columns->shows(MyDataTab::Results, 'ppm'));
        self::assertTrue($columns->shows(MyDataTab::Results, 'f:018d0008-0000-0000-0000-000000000001'), 'a field of the player shows up by itself');

        $field = 'f:018D0008-0000-0000-0000-000000000001';
        $chosen = $columns->withHidden(MyDataTab::Results, ['brand', 'brand', 'collection', 'nope', $field, 'f:', 'f:1', 5, null, ['time']]);

        self::assertSame(['results' => ['brand', strtolower($field)]], $chosen->toStored());
        self::assertTrue($chosen->shows(MyDataTab::Results, 'ppm'), 'everything not switched off is shown once the player chose');
        self::assertSame([], $chosen->hiddenOn(MyDataTab::Puzzles));

        // The wish list has no own fields; nothing ticked off = an empty list, which is not "never chose"
        self::assertSame(['results' => ['brand', strtolower($field)], 'wishlist' => ['solved']], $chosen->withHidden(MyDataTab::WishList, ['solved', $field])->toStored());
        self::assertSame(['results' => []], $columns->withHidden(MyDataTab::Results, [])->toStored());
    }

    public function testWhatIsStoredIsReadAgainAndAnythingElseIsDropped(): void
    {
        $stored = ['results' => ['ppm', 'password'], 'puzzles' => 'lent', 'elsewhere' => ['brand'], 'wishlist' => ['added', 7]];

        self::assertSame(['results' => ['ppm'], 'wishlist' => ['added']], MyDataColumns::fromStored($stored)->toStored());
        self::assertSame(['results' => ['ppm'], 'wishlist' => ['added']], MyDataColumns::fromStored(json_encode($stored))->toStored());
        self::assertNull(MyDataColumns::fromStored('not json')->toStored());
        self::assertNull(MyDataColumns::fromStored(12)->toStored());
    }

    public function testNoMoreThanSixtyColumnsAreRemembered(): void
    {
        $fields = [];

        for ($i = 0; $i < 200; $i++) {
            $fields[] = sprintf('f:018d0008-0000-0000-0000-%012d', $i);
        }

        self::assertCount(MyDataColumns::MAX_HIDDEN, MyDataColumns::fromStored(null)->withHidden(MyDataTab::Puzzles, $fields)->hiddenOn(MyDataTab::Puzzles));
    }
}
