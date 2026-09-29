<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Query;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Query\GetUserPuzzleStatuses;
use SpeedPuzzling\Web\Query\SearchPuzzle;
use SpeedPuzzling\Web\Results\PuzzleOverview;
use SpeedPuzzling\Web\Tests\DataFixtures\CollectionFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Value\PiecesRange;
use SpeedPuzzling\Web\Value\PuzzleSearchList;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The "My list" filter of the puzzle database (SearchPuzzle::listFilter). Expected sets are
 * PLAYER_WITH_STRIPE's fixture lists; solved / unsolved are checked against
 * GetUserPuzzleStatuses, which owns those definitions for the status badges.
 */
final class SearchPuzzleListTest extends KernelTestCase
{
    private SearchPuzzle $searchPuzzle;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->searchPuzzle = self::getContainer()->get(SearchPuzzle::class);
    }

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function stripeLists(): iterable
    {
        yield 'library' => ['library', [PuzzleFixture::PUZZLE_500_02, PuzzleFixture::PUZZLE_500_03, PuzzleFixture::PUZZLE_1000_02, PuzzleFixture::PUZZLE_1500_01, PuzzleFixture::PUZZLE_2000]];
        yield 'custom collection' => ['collection:' . CollectionFixture::COLLECTION_STRIPE_TREFL, [PuzzleFixture::PUZZLE_500_02, PuzzleFixture::PUZZLE_1000_04, PuzzleFixture::PUZZLE_1000_05]];
        yield 'wishlist' => ['wishlist', [PuzzleFixture::PUZZLE_500_01, PuzzleFixture::PUZZLE_3000, PuzzleFixture::PUZZLE_9000]];
        yield 'borrowed' => ['borrowed', [PuzzleFixture::PUZZLE_1500_02, PuzzleFixture::PUZZLE_3000]];
        yield 'lent' => ['lent', [PuzzleFixture::PUZZLE_500_03, PuzzleFixture::PUZZLE_1000_01, PuzzleFixture::PUZZLE_1500_01, PuzzleFixture::PUZZLE_2000]];
        yield 'sell-swap' => ['sell-swap', [PuzzleFixture::PUZZLE_500_01, PuzzleFixture::PUZZLE_500_02, PuzzleFixture::PUZZLE_500_03, PuzzleFixture::PUZZLE_1000_01, PuzzleFixture::PUZZLE_1000_02, PuzzleFixture::PUZZLE_1000_03, PuzzleFixture::PUZZLE_1500_01]];
    }

    /**
     * @param list<string> $expected
     */
    #[DataProvider('stripeLists')]
    public function testListReturnsExactlyThePlayersPuzzles(string $list, array $expected): void
    {
        $this->assertListMatches(PlayerFixture::PLAYER_WITH_STRIPE, $list, $expected);
    }

    public function testSolvedAndUnsolvedMatchThePuzzleStatuses(): void
    {
        foreach ([PlayerFixture::PLAYER_WITH_STRIPE, PlayerFixture::PLAYER_REGULAR, PlayerFixture::PLAYER_PRIVATE] as $playerId) {
            // A fresh lookup per player - the statuses query caches per request
            self::ensureKernelShutdown();
            self::bootKernel();
            $this->searchPuzzle = self::getContainer()->get(SearchPuzzle::class);
            $statuses = self::getContainer()->get(GetUserPuzzleStatuses::class)->byPlayerId($playerId);

            self::assertNotSame([], $statuses->solved);
            $this->assertListMatches($playerId, 'solved', $statuses->solved);
            $this->assertListMatches($playerId, 'unsolved', $statuses->unsolved);
        }
    }

    public function testTeamMembershipCountsAsSolved(): void
    {
        // PLAYER_PRIVATE solved PUZZLE_1000_01 and PUZZLE_1000_03 only as a member of REGULAR's pair
        $solved = $this->ids(PlayerFixture::PLAYER_PRIVATE, 'solved');

        self::assertContains(PuzzleFixture::PUZZLE_1000_01, $solved);
        self::assertContains(PuzzleFixture::PUZZLE_1000_03, $solved);

        // ... so owning one of them does not make it unsolved
        $connection = self::getContainer()->get(Connection::class);
        foreach ([PuzzleFixture::PUZZLE_1000_03, PuzzleFixture::PUZZLE_6000] as $puzzleId) {
            $connection->insert('collection_item', [
                'id' => Uuid::uuid7()->toString(),
                'player_id' => PlayerFixture::PLAYER_PRIVATE,
                'puzzle_id' => $puzzleId,
                'added_at' => '2026-01-01 10:00:00',
            ]);
        }

        $unsolved = $this->ids(PlayerFixture::PLAYER_PRIVATE, 'unsolved');
        self::assertNotContains(PuzzleFixture::PUZZLE_1000_03, $unsolved);
        self::assertContains(PuzzleFixture::PUZZLE_6000, $unsolved);
    }

    public function testSomebodyElsesCollectionMatchesNothing(): void
    {
        $this->assertListMatches(PlayerFixture::PLAYER_REGULAR, 'collection:' . CollectionFixture::COLLECTION_PUBLIC, []);
    }

    public function testListCombinesWithTheOtherFilters(): void
    {
        $list = PuzzleSearchList::tryFrom('sell-swap');
        $pieces = PiecesRange::between(500, 500);

        $puzzles = $this->searchPuzzle->byUserInput(null, null, $pieces, null, 'a-z', 0, 100, list: $list, listPlayerId: PlayerFixture::PLAYER_WITH_STRIPE);
        $count = $this->searchPuzzle->countByUserInput(null, null, $pieces, null, list: $list, listPlayerId: PlayerFixture::PLAYER_WITH_STRIPE);

        $ids = array_map(static fn (PuzzleOverview $puzzle): string => $puzzle->puzzleId, $puzzles);
        sort($ids);
        self::assertSame([PuzzleFixture::PUZZLE_500_01, PuzzleFixture::PUZZLE_500_02, PuzzleFixture::PUZZLE_500_03], $ids);
        self::assertSame(3, $count);
    }

    public function testPagesThroughTheListWithoutDuplicates(): void
    {
        $list = PuzzleSearchList::tryFrom('sell-swap');
        $seen = [];

        foreach ([0, 3, 6] as $offset) {
            foreach ($this->searchPuzzle->byUserInput(null, null, PiecesRange::any(), null, 'most-solved', $offset, 3, list: $list, listPlayerId: PlayerFixture::PLAYER_WITH_STRIPE) as $puzzle) {
                $seen[] = $puzzle->puzzleId;
            }
        }

        self::assertCount(7, $seen);
        self::assertCount(7, array_unique($seen));
    }

    public function testListWithoutPlayerIsAProgrammingError(): void
    {
        $this->expectException(\LogicException::class);

        $this->searchPuzzle->countByUserInput(null, null, PiecesRange::any(), null, list: PuzzleSearchList::tryFrom('wishlist'));
    }

    /**
     * @param array<string> $expected
     */
    private function assertListMatches(string $playerId, string $listValue, array $expected): void
    {
        $expected = array_values(array_unique($expected));
        sort($expected);

        self::assertSame($expected, $this->ids($playerId, $listValue), "$listValue of $playerId");

        $list = PuzzleSearchList::tryFrom($listValue);
        self::assertSame(count($expected), $this->searchPuzzle->countByUserInput(null, null, PiecesRange::any(), null, list: $list, listPlayerId: $playerId), "count of $listValue");
    }

    /**
     * @return list<string>
     */
    private function ids(string $playerId, string $listValue): array
    {
        $list = PuzzleSearchList::tryFrom($listValue);
        self::assertNotNull($list);

        $ids = array_map(
            static fn (PuzzleOverview $puzzle): string => $puzzle->puzzleId,
            $this->searchPuzzle->byUserInput(null, null, PiecesRange::any(), null, 'most-solved', 0, 200, list: $list, listPlayerId: $playerId),
        );
        sort($ids);

        return $ids;
    }
}
