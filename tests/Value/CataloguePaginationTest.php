<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Value;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Value\CataloguePagination;

final class CataloguePaginationTest extends TestCase
{
    public function testPageCountAndExistence(): void
    {
        $empty = new CataloguePagination(page: 1, totalItems: 0);
        self::assertSame(1, $empty->totalPages);
        self::assertTrue($empty->exists(), 'An empty catalogue still renders its first page');
        self::assertFalse((new CataloguePagination(page: 2, totalItems: 0))->exists());

        self::assertSame(1, (new CataloguePagination(page: 1, totalItems: 48))->totalPages);
        self::assertFalse((new CataloguePagination(page: 2, totalItems: 48))->exists());

        $twoPages = new CataloguePagination(page: 2, totalItems: 49);
        self::assertSame(2, $twoPages->totalPages);
        self::assertTrue($twoPages->exists());

        self::assertSame(127, (new CataloguePagination(page: 1, totalItems: 6075))->totalPages);
        self::assertFalse((new CataloguePagination(page: 0, totalItems: 6075))->exists());
    }

    public function testOffsetAndItemRange(): void
    {
        $first = new CataloguePagination(page: 1, totalItems: 100);
        self::assertSame(0, $first->offset());
        self::assertSame(1, $first->firstItem());
        self::assertSame(48, $first->lastItem());
        self::assertTrue($first->isFirstPage());
        self::assertFalse($first->hasPreviousPage());
        self::assertTrue($first->hasNextPage());
        self::assertSame(2, $first->nextPage());

        $last = new CataloguePagination(page: 3, totalItems: 100);
        self::assertSame(96, $last->offset());
        self::assertSame(97, $last->firstItem());
        self::assertSame(100, $last->lastItem());
        self::assertFalse($last->isFirstPage());
        self::assertTrue($last->hasPreviousPage());
        self::assertFalse($last->hasNextPage());
        self::assertSame(2, $last->previousPage());

        $empty = new CataloguePagination(page: 1, totalItems: 0);
        self::assertSame(0, $empty->firstItem());
        self::assertSame(0, $empty->lastItem());
    }

    /**
     * @return iterable<string, array{int, int, list<null|int>}>
     */
    public static function links(): iterable
    {
        yield 'single page' => [1, 1, [1]];
        yield 'first of three' => [1, 3, [1, 2, 3]];
        yield 'first of many' => [1, 127, [1, 2, 3, null, 11, null, 127]];
        yield 'middle' => [64, 127, [1, null, 54, null, 62, 63, 64, 65, 66, null, 74, null, 127]];
        yield 'last' => [127, 127, [1, null, 117, null, 125, 126, 127]];
        yield 'single missing page is shown, not elided' => [5, 12, [1, 2, 3, 4, 5, 6, 7, null, 12]];
        yield 'jump next to the end' => [3, 13, [1, 2, 3, 4, 5, null, 13]];
        yield 'jump right before the end' => [3, 14, [1, 2, 3, 4, 5, null, 13, 14]];
    }

    /**
     * @param list<null|int> $expected
     */
    #[DataProvider('links')]
    public function testLinksShowFirstLastNeighboursAndJumps(int $page, int $totalPages, array $expected): void
    {
        $pagination = new CataloguePagination(page: $page, totalItems: $totalPages * CataloguePagination::PER_PAGE);

        self::assertSame($expected, $pagination->links());
    }
}
