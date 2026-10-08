<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Message\MarkPuzzlesAsSoldOrSwapped;
use SpeedPuzzling\Web\Message\RemovePuzzleListingReservations;
use SpeedPuzzling\Web\Message\RemovePuzzlesFromAllCollections;
use SpeedPuzzling\Web\Message\RemovePuzzlesFromSellSwapList;
use SpeedPuzzling\Web\Message\RemovePuzzlesFromWishList;
use SpeedPuzzling\Web\Message\ReservePuzzleListings;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Value\SelectedPuzzlesOutcome;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

/**
 * Actions on the puzzles selected on the wishlist, sell/swap and unsolved pages
 * (docs/features/collections/bulk-actions.md "Other lists"). Fixtures of PLAYER_WITH_STRIPE: wishlist 9000, 3000,
 * 500_01; listings 500_01, 500_02, 1000_01 (reserved), 500_03 (reserved), 1000_02 (reserved), 1500_01, 1000_03;
 * PUZZLE_500_02 sits in three collections.
 */
final class SelectedListPuzzlesHandlersTest extends KernelTestCase
{
    private const string UNKNOWN_PUZZLE = '018d0003-0000-0000-0000-0000000fffff';

    private MessageBusInterface $messageBus;
    private Connection $connection;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->messageBus = self::getContainer()->get(MessageBusInterface::class);
        $this->connection = self::getContainer()->get(Connection::class);
    }

    public function testRemoveFromTheWishlistCountsWhatWasNotOnIt(): void
    {
        $outcome = $this->dispatch(new RemovePuzzlesFromWishList(
            PlayerFixture::PLAYER_WITH_STRIPE,
            [PuzzleFixture::PUZZLE_9000, PuzzleFixture::PUZZLE_3000, PuzzleFixture::PUZZLE_1000_04],
        ));

        self::assertSame(2, $outcome->changed);
        self::assertSame(1, $outcome->skipped);
        self::assertSame(0, $this->rows('wish_list_item', PuzzleFixture::PUZZLE_9000));
        self::assertSame(0, $this->rows('wish_list_item', PuzzleFixture::PUZZLE_3000));
        self::assertSame(1, $this->rows('wish_list_item', PuzzleFixture::PUZZLE_500_01));
    }

    public function testSoldListingsLeaveTheListCollectionsAndWishlistLikeOneByOne(): void
    {
        $outcome = $this->dispatch(new MarkPuzzlesAsSoldOrSwapped(
            PlayerFixture::PLAYER_WITH_STRIPE,
            [PuzzleFixture::PUZZLE_500_01, PuzzleFixture::PUZZLE_1000_03, self::UNKNOWN_PUZZLE],
        ));

        self::assertSame(2, $outcome->changed);
        self::assertSame(1, $outcome->skipped);

        foreach ([PuzzleFixture::PUZZLE_500_01, PuzzleFixture::PUZZLE_1000_03] as $puzzleId) {
            self::assertSame(0, $this->rows('sell_swap_list_item', $puzzleId), $puzzleId);
            self::assertSame(0, $this->rows('collection_item', $puzzleId), $puzzleId);
            self::assertSame(1, $this->connection->fetchOne(
                'SELECT COUNT(*) FROM sold_swapped_item WHERE seller_id = :player AND puzzle_id = :puzzle AND buyer_player_id IS NULL AND buyer_name IS NULL',
                ['player' => PlayerFixture::PLAYER_WITH_STRIPE, 'puzzle' => $puzzleId],
            ), $puzzleId);
        }

        self::assertSame(0, $this->rows('wish_list_item', PuzzleFixture::PUZZLE_500_01));
    }

    public function testReserveLeavesReservedListingsAsTheyAre(): void
    {
        $outcome = $this->dispatch(new ReservePuzzleListings(
            PlayerFixture::PLAYER_WITH_STRIPE,
            [PuzzleFixture::PUZZLE_500_01, PuzzleFixture::PUZZLE_500_03],
        ));

        self::assertSame(1, $outcome->changed);
        self::assertSame(1, $outcome->alreadyThere);
        self::assertTrue($this->reserved(PuzzleFixture::PUZZLE_500_01));
        // Still reserved for whom it was
        self::assertSame(PlayerFixture::PLAYER_ADMIN, $this->connection->fetchOne(
            'SELECT reserved_for_player_id FROM sell_swap_list_item WHERE player_id = :player AND puzzle_id = :puzzle',
            ['player' => PlayerFixture::PLAYER_WITH_STRIPE, 'puzzle' => PuzzleFixture::PUZZLE_500_03],
        ));
    }

    public function testRemoveReservationOnlyTouchesReservedListings(): void
    {
        $outcome = $this->dispatch(new RemovePuzzleListingReservations(
            PlayerFixture::PLAYER_WITH_STRIPE,
            [PuzzleFixture::PUZZLE_1000_01, PuzzleFixture::PUZZLE_500_02, PuzzleFixture::PUZZLE_9000],
        ));

        self::assertSame(1, $outcome->changed);
        self::assertSame(1, $outcome->alreadyThere);
        self::assertSame(1, $outcome->skipped);
        self::assertFalse($this->reserved(PuzzleFixture::PUZZLE_1000_01));
    }

    public function testRemoveFromTheSellSwapListKeepsTheCollections(): void
    {
        $inCollections = $this->rows('collection_item', PuzzleFixture::PUZZLE_500_02);

        // SELLSWAP_01 (500_01) has a conversation: it is told, and the next listing's message must still go out
        $outcome = $this->dispatch(new RemovePuzzlesFromSellSwapList(
            PlayerFixture::PLAYER_WITH_STRIPE,
            [PuzzleFixture::PUZZLE_500_01, PuzzleFixture::PUZZLE_500_02, PuzzleFixture::PUZZLE_1500_01],
        ));

        self::assertSame(3, $outcome->changed);
        self::assertSame(0, $this->rows('sell_swap_list_item', PuzzleFixture::PUZZLE_500_01));
        self::assertSame(0, $this->rows('sell_swap_list_item', PuzzleFixture::PUZZLE_500_02));
        self::assertSame(0, $this->rows('sell_swap_list_item', PuzzleFixture::PUZZLE_1500_01));
        self::assertSame($inCollections, $this->rows('collection_item', PuzzleFixture::PUZZLE_500_02));
    }

    public function testRemoveFromAllCollectionsTakesEveryCopy(): void
    {
        self::assertSame(3, $this->rows('collection_item', PuzzleFixture::PUZZLE_500_02));

        $outcome = $this->dispatch(new RemovePuzzlesFromAllCollections(
            PlayerFixture::PLAYER_WITH_STRIPE,
            [PuzzleFixture::PUZZLE_500_02, self::UNKNOWN_PUZZLE],
        ));

        self::assertSame(1, $outcome->changed);
        self::assertSame(1, $outcome->skipped);
        self::assertSame(0, $this->rows('collection_item', PuzzleFixture::PUZZLE_500_02));
    }

    public function testAnotherPlayersIdsChangeNothingOfTheirs(): void
    {
        // PLAYER_REGULAR has 4000 on the wishlist; the member asks to remove it from theirs
        $outcome = $this->dispatch(new RemovePuzzlesFromWishList(PlayerFixture::PLAYER_WITH_STRIPE, [PuzzleFixture::PUZZLE_4000]));

        self::assertSame(0, $outcome->changed);
        self::assertSame(1, $this->connection->fetchOne(
            'SELECT COUNT(*) FROM wish_list_item WHERE player_id = :player AND puzzle_id = :puzzle',
            ['player' => PlayerFixture::PLAYER_REGULAR, 'puzzle' => PuzzleFixture::PUZZLE_4000],
        ));
    }

    private function dispatch(object $message): SelectedPuzzlesOutcome
    {
        $outcome = $this->messageBus->dispatch($message)->last(HandledStamp::class)?->getResult();
        self::assertInstanceOf(SelectedPuzzlesOutcome::class, $outcome);

        return $outcome;
    }

    private function rows(string $table, string $puzzleId): int
    {
        $count = $this->connection->fetchOne(
            "SELECT COUNT(*) FROM {$table} WHERE player_id = :player AND puzzle_id = :puzzle",
            ['player' => PlayerFixture::PLAYER_WITH_STRIPE, 'puzzle' => $puzzleId],
        );
        assert(is_int($count));

        return $count;
    }

    private function reserved(string $puzzleId): bool
    {
        return (bool) $this->connection->fetchOne(
            'SELECT reserved FROM sell_swap_list_item WHERE player_id = :player AND puzzle_id = :puzzle',
            ['player' => PlayerFixture::PLAYER_WITH_STRIPE, 'puzzle' => $puzzleId],
        );
    }
}
