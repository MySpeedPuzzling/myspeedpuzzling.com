<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Message\ApprovePuzzleMergeRequest;
use SpeedPuzzling\Web\Message\AskToBorrowPuzzles;
use SpeedPuzzling\Web\Message\SubmitPuzzleMergeRequest;
use SpeedPuzzling\Web\Tests\BorrowSeeding;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * A puzzle merge moves borrow asks onto the survivor (docs/features/wishlist-availability.md) - and drops one the same
 * request or the same waiting ask already holds, also when two merged puzzles were in one request.
 */
final class BorrowRequestPuzzleMergeTest extends KernelTestCase
{
    use BorrowSeeding;

    public function testAsksMoveToTheSurvivorWithoutDuplicates(): void
    {
        self::bootKernel();
        $bus = self::getContainer()->get(MessageBusInterface::class);
        $connection = self::getContainer()->get(Connection::class);

        $asker = $this->seedPlayer('Jana');
        $otherAsker = $this->seedPlayer('Karel');
        $owner = $this->seedPlayer('Anna');
        $survivor = $this->seedPuzzle(500, 'Survivor');
        $duplicateA = $this->seedPuzzle(500, 'Duplicate A');
        $duplicateB = $this->seedPuzzle(500, 'Duplicate B');

        $this->seedCollectionVisibility($owner, true);

        foreach ([$duplicateA, $duplicateB] as $puzzle) {
            $this->seedOwned($owner, $puzzle);
        }

        foreach ([$asker, $otherAsker] as $player) {
            $this->seedFavorite($player, $owner);
        }

        // One request holding both merged puzzles, another asker with one of them
        $this->seedWished($asker, $duplicateA);
        $this->seedWished($asker, $duplicateB);
        $this->seedWished($otherAsker, $duplicateB);
        $bus->dispatch(new AskToBorrowPuzzles(Uuid::uuid7()->toString(), $asker, [$owner => [$duplicateA, $duplicateB]]));
        $bus->dispatch(new AskToBorrowPuzzles(Uuid::uuid7()->toString(), $otherAsker, [$owner => [$duplicateB]]));

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
            mergedPiecesCount: 500,
            mergedManufacturerId: null,
            selectedImagePuzzleId: null,
        ));

        self::assertSame(
            [$survivor],
            $connection->fetchFirstColumn('SELECT puzzle_id FROM borrow_request_item WHERE asker_id = :asker', ['asker' => $asker]),
        );
        self::assertSame(
            [$survivor],
            $connection->fetchFirstColumn('SELECT puzzle_id FROM borrow_request_item WHERE asker_id = :asker', ['asker' => $otherAsker]),
        );
    }
}
