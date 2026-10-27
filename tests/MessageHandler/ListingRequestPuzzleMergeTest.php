<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Message\ApprovePuzzleMergeRequest;
use SpeedPuzzling\Web\Message\SubmitPuzzleMergeRequest;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\ListingRequestSeeding;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * A puzzle merge moves sell/swap requests and offers onto the survivor (docs/features/marketplace/12-listing-requests.md);
 * an ask for a listing the merge drops as a duplicate closes as unavailable.
 */
final class ListingRequestPuzzleMergeTest extends KernelTestCase
{
    use ListingRequestSeeding;

    public function testAsksAndOffersMoveToTheSurvivorAndADroppedListingClosesItsAsk(): void
    {
        self::bootKernel();
        $bus = self::getContainer()->get(MessageBusInterface::class);
        $connection = self::getContainer()->get(Connection::class);

        $buyer = $this->seedPlayer('Jana');
        $seller = $this->seedPlayer('Anna');
        $survivor = $this->seedPuzzle(500, 'Survivor');
        $duplicate = $this->seedPuzzle(500, 'Duplicate');
        $offeredDuplicate = $this->seedPuzzle(500, 'Offered duplicate');

        // Anna lists both the survivor and its duplicate: the duplicate listing is dropped by the merge
        $this->seedListing($seller, $survivor);
        $duplicateListing = $this->seedListing($seller, $duplicate);
        $this->seedListingType($duplicateListing, 'swap');
        $offered = $this->seedListing($buyer, $offeredDuplicate);

        $this->askFor($buyer, [$seller => [$duplicateListing]], offersBySeller: [$seller => [$offered]]);
        $requestId = $this->requestIdOf($buyer, $seller);
        $itemId = $this->itemIdFor($requestId, $duplicateListing);

        $mergeRequestId = Uuid::uuid7()->toString();
        $bus->dispatch(new SubmitPuzzleMergeRequest(
            mergeRequestId: $mergeRequestId,
            sourcePuzzleId: $survivor,
            reporterId: PlayerFixture::PLAYER_REGULAR,
            duplicatePuzzleIds: [$duplicate, $offeredDuplicate],
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
            ['puzzle_id' => $survivor, 'status' => 'unavailable', 'listing_id' => null],
            $connection->fetchAssociative('SELECT puzzle_id, status, listing_id FROM listing_request_item WHERE id = :id', ['id' => $itemId]),
        );
        self::assertSame(
            [$survivor],
            $connection->fetchFirstColumn('SELECT puzzle_id FROM listing_request_offer WHERE request_id = :id', ['id' => $requestId]),
        );
    }
}
