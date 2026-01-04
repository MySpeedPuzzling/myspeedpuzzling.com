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

        // Collection items - expect 2 for duplicate (ITEM_25, ITEM_26)
        $duplicateCollectionItems = $this->entityManager->getRepository(CollectionItem::class)
            ->findBy(['puzzle' => $duplicatePuzzle]);
        self::assertCount(2, $duplicateCollectionItems, 'Expected 2 collection items for duplicate puzzle');

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
        self::assertCount(1, $initialSurvivorCollectionItems, 'Expected 1 collection item for survivor puzzle initially (ITEM_21)');

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

        // Collection items - survivor should have 3 total (1 original + 2 migrated)
        $survivorCollectionItems = $this->entityManager->getRepository(CollectionItem::class)
            ->findBy(['puzzle' => $survivorPuzzle]);
        self::assertCount(3, $survivorCollectionItems, 'Expected 3 collection items after merge (1 original + 2 migrated)');

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
}
