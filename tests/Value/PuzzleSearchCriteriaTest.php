<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Value;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Value\PuzzleSearchCriteria;
use Symfony\Component\HttpFoundation\Request;

final class PuzzleSearchCriteriaTest extends TestCase
{
    private const string COLLECTION = 'collection:018d0008-0000-0000-0000-000000000001';

    private static function criteria(null|string $list, bool $isLoggedIn, bool $isMember): PuzzleSearchCriteria
    {
        return PuzzleSearchCriteria::fromUserInput(
            brandId: null,
            search: null,
            pieces: null,
            tagId: null,
            difficultyTiers: [],
            sortBy: 'most-solved',
            isMember: $isMember,
            list: $list,
            isLoggedIn: $isLoggedIn,
        );
    }

    /**
     * @return iterable<string, array{string, bool, bool, bool}> list, logged in, member, kept
     */
    public static function gating(): iterable
    {
        foreach (['library', 'wishlist', 'unsolved', 'solved'] as $free) {
            yield "$free/guest" => [$free, false, false, false];
            yield "$free/non-member" => [$free, true, false, true];
            yield "$free/member" => [$free, true, true, true];
        }

        foreach ([self::COLLECTION, 'borrowed', 'lent', 'sell-swap'] as $membersOnly) {
            yield "$membersOnly/guest" => [$membersOnly, false, false, false];
            yield "$membersOnly/non-member" => [$membersOnly, true, false, false];
            yield "$membersOnly/member" => [$membersOnly, true, true, true];
        }
    }

    #[DataProvider('gating')]
    public function testListIsGatedByViewer(string $list, bool $isLoggedIn, bool $isMember, bool $kept): void
    {
        $criteria = self::criteria($list, $isLoggedIn, $isMember);

        self::assertSame($kept ? $list : null, $criteria->list?->value());
        self::assertSame($kept === false, $criteria->isDefault());
    }

    public function testInvalidListIsDropped(): void
    {
        self::assertNull(self::criteria('collection:nope', true, true)->list);
        self::assertNull(self::criteria('everything', true, true)->list);
        self::assertTrue(self::criteria('', true, true)->isDefault());
    }

    public function testCallersWithoutTheListArgumentsNeverFilterByList(): void
    {
        // The public API search builds criteria this way - it must stay list-free
        $criteria = PuzzleSearchCriteria::fromUserInput(null, null, null, null, [], 'most-solved', true);

        self::assertNull($criteria->list);
        self::assertTrue($criteria->isDefault());
    }

    public function testQueryParametersRoundTripThroughTheRequest(): void
    {
        $criteria = PuzzleSearchCriteria::fromUserInput(
            brandId: null,
            search: 'cat',
            pieces: '500',
            tagId: null,
            difficultyTiers: ['2'],
            sortBy: 'a-z',
            isMember: true,
            list: self::COLLECTION,
            isLoggedIn: true,
        );

        $parameters = $criteria->toQueryParameters();
        self::assertSame(self::COLLECTION, $parameters['list']);

        $fromRequest = PuzzleSearchCriteria::fromRequest(Request::create('/', 'GET', $parameters), isMember: true, isLoggedIn: true);
        self::assertEquals($criteria, $fromRequest);

        // The same URL opened by a guest loses the list, keeps the rest that guests may use
        $asGuest = PuzzleSearchCriteria::fromRequest(Request::create('/', 'GET', $parameters), isMember: false);
        self::assertNull($asGuest->list);
        self::assertSame('cat', $asGuest->search);
        self::assertArrayNotHasKey('list', $asGuest->toQueryParameters());
    }
}
