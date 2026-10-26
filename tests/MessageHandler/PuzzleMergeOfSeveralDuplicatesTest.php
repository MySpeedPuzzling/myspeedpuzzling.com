<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Message\ApprovePuzzleMergeRequest;
use SpeedPuzzling\Web\Message\SubmitPuzzleMergeRequest;
use SpeedPuzzling\Web\Tests\BorrowSeeding;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Merging two duplicates that one player has both on the same list: the first moves onto the survivor, the second is
 * dropped - the survivor ends up there once. The second duplicate's lookup used to miss the first one's move (not
 * flushed yet), both landed on the survivor and the merge failed on the unique key.
 */
final class PuzzleMergeOfSeveralDuplicatesTest extends KernelTestCase
{
    use BorrowSeeding;

    public function testEveryListHoldsTheSurvivorOnce(): void
    {
        self::bootKernel();
        $connection = self::getContainer()->get(Connection::class);
        $bus = self::getContainer()->get(MessageBusInterface::class);

        $player = $this->seedPlayer('Collector');
        $survivor = $this->seedPuzzle(1000, 'Survivor');
        $duplicateA = $this->seedPuzzle(1000, 'Duplicate A');
        $duplicateB = $this->seedPuzzle(1000, 'Duplicate B');
        $shelf = $this->seedCustomCollection($player, true);

        foreach ([$duplicateA, $duplicateB] as $duplicate) {
            $this->seedWished($player, $duplicate);
            $this->seedOwned($player, $duplicate);
            $this->seedOwned($player, $duplicate, $shelf);
            $this->seedListing($player, $duplicate);
            $this->seedLentOut($player, $duplicate);
        }

        $mergeRequestId = Uuid::uuid7()->toString();
        $bus->dispatch(new SubmitPuzzleMergeRequest(
            mergeRequestId: $mergeRequestId,
            sourcePuzzleId: $survivor,
            reporterId: PlayerFixture::PLAYER_REGULAR,
            duplicatePuzzleIds: [$duplicateA, $duplicateB],
        ));
        $bus->dispatch(new ApprovePuzzleMergeRequest(
            mergeRequestId: $mergeRequestId,
            reviewerId: PlayerFixture::PLAYER_ADMIN,
            survivorPuzzleId: $survivor,
            mergedName: 'Survivor',
            mergedEans: null,
            mergedBrandCodes: null,
            mergedPiecesCount: 1000,
            mergedManufacturerId: null,
            selectedImagePuzzleId: null,
        ));

        $count = static fn (string $table, string $column): mixed => $connection->fetchOne(
            "SELECT COUNT(*) FROM {$table} WHERE {$column} = :player AND puzzle_id = :survivor",
            ['player' => $player, 'survivor' => $survivor],
        );

        self::assertSame(1, $count('wish_list_item', 'player_id'));
        self::assertSame(2, $count('collection_item', 'player_id'), 'Once in the system collection, once on the shelf');
        self::assertSame(1, $count('sell_swap_list_item', 'player_id'));
        self::assertSame(1, $count('lent_puzzle', 'owner_player_id'));
        self::assertSame(0, $connection->fetchOne('SELECT COUNT(*) FROM puzzle WHERE id IN (:a, :b)', ['a' => $duplicateA, 'b' => $duplicateB]));
    }
}
