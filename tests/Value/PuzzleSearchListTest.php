<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Value;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Value\PuzzleSearchList;
use SpeedPuzzling\Web\Value\PuzzleSearchListKind;

final class PuzzleSearchListTest extends TestCase
{
    private const string COLLECTION_ID = '018d0008-0000-0000-0000-000000000001';

    /**
     * @return iterable<string, array{string, PuzzleSearchListKind, bool}>
     */
    public static function validValues(): iterable
    {
        yield 'library' => ['library', PuzzleSearchListKind::Library, false];
        yield 'wishlist' => ['wishlist', PuzzleSearchListKind::Wishlist, false];
        yield 'unsolved' => ['unsolved', PuzzleSearchListKind::Unsolved, false];
        yield 'solved' => ['solved', PuzzleSearchListKind::Solved, false];
        yield 'collection' => ['collection:' . self::COLLECTION_ID, PuzzleSearchListKind::Collection, true];
        yield 'borrowed' => ['borrowed', PuzzleSearchListKind::Borrowed, true];
        yield 'lent' => ['lent', PuzzleSearchListKind::Lent, true];
        yield 'sell-swap' => ['sell-swap', PuzzleSearchListKind::SellSwap, true];
    }

    #[DataProvider('validValues')]
    public function testValidValuesRoundTrip(string $value, PuzzleSearchListKind $kind, bool $membersOnly): void
    {
        $list = PuzzleSearchList::tryFrom($value);

        self::assertNotNull($list);
        self::assertSame($kind, $list->kind);
        self::assertSame($value, $list->value());
        self::assertSame($membersOnly, $list->isMembersOnly());
    }

    public function testCollectionCarriesItsId(): void
    {
        $list = PuzzleSearchList::tryFrom('collection:' . strtoupper(self::COLLECTION_ID));

        self::assertNotNull($list);
        self::assertSame(self::COLLECTION_ID, $list->collectionId);
    }

    /**
     * @return iterable<string, array{null|string}>
     */
    public static function invalidValues(): iterable
    {
        yield 'null' => [null];
        yield 'empty' => [''];
        yield 'unknown kind' => ['favourites'];
        yield 'bare collection kind' => ['collection'];
        yield 'collection without id' => ['collection:'];
        yield 'collection with broken id' => ['collection:not-a-uuid'];
        yield 'system collection sentinel' => ['collection:__system_collection__'];
        yield 'sql' => ["wishlist' OR 1=1 --"];
    }

    #[DataProvider('invalidValues')]
    public function testInvalidValuesAreNull(null|string $value): void
    {
        self::assertNull(PuzzleSearchList::tryFrom($value));
    }
}
