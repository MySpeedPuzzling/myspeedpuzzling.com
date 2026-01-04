<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\CollectionItem;
use SpeedPuzzling\Web\Entity\LentPuzzle;
use SpeedPuzzling\Web\Entity\PuzzleSolvingTime;
use SpeedPuzzling\Web\Entity\SellSwapListItem;
use SpeedPuzzling\Web\Entity\SoldSwappedItem;
use SpeedPuzzling\Web\Entity\WishListItem;
use SpeedPuzzling\Web\Exceptions\PuzzleNotFound;
use SpeedPuzzling\Web\Message\ApprovePuzzleMergeRequest;
use SpeedPuzzling\Web\Message\SubmitPuzzleMergeRequest;
use SpeedPuzzling\Web\Repository\PuzzleMergeRequestRepository;
use SpeedPuzzling\Web\Repository\PuzzleRepository;
use SpeedPuzzling\Web\Repository\PuzzleStatisticsRepository;
use SpeedPuzzling\Web\Tests\DataFixtures\CollectionFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\ManufacturerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Value\PuzzleReportStatus;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;

final class ApprovePuzzleMergeRequestHandlerTest extends KernelTestCase
{
    private MessageBusInterface $messageBus;
    private PuzzleMergeRequestRepository $mergeRequestRepository;
    private PuzzleRepository $puzzleRepository;
    private EntityManagerInterface $entityManager;
    private PuzzleStatisticsRepository $statisticsRepository;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->messageBus = $container->get(MessageBusInterface::class);
        $this->mergeRequestRepository = $container->get(PuzzleMergeRequestRepository::class);
        $this->puzzleRepository = $container->get(PuzzleRepository::class);
        $this->entityManager = $container->get(EntityManagerInterface::class);
        $this->statisticsRepository = $container->get(PuzzleStatisticsRepository::class);
    }

    public function testApprovingMergeRequestUpdatesSurvivorPuzzle(): void
    {
        // First create a merge request
        $mergeRequestId = Uuid::uuid7()->toString();

        $this->messageBus->dispatch(
            new SubmitPuzzleMergeRequest(
                mergeRequestId: $mergeRequestId,
                sourcePuzzleId: PuzzleFixture::PUZZLE_500_04,
                reporterId: PlayerFixture::PLAYER_REGULAR,
                duplicatePuzzleIds: [
                    PuzzleFixture::PUZZLE_500_05,
                ],
            ),
        );

        // Now approve it
        $this->messageBus->dispatch(
            new ApprovePuzzleMergeRequest(
                mergeRequestId: $mergeRequestId,
                reviewerId: PlayerFixture::PLAYER_ADMIN,
                survivorPuzzleId: PuzzleFixture::PUZZLE_500_04,
                mergedName: 'Merged Puzzle Name',
                mergedEan: '9999999999999',
                mergedIdentificationNumber: 'MERGED-001',
                mergedPiecesCount: 500,
                mergedManufacturerId: ManufacturerFixture::MANUFACTURER_RAVENSBURGER,
                selectedImagePuzzleId: null,
            ),
        );

        // Verify merge request is approved
        $mergeRequest = $this->mergeRequestRepository->get($mergeRequestId);
        self::assertSame(PuzzleReportStatus::Approved, $mergeRequest->status);
        self::assertNotNull($mergeRequest->reviewedAt);
        self::assertNotNull($mergeRequest->reviewedBy);
        self::assertNotNull($mergeRequest->survivorPuzzleId);
        self::assertSame(PuzzleFixture::PUZZLE_500_04, $mergeRequest->survivorPuzzleId->toString());

        // Verify survivor puzzle was updated
        $survivorPuzzle = $this->puzzleRepository->get(PuzzleFixture::PUZZLE_500_04);
        self::assertSame('Merged Puzzle Name', $survivorPuzzle->name);
        self::assertSame('9999999999999', $survivorPuzzle->ean);
        self::assertSame('MERGED-001', $survivorPuzzle->identificationNumber);
        self::assertSame(500, $survivorPuzzle->piecesCount);
        self::assertNotNull($survivorPuzzle->manufacturer);
        self::assertSame(ManufacturerFixture::MANUFACTURER_RAVENSBURGER, $survivorPuzzle->manufacturer->id->toString());
    }

    public function testApprovingMergeRequestMigratesAllRelatedRecords(): void
    {
        // Get puzzle entities for querying
        $survivorPuzzle = $this->puzzleRepository->get(PuzzleFixture::PUZZLE_500_04);
        $duplicatePuzzle = $this->puzzleRepository->get(PuzzleFixture::PUZZLE_500_05);

        // --- BEFORE MERGE: Assert records exist for duplicate puzzle ---

        // Solving times - expect 2 for duplicate (TIME_43, TIME_44)
        $duplicateSolvingTimes = $this->entityManager->getRepository(PuzzleSolvingTime::class)
            ->findBy(['puzzle' => $duplicatePuzzle]);
        self::assertCount(2, $duplicateSolvingTimes, 'Expected 2 solving times for duplicate puzzle');

        // Collection items - expect 3 for duplicate (ITEM_25, ITEM_26, ITEM_27)
        // Note: ITEM_25 + ITEM_26 are in null collection, ITEM_27 is in COLLECTION_PUBLIC
        $duplicateCollectionItems = $this->entityManager->getRepository(CollectionItem::class)
            ->findBy(['puzzle' => $duplicatePuzzle]);
        self::assertCount(3, $duplicateCollectionItems, 'Expected 3 collection items for duplicate puzzle');

        // Wishlist items - expect 1 for duplicate (WISHLIST_08)
        $duplicateWishlistItems = $this->entityManager->getRepository(WishListItem::class)
            ->findBy(['puzzle' => $duplicatePuzzle]);
        self::assertCount(1, $duplicateWishlistItems, 'Expected 1 wishlist item for duplicate puzzle');

        // Sell/swap items - expect 1 for duplicate (SELLSWAP_08)
        $duplicateSellSwapItems = $this->entityManager->getRepository(SellSwapListItem::class)
            ->findBy(['puzzle' => $duplicatePuzzle]);
        self::assertCount(1, $duplicateSellSwapItems, 'Expected 1 sell/swap item for duplicate puzzle');

        // Lent puzzles - expect 1 for duplicate (LENT_07)
        $duplicateLentPuzzles = $this->entityManager->getRepository(LentPuzzle::class)
            ->findBy(['puzzle' => $duplicatePuzzle]);
        self::assertCount(1, $duplicateLentPuzzles, 'Expected 1 lent puzzle for duplicate puzzle');

        // Sold/swapped items - expect 2 for duplicate (SOLD_01, SOLD_02)
        $duplicateSoldSwappedItems = $this->entityManager->getRepository(SoldSwappedItem::class)
            ->findBy(['puzzle' => $duplicatePuzzle]);
        self::assertCount(2, $duplicateSoldSwappedItems, 'Expected 2 sold/swapped items for duplicate puzzle');

        // Initial survivor counts
        $initialSurvivorSolvingTimes = $this->entityManager->getRepository(PuzzleSolvingTime::class)
            ->findBy(['puzzle' => $survivorPuzzle]);
        $initialSurvivorSolvingTimesCount = count($initialSurvivorSolvingTimes);

        $initialSurvivorCollectionItems = $this->entityManager->getRepository(CollectionItem::class)
            ->findBy(['puzzle' => $survivorPuzzle]);
        self::assertCount(3, $initialSurvivorCollectionItems, 'Expected 3 collection items for survivor puzzle initially (ITEM_21, ITEM_28, ITEM_29)');

        // --- PERFORM MERGE ---
        $mergeRequestId = Uuid::uuid7()->toString();

        $this->messageBus->dispatch(
            new SubmitPuzzleMergeRequest(
                mergeRequestId: $mergeRequestId,
                sourcePuzzleId: PuzzleFixture::PUZZLE_500_04,
                reporterId: PlayerFixture::PLAYER_REGULAR,
                duplicatePuzzleIds: [PuzzleFixture::PUZZLE_500_05],
            ),
        );

        $this->messageBus->dispatch(
            new ApprovePuzzleMergeRequest(
                mergeRequestId: $mergeRequestId,
                reviewerId: PlayerFixture::PLAYER_ADMIN,
                survivorPuzzleId: PuzzleFixture::PUZZLE_500_04,
                mergedName: 'Merged Puzzle with All Data',
                mergedEan: null,
                mergedIdentificationNumber: null,
                mergedPiecesCount: 500,
                mergedManufacturerId: ManufacturerFixture::MANUFACTURER_RAVENSBURGER,
                selectedImagePuzzleId: null,
            ),
        );

        // Clear entity manager to ensure fresh data
        $this->entityManager->clear();

        // --- AFTER MERGE: Assert duplicate puzzle is deleted ---
        try {
            $this->puzzleRepository->get(PuzzleFixture::PUZZLE_500_05);
            self::fail('Expected PuzzleNotFound exception - duplicate puzzle should be deleted');
        } catch (PuzzleNotFound) {
            // Expected behavior
        }

        // Reload survivor puzzle
        $survivorPuzzle = $this->puzzleRepository->get(PuzzleFixture::PUZZLE_500_04);

        // --- AFTER MERGE: Assert all records migrated to survivor ---

        // Solving times - survivor should have 2 more (migrated from duplicate)
        $survivorSolvingTimes = $this->entityManager->getRepository(PuzzleSolvingTime::class)
            ->findBy(['puzzle' => $survivorPuzzle]);
        self::assertCount(
            $initialSurvivorSolvingTimesCount + 2,
            $survivorSolvingTimes,
            'Expected 2 solving times to be migrated to survivor puzzle',
        );

        // Collection items - survivor should have 3 total
        // All 3 original survivor items stay, all 3 duplicates are deduplicated (removed)
        // ITEM_21 stays, ITEM_28 stays, ITEM_29 stays
        // ITEM_27 deduplicated with ITEM_21, ITEM_25 deduplicated with ITEM_28, ITEM_26 deduplicated with ITEM_29
        $survivorCollectionItems = $this->entityManager->getRepository(CollectionItem::class)
            ->findBy(['puzzle' => $survivorPuzzle]);
        self::assertCount(3, $survivorCollectionItems, 'Expected 3 collection items after merge (all deduplicated)');

        // Wishlist items - survivor should have 1 (migrated)
        $survivorWishlistItems = $this->entityManager->getRepository(WishListItem::class)
            ->findBy(['puzzle' => $survivorPuzzle]);
        self::assertCount(1, $survivorWishlistItems, 'Expected 1 wishlist item migrated to survivor puzzle');

        // Sell/swap items - survivor should have 1 (migrated)
        $survivorSellSwapItems = $this->entityManager->getRepository(SellSwapListItem::class)
            ->findBy(['puzzle' => $survivorPuzzle]);
        self::assertCount(1, $survivorSellSwapItems, 'Expected 1 sell/swap item migrated to survivor puzzle');

        // Lent puzzles - survivor should have 1 (migrated)
        $survivorLentPuzzles = $this->entityManager->getRepository(LentPuzzle::class)
            ->findBy(['puzzle' => $survivorPuzzle]);
        self::assertCount(1, $survivorLentPuzzles, 'Expected 1 lent puzzle migrated to survivor puzzle');

        // Sold/swapped items - survivor should have 2 (migrated)
        $survivorSoldSwappedItems = $this->entityManager->getRepository(SoldSwappedItem::class)
            ->findBy(['puzzle' => $survivorPuzzle]);
        self::assertCount(2, $survivorSoldSwappedItems, 'Expected 2 sold/swapped items migrated to survivor puzzle');

        // Statistics should be recalculated
        $statistics = $this->statisticsRepository->findByPuzzleId($survivorPuzzle->id);
        self::assertNotNull($statistics, 'Expected statistics to exist for survivor puzzle');
        self::assertSame(2, $statistics->solvedTimesCount, 'Expected 2 solving times in statistics (migrated from duplicate)');
    }

    public function testApprovingMergeRequestDeduplicatesPlayerRecords(): void
    {
        // Get puzzle entities
        $survivorPuzzle = $this->puzzleRepository->get(PuzzleFixture::PUZZLE_500_04);
        $duplicatePuzzle = $this->puzzleRepository->get(PuzzleFixture::PUZZLE_500_05);

        // --- BEFORE MERGE: Assert deduplication scenarios exist ---

        // CollectionItem: PLAYER_WITH_STRIPE has BOTH puzzles in COLLECTION_PUBLIC (ITEM_21 + ITEM_27)
        $stripePlayerReference = $this->entityManager
            ->getRepository(\SpeedPuzzling\Web\Entity\Player::class)
            ->find(PlayerFixture::PLAYER_WITH_STRIPE);
        $publicCollectionReference = $this->entityManager
            ->getRepository(\SpeedPuzzling\Web\Entity\Collection::class)
            ->find(CollectionFixture::COLLECTION_PUBLIC);

        $stripeCollectionItemsForSurvivor = $this->entityManager->getRepository(CollectionItem::class)
            ->findBy(['player' => $stripePlayerReference, 'puzzle' => $survivorPuzzle, 'collection' => $publicCollectionReference]);
        self::assertCount(1, $stripeCollectionItemsForSurvivor, 'PLAYER_WITH_STRIPE should have survivor puzzle in COLLECTION_PUBLIC');

        $stripeCollectionItemsForDuplicate = $this->entityManager->getRepository(CollectionItem::class)
            ->findBy(['player' => $stripePlayerReference, 'puzzle' => $duplicatePuzzle, 'collection' => $publicCollectionReference]);
        self::assertCount(1, $stripeCollectionItemsForDuplicate, 'PLAYER_WITH_STRIPE should have duplicate puzzle in COLLECTION_PUBLIC');

        // CollectionItem: PLAYER_ADMIN has BOTH puzzles in null collection (ITEM_28 + ITEM_25)
        $adminPlayerReference = $this->entityManager
            ->getRepository(\SpeedPuzzling\Web\Entity\Player::class)
            ->find(PlayerFixture::PLAYER_ADMIN);

        $adminCollectionItemsForSurvivor = $this->entityManager->getRepository(CollectionItem::class)
            ->findBy(['player' => $adminPlayerReference, 'puzzle' => $survivorPuzzle, 'collection' => null]);
        self::assertCount(1, $adminCollectionItemsForSurvivor, 'PLAYER_ADMIN should have survivor puzzle in null collection');

        $adminCollectionItemsForDuplicate = $this->entityManager->getRepository(CollectionItem::class)
            ->findBy(['player' => $adminPlayerReference, 'puzzle' => $duplicatePuzzle, 'collection' => null]);
        self::assertCount(1, $adminCollectionItemsForDuplicate, 'PLAYER_ADMIN should have duplicate puzzle in null collection');

        // WishListItem: PLAYER_REGULAR has BOTH puzzles on wishlist (WISHLIST_08 + WISHLIST_09)
        $regularPlayerReference = $this->entityManager
            ->getRepository(\SpeedPuzzling\Web\Entity\Player::class)
            ->find(PlayerFixture::PLAYER_REGULAR);

        $regularWishlistForSurvivor = $this->entityManager->getRepository(WishListItem::class)
            ->findBy(['player' => $regularPlayerReference, 'puzzle' => $survivorPuzzle]);
        self::assertCount(1, $regularWishlistForSurvivor, 'PLAYER_REGULAR should have survivor puzzle on wishlist');

        $regularWishlistForDuplicate = $this->entityManager->getRepository(WishListItem::class)
            ->findBy(['player' => $regularPlayerReference, 'puzzle' => $duplicatePuzzle]);
        self::assertCount(1, $regularWishlistForDuplicate, 'PLAYER_REGULAR should have duplicate puzzle on wishlist');

        // SellSwapListItem: PLAYER_ADMIN has BOTH puzzles on sell/swap list (SELLSWAP_08 + SELLSWAP_09)
        $adminPlayerReference = $this->entityManager
            ->getRepository(\SpeedPuzzling\Web\Entity\Player::class)
            ->find(PlayerFixture::PLAYER_ADMIN);

        $adminSellSwapForSurvivor = $this->entityManager->getRepository(SellSwapListItem::class)
            ->findBy(['player' => $adminPlayerReference, 'puzzle' => $survivorPuzzle]);
        self::assertCount(1, $adminSellSwapForSurvivor, 'PLAYER_ADMIN should have survivor puzzle on sell/swap list');

        $adminSellSwapForDuplicate = $this->entityManager->getRepository(SellSwapListItem::class)
            ->findBy(['player' => $adminPlayerReference, 'puzzle' => $duplicatePuzzle]);
        self::assertCount(1, $adminSellSwapForDuplicate, 'PLAYER_ADMIN should have duplicate puzzle on sell/swap list');

        // LentPuzzle: PLAYER_REGULAR owns BOTH puzzles (LENT_07 + LENT_08)
        $regularLentForSurvivor = $this->entityManager->getRepository(LentPuzzle::class)
            ->findBy(['ownerPlayer' => $regularPlayerReference, 'puzzle' => $survivorPuzzle]);
        self::assertCount(1, $regularLentForSurvivor, 'PLAYER_REGULAR should own survivor puzzle in lent puzzles');

        $regularLentForDuplicate = $this->entityManager->getRepository(LentPuzzle::class)
            ->findBy(['ownerPlayer' => $regularPlayerReference, 'puzzle' => $duplicatePuzzle]);
        self::assertCount(1, $regularLentForDuplicate, 'PLAYER_REGULAR should own duplicate puzzle in lent puzzles');

        // --- PERFORM MERGE ---
        $mergeRequestId = Uuid::uuid7()->toString();

        $this->messageBus->dispatch(
            new SubmitPuzzleMergeRequest(
                mergeRequestId: $mergeRequestId,
                sourcePuzzleId: PuzzleFixture::PUZZLE_500_04,
                reporterId: PlayerFixture::PLAYER_REGULAR,
                duplicatePuzzleIds: [PuzzleFixture::PUZZLE_500_05],
            ),
        );

        $this->messageBus->dispatch(
            new ApprovePuzzleMergeRequest(
                mergeRequestId: $mergeRequestId,
                reviewerId: PlayerFixture::PLAYER_ADMIN,
                survivorPuzzleId: PuzzleFixture::PUZZLE_500_04,
                mergedName: 'Deduplicated Puzzle',
                mergedEan: null,
                mergedIdentificationNumber: null,
                mergedPiecesCount: 500,
                mergedManufacturerId: ManufacturerFixture::MANUFACTURER_TREFL,
                selectedImagePuzzleId: null,
            ),
        );

        // Clear entity manager to ensure fresh data
        $this->entityManager->clear();

        // Reload survivor puzzle and player references
        $survivorPuzzle = $this->puzzleRepository->get(PuzzleFixture::PUZZLE_500_04);
        $stripePlayerReference = $this->entityManager
            ->getRepository(\SpeedPuzzling\Web\Entity\Player::class)
            ->find(PlayerFixture::PLAYER_WITH_STRIPE);
        $regularPlayerReference = $this->entityManager
            ->getRepository(\SpeedPuzzling\Web\Entity\Player::class)
            ->find(PlayerFixture::PLAYER_REGULAR);
        $adminPlayerReference = $this->entityManager
            ->getRepository(\SpeedPuzzling\Web\Entity\Player::class)
            ->find(PlayerFixture::PLAYER_ADMIN);
        $publicCollectionReference = $this->entityManager
            ->getRepository(\SpeedPuzzling\Web\Entity\Collection::class)
            ->find(CollectionFixture::COLLECTION_PUBLIC);

        // --- AFTER MERGE: Assert deduplication happened ---

        // CollectionItem (named collection): PLAYER_WITH_STRIPE should have ONLY 1 item for survivor in COLLECTION_PUBLIC (not 2)
        $stripeCollectionItemsAfter = $this->entityManager->getRepository(CollectionItem::class)
            ->findBy(['player' => $stripePlayerReference, 'puzzle' => $survivorPuzzle, 'collection' => $publicCollectionReference]);
        self::assertCount(1, $stripeCollectionItemsAfter, 'PLAYER_WITH_STRIPE should have exactly 1 collection item for survivor puzzle in named collection (deduplication)');

        // CollectionItem (null/system collection): PLAYER_ADMIN should have ONLY 1 item for survivor in null collection (not 2)
        $adminCollectionItemsAfter = $this->entityManager->getRepository(CollectionItem::class)
            ->findBy(['player' => $adminPlayerReference, 'puzzle' => $survivorPuzzle, 'collection' => null]);
        self::assertCount(1, $adminCollectionItemsAfter, 'PLAYER_ADMIN should have exactly 1 collection item for survivor puzzle in null collection (deduplication)');

        // WishListItem: PLAYER_REGULAR should have ONLY 1 item for survivor (not 2)
        $regularWishlistAfter = $this->entityManager->getRepository(WishListItem::class)
            ->findBy(['player' => $regularPlayerReference, 'puzzle' => $survivorPuzzle]);
        self::assertCount(1, $regularWishlistAfter, 'PLAYER_REGULAR should have exactly 1 wishlist item for survivor puzzle (deduplication)');

        // SellSwapListItem: PLAYER_ADMIN should have ONLY 1 item for survivor (not 2)
        $adminSellSwapAfter = $this->entityManager->getRepository(SellSwapListItem::class)
            ->findBy(['player' => $adminPlayerReference, 'puzzle' => $survivorPuzzle]);
        self::assertCount(1, $adminSellSwapAfter, 'PLAYER_ADMIN should have exactly 1 sell/swap item for survivor puzzle (deduplication)');

        // LentPuzzle: PLAYER_REGULAR should own ONLY 1 lent puzzle record for survivor (not 2)
        $regularLentAfter = $this->entityManager->getRepository(LentPuzzle::class)
            ->findBy(['ownerPlayer' => $regularPlayerReference, 'puzzle' => $survivorPuzzle]);
        self::assertCount(1, $regularLentAfter, 'PLAYER_REGULAR should have exactly 1 lent puzzle for survivor puzzle (deduplication)');

        // PuzzleSolvingTime: ALL records should be kept (no deduplication for solving times)
        $survivorSolvingTimes = $this->entityManager->getRepository(PuzzleSolvingTime::class)
            ->findBy(['puzzle' => $survivorPuzzle]);
        // Original survivor had 0 solving times, duplicate had 2 (TIME_43, TIME_44)
        self::assertCount(2, $survivorSolvingTimes, 'All solving times should be migrated (no deduplication for solving times)');
    }
}
