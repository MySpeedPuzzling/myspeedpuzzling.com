<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Message\ApprovePuzzleMergeRequest;
use SpeedPuzzling\Web\Message\SubmitPuzzleMergeRequest;
use SpeedPuzzling\Web\Tests\BorrowSeeding;
use SpeedPuzzling\Web\Tests\CustomFieldsScenario;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Value\CustomFieldType;
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

    /**
     * The player has all three puzzles in one collection, each item with their own values (docs/features/
     * custom-fields.md "Copy, move, merge, remove"). The item on the survivor keeps what it holds; its gaps are filled
     * from the merged items in the order of the merge request - the first merged puzzle first, the second only where
     * there is still a gap. Where the player has no item of the survivor, the first merged item moves and is the one
     * that is filled.
     */
    public function testTheSurvivorsItemKeepsItsValuesAndItsGapsAreFilledFromTheMergedItemsInTheirOrder(): void
    {
        self::bootKernel();
        $connection = self::getContainer()->get(Connection::class);
        $bus = self::getContainer()->get(MessageBusInterface::class);
        $scenario = new CustomFieldsScenario(self::getContainer());

        $player = $this->seedPlayer('Collector');
        $survivor = $this->seedPuzzle(1000, 'Survivor');
        $duplicateA = $this->seedPuzzle(1000, 'Duplicate A');
        $duplicateB = $this->seedPuzzle(1000, 'Duplicate B');
        $shelfCollection = $this->seedCustomCollection($player, true);

        // The main collection holds all three, the custom one only the two merged puzzles
        foreach ([$survivor, $duplicateA, $duplicateB] as $puzzle) {
            $this->seedOwned($player, $puzzle);
        }

        $this->seedOwned($player, $duplicateA, $shelfCollection);
        $this->seedOwned($player, $duplicateB, $shelfCollection);

        $shelf = $scenario->field($player, 'Shelf', forResults: false, forCollectionItems: true);
        $price = $scenario->field($player, 'Price', CustomFieldType::Number, forResults: false, forCollectionItems: true);
        $notes = $scenario->field($player, 'Notes', forResults: false, forCollectionItems: true);
        $stars = $scenario->field($player, 'Stars', CustomFieldType::Rating, forResults: false, forCollectionItems: true);

        $itemOf = static fn (string $puzzle, null|string $collection): string => (string) $scenario->itemId($player, $puzzle, $collection);
        $survivorItem = $itemOf($survivor, null);
        $movingItem = $itemOf($duplicateA, $shelfCollection);
        $scenario->setItemValues($survivorItem, $player, [$shelf => 'of the survivor']);
        $scenario->setItemValues($itemOf($duplicateA, null), $player, [$shelf => 'of A', $price => '1', $notes => 'of A']);
        $scenario->setItemValues($itemOf($duplicateB, null), $player, [$shelf => 'of B', $price => '2', $notes => 'of B', $stars => '5']);
        $scenario->setItemValues($movingItem, $player, [$price => '10']);
        $scenario->setItemValues($itemOf($duplicateB, $shelfCollection), $player, [$price => '20', $notes => 'of B on the shelf']);

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
        $scenario->forgetLoadedEntities();

        // One item per collection, on the survivor
        self::assertSame(
            [$survivorItem, $movingItem],
            $connection->fetchFirstColumn('SELECT id FROM collection_item WHERE player_id = :player ORDER BY collection_id NULLS FIRST', ['player' => $player]),
        );
        self::assertSame($survivor, $connection->fetchOne('SELECT puzzle_id FROM collection_item WHERE id = :id', ['id' => $movingItem]));

        // The survivor's own value stays; the price and the notes come from A (first), the stars from B (only B had any)
        self::assertEquals([$shelf => 'of the survivor', $price => 1, $notes => 'of A', $stars => 5], $scenario->storedItemValues($survivorItem));
        // On the shelf A's item moved onto the survivor with its own value, B's filled the gap
        self::assertEquals([$price => 10, $notes => 'of B on the shelf'], $scenario->storedItemValues($movingItem));
    }
}
