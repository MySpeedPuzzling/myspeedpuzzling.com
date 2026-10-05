<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use DateTimeImmutable;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\ORM\EntityManagerInterface;
use PDO;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\CollectionItem;
use SpeedPuzzling\Web\Entity\CompetitionRound;
use SpeedPuzzling\Web\Entity\CompetitionRoundPuzzle;
use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Entity\Stopwatch;
use SpeedPuzzling\Web\Entity\Tag;
use SpeedPuzzling\Web\Entity\LentPuzzle;
use SpeedPuzzling\Web\Entity\LentPuzzleTransfer;
use SpeedPuzzling\Web\Entity\PuzzleMergeAudit;
use SpeedPuzzling\Web\Value\BrandCodeList;
use SpeedPuzzling\Web\Value\EanList;
use SpeedPuzzling\Web\Value\NotificationType;
use SpeedPuzzling\Web\Value\PuzzleModerationAction;
use SpeedPuzzling\Web\Entity\Notification;
use SpeedPuzzling\Web\Entity\PuzzleModerationDecision;
use SpeedPuzzling\Web\Entity\PuzzleSolvingTime;
use SpeedPuzzling\Web\Entity\SellSwapListItem;
use SpeedPuzzling\Web\Entity\SoldSwappedItem;
use SpeedPuzzling\Web\Entity\WishListItem;
use SpeedPuzzling\Web\Exceptions\InvalidPuzzleValues;
use SpeedPuzzling\Web\Exceptions\PuzzleChangedMeanwhile;
use SpeedPuzzling\Web\Exceptions\PuzzleNotFound;
use SpeedPuzzling\Web\Message\ApprovePuzzleMergeRequest;
use SpeedPuzzling\Web\Message\SubmitPuzzleMergeRequest;
use SpeedPuzzling\Web\Repository\PuzzleMergeRequestRepository;
use SpeedPuzzling\Web\Repository\PuzzleRepository;
use SpeedPuzzling\Web\Repository\PuzzleStatisticsRepository;
use SpeedPuzzling\Web\Tests\DataFixtures\CollectionFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionRoundFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\ManufacturerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Value\MergeDecisionConfidence;
use SpeedPuzzling\Web\Value\MergeDecisionSource;
use SpeedPuzzling\Web\Value\PuzzleName;
use SpeedPuzzling\Web\Value\PuzzleNames;
use SpeedPuzzling\Web\Value\PuzzleRecordVersion;
use SpeedPuzzling\Web\Value\PuzzleReportStatus;
use SpeedPuzzling\Web\Value\TransferType;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;
use Throwable;

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
                mergedEans: EanList::fromInputs(['9999999999999']),
                mergedBrandCodes: BrandCodeList::fromInputs(['merged-001']),
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
                mergedEans: null,
                mergedBrandCodes: null,
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
                mergedEans: null,
                mergedBrandCodes: null,
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

    public function testApprovedMergeIsRecordedInAuditTrailWithBeforeAndAfterSnapshots(): void
    {
        $duplicatePuzzle = $this->puzzleRepository->get(PuzzleFixture::PUZZLE_500_05);
        $duplicatePuzzle->changeNames('Doomed Duplicate', null, new PuzzleNames([new PuzzleName('Odsouzený duplikát', 'cs')]), new DateTimeImmutable());
        $duplicatePuzzle->updateProductIdentifiers(EanList::fromStored('1111111111111'), BrandCodeList::fromStored('DUP-001'));
        $this->entityManager->flush();

        $migratedSolvingTimeIds = array_map(
            static fn(PuzzleSolvingTime $time): string => $time->id->toString(),
            $this->entityManager->getRepository(PuzzleSolvingTime::class)->findBy(['puzzle' => $duplicatePuzzle]),
        );
        self::assertNotEmpty($migratedSolvingTimeIds, 'Fixture should give the duplicate puzzle some solving times to migrate');

        $mergeRequestId = $this->submitMergeRequest();

        $this->messageBus->dispatch(
            new ApprovePuzzleMergeRequest(
                mergeRequestId: $mergeRequestId,
                reviewerId: PlayerFixture::PLAYER_ADMIN,
                survivorPuzzleId: PuzzleFixture::PUZZLE_500_04,
                mergedName: 'Survivor Name',
                mergedEans: null,
                mergedBrandCodes: null,
                mergedPiecesCount: 500,
                mergedManufacturerId: null,
                selectedImagePuzzleId: null,
                decisionSource: MergeDecisionSource::InternalApi,
                decisionConfidence: MergeDecisionConfidence::Medium,
                decisionNote: 'Same artwork, manufacturer recorded under two names.',
            ),
        );

        $audit = $this->entityManager->getRepository(PuzzleMergeAudit::class)
            ->findOneBy(['mergeRequestId' => $mergeRequestId]);

        self::assertNotNull($audit, 'Approving a merge must leave an audit record');
        self::assertSame(PuzzleFixture::PUZZLE_500_04, $audit->survivorPuzzleId->toString());
        self::assertSame(MergeDecisionSource::InternalApi, $audit->decisionSource);
        self::assertSame(MergeDecisionConfidence::Medium, $audit->decisionConfidence);
        self::assertSame('Same artwork, manufacturer recorded under two names.', $audit->decisionNote);
        self::assertNotNull($audit->performedBy);
        self::assertSame(PlayerFixture::PLAYER_ADMIN, $audit->performedBy->id->toString());

        // The deleted puzzle survives only here - its full row must be recoverable
        $mergedSnapshots = $audit->snapshotBefore['mergedPuzzles'];
        self::assertIsArray($mergedSnapshots);
        self::assertCount(1, $mergedSnapshots);

        $deletedPuzzleSnapshot = $mergedSnapshots[0];
        self::assertIsArray($deletedPuzzleSnapshot);
        self::assertSame(PuzzleFixture::PUZZLE_500_05, $deletedPuzzleSnapshot['id']);
        self::assertSame('Doomed Duplicate', $deletedPuzzleSnapshot['name']);
        self::assertSame([['name' => 'Odsouzený duplikát', 'language' => 'cs']], $deletedPuzzleSnapshot['alternativeNames']);
        self::assertNull($deletedPuzzleSnapshot['nameLanguage']);
        self::assertArrayNotHasKey('alternativeName', $deletedPuzzleSnapshot);
        self::assertSame('1111111111111', $deletedPuzzleSnapshot['ean']);
        self::assertSame('DUP-001', $deletedPuzzleSnapshot['identificationNumber']);

        // And what moved, so the migration can be unpicked row by row
        $migrated = $audit->snapshotBefore['migrated'];
        self::assertIsArray($migrated);
        self::assertEqualsCanonicalizing($migratedSolvingTimeIds, $migrated['solvingTimes']);

        $survivorAfter = $audit->snapshotAfter['survivorPuzzle'];
        self::assertIsArray($survivorAfter);
        self::assertSame('Survivor Name', $survivorAfter['name']);
        self::assertSame([
            ['name' => 'Odsouzený duplikát', 'language' => 'cs'],
            ['name' => 'Doomed Duplicate', 'language' => null],
            ['name' => 'Puzzle 4', 'language' => null],
        ], $survivorAfter['alternativeNames']);
    }

    public function testMergeCarriesOverDetailsOnlyTheDeletedPuzzleHad(): void
    {
        // The survivor is the bare record; everything descriptive sits on the duplicate
        $survivorPuzzle = $this->puzzleRepository->get(PuzzleFixture::PUZZLE_500_04);
        $survivorPuzzle->updateProductIdentifiers(EanList::fromStored(null), BrandCodeList::fromStored(null));
        $survivorPuzzle->image = null;

        $duplicatePuzzle = $this->puzzleRepository->get(PuzzleFixture::PUZZLE_500_05);
        $duplicatePuzzle->updateProductIdentifiers(EanList::fromStored('5900511374414'), BrandCodeList::fromStored('37441'));
        $duplicatePuzzle->changeNames('Puzzle 5', null, new PuzzleNames([new PuzzleName('Americké koblihy', 'cs')]), new DateTimeImmutable());
        $duplicatePuzzle->image = 'puzzles/duplicate-cover.jpg';
        $duplicatePuzzle->imageRatio = 1.4;
        $this->entityManager->flush();

        $mergeRequestId = $this->submitMergeRequest();

        // Reviewer supplies no identifiers at all - the merge must not drop them
        $this->messageBus->dispatch(
            new ApprovePuzzleMergeRequest(
                mergeRequestId: $mergeRequestId,
                reviewerId: PlayerFixture::PLAYER_ADMIN,
                survivorPuzzleId: PuzzleFixture::PUZZLE_500_04,
                mergedName: 'Survivor Name',
                mergedEans: null,
                mergedBrandCodes: null,
                mergedPiecesCount: 500,
                mergedManufacturerId: null,
                selectedImagePuzzleId: null,
            ),
        );

        $survivorPuzzle = $this->puzzleRepository->get(PuzzleFixture::PUZZLE_500_04);
        self::assertSame('5900511374414', $survivorPuzzle->ean, 'EAN known only to the deleted puzzle must be kept');
        self::assertSame('37441', $survivorPuzzle->identificationNumber);
        self::assertSame("\ne:5900511374414\nc:37441\nc:374414\n", $survivorPuzzle->searchCodes);
        self::assertSame('Americké koblihy', $survivorPuzzle->alternativeNames()->legacyAlternativeName());
        self::assertSame('puzzles/duplicate-cover.jpg', $survivorPuzzle->image);
        self::assertSame(1.4, $survivorPuzzle->imageRatio);
    }

    public function testMergeKeepsEveryNameAndBothProductCodesAndNeverOverwritesOtherSurvivorDetails(): void
    {
        $survivorPuzzle = $this->puzzleRepository->get(PuzzleFixture::PUZZLE_500_04);
        $survivorPuzzle->updateProductIdentifiers(EanList::fromStored('1234567890123'), BrandCodeList::fromStored('KEEP-ME'));
        $survivorPuzzle->changeNames('Puzzle 4', null, new PuzzleNames([new PuzzleName('Survivor Alternative', null)]), new DateTimeImmutable());
        $survivorPuzzle->image = 'puzzles/survivor-cover.jpg';

        $duplicatePuzzle = $this->puzzleRepository->get(PuzzleFixture::PUZZLE_500_05);
        $duplicatePuzzle->updateProductIdentifiers(EanList::fromStored('9999999999999'), BrandCodeList::fromStored('SECOND-EDITION'));
        $duplicatePuzzle->changeNames('Puzzle 5', null, new PuzzleNames([
            new PuzzleName('Duplicate Alternative', null),
            // The survivor's name again, accented and in Czech: one name, the tagged variant kept
            new PuzzleName('Survivor Alternativé', 'cs'),
        ]), new DateTimeImmutable());
        $duplicatePuzzle->image = 'puzzles/duplicate-cover.jpg';
        $this->entityManager->flush();

        $mergeRequestId = $this->submitMergeRequest();

        $this->messageBus->dispatch(
            new ApprovePuzzleMergeRequest(
                mergeRequestId: $mergeRequestId,
                reviewerId: PlayerFixture::PLAYER_ADMIN,
                survivorPuzzleId: PuzzleFixture::PUZZLE_500_04,
                mergedName: 'Survivor Name',
                mergedEans: null,
                mergedBrandCodes: null,
                mergedPiecesCount: 500,
                mergedManufacturerId: null,
                selectedImagePuzzleId: null,
            ),
        );

        $survivorPuzzle = $this->puzzleRepository->get(PuzzleFixture::PUZZLE_500_04);
        // One puzzle can carry several product codes (one per edition or region), so a
        // merge unions them - discarding either would lose a real product's identifier.
        self::assertSame('1234567890123, 9999999999999', $survivorPuzzle->ean);
        self::assertSame('KEEP-ME, SECOND-EDITION', $survivorPuzzle->identificationNumber);
        self::assertSame("\ne:1234567890123\ne:9999999999999\nc:keepme\nc:secondedition\n", $survivorPuzzle->searchCodes);
        // Every name of both puzzles stays findable: the survivor's own first, then the merged puzzle's other names,
        // then its main title, then the survivor's previous main title
        self::assertSame('Survivor Name', $survivorPuzzle->name);
        self::assertSame([
            ['name' => 'Survivor Alternativé', 'language' => 'cs'],
            ['name' => 'Duplicate Alternative', 'language' => null],
            ['name' => 'Puzzle 5', 'language' => null],
            ['name' => 'Puzzle 4', 'language' => null],
        ], $survivorPuzzle->alternativeNames);
        self::assertSame('Survivor Alternativé', $survivorPuzzle->alternativeNames()->legacyAlternativeName());
        self::assertSame(
            "\nsurvivor name\nsurvivor alternative\nduplicate alternative\npuzzle 5\npuzzle 4\n",
            $survivorPuzzle->searchNames,
        );
        self::assertNotNull($survivorPuzzle->namesChangedAt);
        // Everything else still belongs to the survivor alone
        self::assertSame('puzzles/survivor-cover.jpg', $survivorPuzzle->image);
    }

    public function testAMergedPuzzlesBoxNameComesBeforeItsMainTitle(): void
    {
        // A duplicate added under the English title with the Czech box name, merged into a survivor without names
        $duplicatePuzzle = $this->puzzleRepository->get(PuzzleFixture::PUZZLE_500_05);
        $duplicatePuzzle->changeNames('Magic Morning 1000', null, new PuzzleNames([new PuzzleName('Kouzelné ráno', null)]), new DateTimeImmutable());
        $this->entityManager->flush();

        $this->approveMerge($this->submitMergeRequest(), mergedName: 'Puzzle 4');

        $survivorPuzzle = $this->puzzleRepository->get(PuzzleFixture::PUZZLE_500_04);
        self::assertSame([
            ['name' => 'Kouzelné ráno', 'language' => null],
            ['name' => 'Magic Morning 1000', 'language' => null],
        ], $survivorPuzzle->alternativeNames);
        self::assertSame('Kouzelné ráno', $survivorPuzzle->alternativeNames()->legacyAlternativeName());
    }

    public function testAMainTitlePickedFromTheOtherNamesKeepsItsLanguage(): void
    {
        $duplicatePuzzle = $this->puzzleRepository->get(PuzzleFixture::PUZZLE_500_05);
        $duplicatePuzzle->changeNames('Puzzle 5', null, new PuzzleNames([new PuzzleName('Kouzelné ráno', 'cs')]), new DateTimeImmutable());
        $this->entityManager->flush();

        $this->approveMerge($this->submitMergeRequest(), mergedName: 'Kouzelné ráno');

        $survivorPuzzle = $this->puzzleRepository->get(PuzzleFixture::PUZZLE_500_04);
        self::assertSame('Kouzelné ráno', $survivorPuzzle->name);
        self::assertSame('cs', $survivorPuzzle->nameLanguage);
        self::assertSame([
            ['name' => 'Puzzle 5', 'language' => null],
            ['name' => 'Puzzle 4', 'language' => null],
        ], $survivorPuzzle->alternativeNames);
        self::assertSame("\nkouzelne rano\npuzzle 5\npuzzle 4\n", $survivorPuzzle->searchNames);
    }

    public function testMergeWithTheMergedPuzzlesTitleKeepsTheSurvivorsTitleAsAnOtherName(): void
    {
        $mergeRequestId = $this->submitMergeRequest();

        $this->messageBus->dispatch(
            new ApprovePuzzleMergeRequest(
                mergeRequestId: $mergeRequestId,
                reviewerId: PlayerFixture::PLAYER_ADMIN,
                survivorPuzzleId: PuzzleFixture::PUZZLE_500_04,
                mergedName: 'Puzzle 5',
                mergedEans: null,
                mergedBrandCodes: null,
                mergedPiecesCount: 500,
                mergedManufacturerId: null,
                selectedImagePuzzleId: null,
            ),
        );

        $survivorPuzzle = $this->puzzleRepository->get(PuzzleFixture::PUZZLE_500_04);
        self::assertSame('Puzzle 5', $survivorPuzzle->name);
        self::assertSame([['name' => 'Puzzle 4', 'language' => null]], $survivorPuzzle->alternativeNames);
        self::assertSame('Puzzle 4', $survivorPuzzle->alternativeNames()->legacyAlternativeName());
        self::assertSame("\npuzzle 5\npuzzle 4\n", $survivorPuzzle->searchNames);
    }

    public function testMergeKeepingTheSurvivorsTitleAddsTheMergedTitle(): void
    {
        $mergeRequestId = $this->submitMergeRequest();

        $this->messageBus->dispatch(
            new ApprovePuzzleMergeRequest(
                mergeRequestId: $mergeRequestId,
                reviewerId: PlayerFixture::PLAYER_ADMIN,
                survivorPuzzleId: PuzzleFixture::PUZZLE_500_04,
                mergedName: 'Puzzle 4',
                mergedEans: null,
                mergedBrandCodes: null,
                mergedPiecesCount: 500,
                mergedManufacturerId: null,
                selectedImagePuzzleId: null,
            ),
        );

        $survivorPuzzle = $this->puzzleRepository->get(PuzzleFixture::PUZZLE_500_04);
        self::assertSame('Puzzle 4', $survivorPuzzle->name);
        self::assertSame([['name' => 'Puzzle 5', 'language' => null]], $survivorPuzzle->alternativeNames);
        self::assertSame("\npuzzle 4\npuzzle 5\n", $survivorPuzzle->searchNames);
    }

    public function testMergeTakesTheReviewersNamesAsTheyAre(): void
    {
        $duplicatePuzzle = $this->puzzleRepository->get(PuzzleFixture::PUZZLE_500_05);
        $duplicatePuzzle->changeNames('Kouzelné ráno', null, new PuzzleNames([new PuzzleName('Magischer Morgen', 'de')]), new DateTimeImmutable());
        $this->entityManager->flush();

        $mergeRequestId = $this->submitMergeRequest();

        // The reviewer picked a new English main title, tagged the Czech title and removed the German one
        $this->messageBus->dispatch(new ApprovePuzzleMergeRequest(
            mergeRequestId: $mergeRequestId,
            reviewerId: PlayerFixture::PLAYER_ADMIN,
            survivorPuzzleId: PuzzleFixture::PUZZLE_500_04,
            mergedName: 'Magic Morning',
            mergedEans: null,
            mergedBrandCodes: null,
            mergedPiecesCount: 500,
            mergedManufacturerId: null,
            selectedImagePuzzleId: null,
            mergedNameLanguage: null,
            mergedAlternativeNames: new PuzzleNames([
                new PuzzleName('Kouzelné ráno', 'cs'),
                new PuzzleName('Puzzle 4', null),
            ]),
        ));

        $survivorPuzzle = $this->puzzleRepository->get(PuzzleFixture::PUZZLE_500_04);
        self::assertSame('Magic Morning', $survivorPuzzle->name);
        self::assertNull($survivorPuzzle->nameLanguage);
        self::assertSame([
            ['name' => 'Kouzelné ráno', 'language' => 'cs'],
            ['name' => 'Puzzle 4', 'language' => null],
        ], $survivorPuzzle->alternativeNames);
        self::assertSame("\nmagic morning\nkouzelne rano\npuzzle 4\n", $survivorPuzzle->searchNames);

        $audit = $this->entityManager->getRepository(PuzzleMergeAudit::class)->findOneBy(['mergeRequestId' => $mergeRequestId]);
        self::assertNotNull($audit);
        $survivorAfter = $audit->snapshotAfter['survivorPuzzle'];
        self::assertIsArray($survivorAfter);
        self::assertSame($survivorPuzzle->alternativeNames, $survivorAfter['alternativeNames']);
    }

    public function testMergeWithoutTheReviewersNamesTagsTheMainTitlesInTheReportersLanguages(): void
    {
        $duplicatePuzzle = $this->puzzleRepository->get(PuzzleFixture::PUZZLE_500_05);
        $duplicatePuzzle->changeNames('Kouzelné ráno', null, new PuzzleNames(), new DateTimeImmutable());
        $this->entityManager->flush();

        $mergeRequestId = $this->submitMergeRequest([PuzzleFixture::PUZZLE_500_05 => 'cs']);

        $this->approveMerge($mergeRequestId, mergedName: 'Puzzle 4');

        $survivorPuzzle = $this->puzzleRepository->get(PuzzleFixture::PUZZLE_500_04);
        self::assertSame('Puzzle 4', $survivorPuzzle->name);
        self::assertSame([['name' => 'Kouzelné ráno', 'language' => 'cs']], $survivorPuzzle->alternativeNames);

        $audit = $this->entityManager->getRepository(PuzzleMergeAudit::class)->findOneBy(['mergeRequestId' => $mergeRequestId]);
        self::assertNotNull($audit);
        self::assertSame([PuzzleFixture::PUZZLE_500_05 => 'cs'], $audit->snapshotBefore['reportedNameLanguages']);
    }

    public function testTheReportersLanguageTagsTheMainTitleTheReviewerKeeps(): void
    {
        $duplicatePuzzle = $this->puzzleRepository->get(PuzzleFixture::PUZZLE_500_05);
        $duplicatePuzzle->changeNames('Kouzelné ráno', null, new PuzzleNames(), new DateTimeImmutable());
        $this->entityManager->flush();

        $this->approveMerge($this->submitMergeRequest([PuzzleFixture::PUZZLE_500_05 => 'cs']), mergedName: 'Kouzelné ráno');

        $survivorPuzzle = $this->puzzleRepository->get(PuzzleFixture::PUZZLE_500_04);
        self::assertSame('Kouzelné ráno', $survivorPuzzle->name);
        self::assertSame('cs', $survivorPuzzle->nameLanguage);
        self::assertSame([['name' => 'Puzzle 4', 'language' => null]], $survivorPuzzle->alternativeNames);
    }

    public function testWithTheReviewersNamesButNoLanguageTheMainTitleKeepsTheLanguageThePuzzlesKnow(): void
    {
        $duplicatePuzzle = $this->puzzleRepository->get(PuzzleFixture::PUZZLE_500_05);
        $duplicatePuzzle->changeNames('Kouzelné ráno', null, new PuzzleNames(), new DateTimeImmutable());
        $this->entityManager->flush();

        // The internal API without mergedNameLanguage
        $this->messageBus->dispatch(new ApprovePuzzleMergeRequest(
            mergeRequestId: $this->submitMergeRequest([PuzzleFixture::PUZZLE_500_05 => 'cs']),
            reviewerId: PlayerFixture::PLAYER_ADMIN,
            survivorPuzzleId: PuzzleFixture::PUZZLE_500_04,
            mergedName: 'Kouzelné ráno',
            mergedEans: null,
            mergedBrandCodes: null,
            mergedPiecesCount: 500,
            mergedManufacturerId: null,
            selectedImagePuzzleId: null,
            mergedAlternativeNames: new PuzzleNames([new PuzzleName('Puzzle 4', null)]),
        ));

        $survivorPuzzle = $this->puzzleRepository->get(PuzzleFixture::PUZZLE_500_04);
        self::assertSame('Kouzelné ráno', $survivorPuzzle->name);
        self::assertSame('cs', $survivorPuzzle->nameLanguage);
    }

    public function testAnExplicitlyEmptyLanguageIsHonouredWithoutTheReviewersNames(): void
    {
        $duplicatePuzzle = $this->puzzleRepository->get(PuzzleFixture::PUZZLE_500_05);
        $duplicatePuzzle->changeNames('Kouzelné ráno', null, new PuzzleNames(), new DateTimeImmutable());
        $this->entityManager->flush();

        // The reporter said Czech - the reviewer says the main title is English (or not known)
        $this->messageBus->dispatch(new ApprovePuzzleMergeRequest(
            mergeRequestId: $this->submitMergeRequest([PuzzleFixture::PUZZLE_500_05 => 'cs']),
            reviewerId: PlayerFixture::PLAYER_ADMIN,
            survivorPuzzleId: PuzzleFixture::PUZZLE_500_04,
            mergedName: 'Kouzelné ráno',
            mergedEans: null,
            mergedBrandCodes: null,
            mergedPiecesCount: 500,
            mergedManufacturerId: null,
            selectedImagePuzzleId: null,
            mergedNameLanguage: null,
        ));

        $survivorPuzzle = $this->puzzleRepository->get(PuzzleFixture::PUZZLE_500_04);
        self::assertSame('Kouzelné ráno', $survivorPuzzle->name);
        self::assertNull($survivorPuzzle->nameLanguage);
        self::assertSame([['name' => 'Puzzle 4', 'language' => null]], $survivorPuzzle->alternativeNames);
    }

    public function testAPuzzleSavedAfterTheReviewWasLoadedRefusesTheMerge(): void
    {
        $survivorVersion = PuzzleRecordVersion::ofPuzzle($this->puzzleRepository->get(PuzzleFixture::PUZZLE_500_04));
        $duplicatePuzzle = $this->puzzleRepository->get(PuzzleFixture::PUZZLE_500_05);
        $loadedVersion = PuzzleRecordVersion::ofPuzzle($duplicatePuzzle);
        $mergeRequestId = $this->submitMergeRequest();

        // Somebody saves the duplicate while the review is open
        $duplicatePuzzle->changeNames('Puzzle 5', null, new PuzzleNames([new PuzzleName('Saved meanwhile', 'de')]), new DateTimeImmutable());
        $this->entityManager->flush();

        try {
            $this->messageBus->dispatch(new ApprovePuzzleMergeRequest(
                mergeRequestId: $mergeRequestId,
                reviewerId: PlayerFixture::PLAYER_ADMIN,
                survivorPuzzleId: PuzzleFixture::PUZZLE_500_04,
                mergedName: 'Puzzle 4',
                mergedEans: null,
                mergedBrandCodes: null,
                mergedPiecesCount: 500,
                mergedManufacturerId: null,
                selectedImagePuzzleId: null,
                recordVersions: [
                    PuzzleFixture::PUZZLE_500_04 => $survivorVersion,
                    PuzzleFixture::PUZZLE_500_05 => $loadedVersion,
                ],
            ));
            self::fail('A stale review must not merge');
        } catch (PuzzleChangedMeanwhile) {
        }

        $this->entityManager->clear();
        self::assertSame(PuzzleReportStatus::Pending, $this->mergeRequestRepository->get($mergeRequestId)->status);
        self::assertSame('Puzzle 5', $this->puzzleRepository->get(PuzzleFixture::PUZZLE_500_05)->name);
    }

    public function testEveryPuzzleOfTheMergeIsLockedBeforeTheRecordsAreCompared(): void
    {
        $mergeRequestId = $this->submitMergeRequest();

        // Another request holds the duplicate's row - a moderator's edit of it, not committed yet
        $otherRequest = $this->otherDatabaseConnection();
        $otherRequest->beginTransaction();
        $otherRequest->query(sprintf("SELECT id FROM puzzle WHERE id = '%s' FOR UPDATE", PuzzleFixture::PUZZLE_500_05));

        // The merge waits for it; here the wait is cut short (until the end of the test's transaction)
        $this->entityManager->getConnection()->executeStatement("SET LOCAL lock_timeout = '200ms'");

        $failedStatement = null;

        try {
            $this->approveMerge($mergeRequestId, mergedName: 'Puzzle 4');
        } catch (Throwable $exception) {
            for ($cause = $exception; $cause !== null; $cause = $cause->getPrevious()) {
                if ($cause instanceof DriverException && $cause->getSQLState() === '55P03') {
                    $failedStatement = $cause->getQuery()?->getSQL();
                }
            }
        } finally {
            $otherRequest->rollBack();
        }

        // lock_not_available on the locking read at the start - not on the DELETE at the end, after the record
        // versions were compared against a row somebody was changing
        self::assertIsString($failedStatement, 'The merge must wait for the duplicate\'s row');
        self::assertStringStartsWith('SELECT', $failedStatement);
        self::assertStringContainsString('FOR UPDATE', $failedStatement);
    }

    public function testAnUpperCaseSurvivorIdKeepsTheSurvivor(): void
    {
        $mergeRequestId = $this->submitMergeRequest();

        $this->messageBus->dispatch(new ApprovePuzzleMergeRequest(
            mergeRequestId: $mergeRequestId,
            reviewerId: PlayerFixture::PLAYER_ADMIN,
            survivorPuzzleId: strtoupper(PuzzleFixture::PUZZLE_500_04),
            mergedName: 'Puzzle 4',
            mergedEans: null,
            mergedBrandCodes: null,
            mergedPiecesCount: 500,
            mergedManufacturerId: null,
            selectedImagePuzzleId: strtoupper(PuzzleFixture::PUZZLE_500_04),
        ));

        $this->entityManager->clear();
        self::assertSame('Puzzle 4', $this->puzzleRepository->get(PuzzleFixture::PUZZLE_500_04)->name);

        $this->expectException(PuzzleNotFound::class);
        $this->puzzleRepository->get(PuzzleFixture::PUZZLE_500_05);
    }

    public function testASurvivorOutsideTheRequestIsRefused(): void
    {
        $mergeRequestId = $this->submitMergeRequest();

        try {
            $this->messageBus->dispatch(new ApprovePuzzleMergeRequest(
                mergeRequestId: $mergeRequestId,
                reviewerId: PlayerFixture::PLAYER_ADMIN,
                survivorPuzzleId: PuzzleFixture::PUZZLE_1000_01,
                mergedName: 'Puzzle 4',
                mergedEans: null,
                mergedBrandCodes: null,
                mergedPiecesCount: 500,
                mergedManufacturerId: null,
                selectedImagePuzzleId: null,
            ));
            self::fail('Every reported puzzle would have been merged into a puzzle outside the request');
        } catch (InvalidPuzzleValues) {
        }

        $this->entityManager->clear();
        self::assertSame(PuzzleReportStatus::Pending, $this->mergeRequestRepository->get($mergeRequestId)->status);
        $this->puzzleRepository->get(PuzzleFixture::PUZZLE_500_04);
        $this->puzzleRepository->get(PuzzleFixture::PUZZLE_500_05);
    }

    public function testTheReviewersNamesMayNotGrowPastTheFormLimit(): void
    {
        $mergeRequestId = $this->submitMergeRequest();

        $this->expectException(InvalidPuzzleValues::class);

        $this->messageBus->dispatch(new ApprovePuzzleMergeRequest(
            mergeRequestId: $mergeRequestId,
            reviewerId: PlayerFixture::PLAYER_ADMIN,
            survivorPuzzleId: PuzzleFixture::PUZZLE_500_04,
            mergedName: 'Puzzle 4',
            mergedEans: null,
            mergedBrandCodes: null,
            mergedPiecesCount: 500,
            mergedManufacturerId: null,
            selectedImagePuzzleId: null,
            mergedAlternativeNames: new PuzzleNames(array_map(
                static fn (int $number): PuzzleName => new PuzzleName('Name ' . $number, null),
                range(1, PuzzleNames::FORM_MAX_NAMES + 1),
            )),
        ));
    }

    /**
     * A connection of its own to this test process's database (tests/bootstrap.php gives every ParaTest worker one) -
     * another request, outside the test's transaction
     */
    private function otherDatabaseConnection(): PDO
    {
        $databaseUrl = $_ENV['DATABASE_URL'] ?? null;
        self::assertIsString($databaseUrl);
        $url = parse_url($databaseUrl);
        self::assertIsArray($url);

        return new PDO(
            sprintf('pgsql:host=%s;port=%d;dbname=%s', $url['host'] ?? 'postgres', $url['port'] ?? 5432, ltrim($url['path'] ?? '', '/')),
            $url['user'] ?? null,
            $url['pass'] ?? null,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        );
    }

    private function approveMerge(string $mergeRequestId, string $mergedName): void
    {
        $this->messageBus->dispatch(
            new ApprovePuzzleMergeRequest(
                mergeRequestId: $mergeRequestId,
                reviewerId: PlayerFixture::PLAYER_ADMIN,
                survivorPuzzleId: PuzzleFixture::PUZZLE_500_04,
                mergedName: $mergedName,
                mergedEans: null,
                mergedBrandCodes: null,
                mergedPiecesCount: 500,
                mergedManufacturerId: null,
                selectedImagePuzzleId: null,
            ),
        );
    }

    /**
     * @param array<string, string> $reportedNameLanguages
     */
    private function submitMergeRequest(array $reportedNameLanguages = []): string
    {
        $mergeRequestId = Uuid::uuid7()->toString();

        $this->messageBus->dispatch(
            new SubmitPuzzleMergeRequest(
                mergeRequestId: $mergeRequestId,
                sourcePuzzleId: PuzzleFixture::PUZZLE_500_04,
                reporterId: PlayerFixture::PLAYER_REGULAR,
                duplicatePuzzleIds: [
                    PuzzleFixture::PUZZLE_500_05,
                ],
                reportedNameLanguages: $reportedNameLanguages,
            ),
        );

        return $mergeRequestId;
    }

    /**
     * Regression: competition rounds, marketplace conversations, stopwatches and tags
     * all point at the puzzle. The first three are blocking foreign keys - leaving any
     * of them behind aborts the whole merge when the merged puzzle is deleted, which is
     * exactly what happened in production. Stopwatches and tags cascade instead, which
     * is worse: the merge succeeds and silently destroys them.
     */
    public function testMergeMovesCompetitionRoundsConversationsStopwatchesAndTags(): void
    {
        $survivorPuzzle = $this->puzzleRepository->get(PuzzleFixture::PUZZLE_500_04);
        $duplicatePuzzle = $this->puzzleRepository->get(PuzzleFixture::PUZZLE_500_05);
        $round = $this->entityManager->find(CompetitionRound::class, CompetitionRoundFixture::ROUND_CZECH_FINAL);
        self::assertNotNull($round);

        $roundPuzzle = new CompetitionRoundPuzzle(
            id: Uuid::uuid7(),
            round: $round,
            puzzle: $duplicatePuzzle,
        );
        $this->entityManager->persist($roundPuzzle);

        $stopwatch = $this->entityManager->getRepository(Stopwatch::class)->findOneBy([]);
        self::assertNotNull($stopwatch, 'Fixture should provide a stopwatch to re-point');
        $stopwatch->puzzle = $duplicatePuzzle;

        $tag = new Tag(id: Uuid::uuid7(), name: 'merge-test-tag');
        $tag->puzzles->add($duplicatePuzzle);
        $this->entityManager->persist($tag);
        $this->entityManager->flush();

        $roundPuzzleId = $roundPuzzle->id->toString();
        $stopwatchId = $stopwatch->id->toString();

        $mergeRequestId = $this->submitMergeRequest();

        $this->messageBus->dispatch(
            new ApprovePuzzleMergeRequest(
                mergeRequestId: $mergeRequestId,
                reviewerId: PlayerFixture::PLAYER_ADMIN,
                survivorPuzzleId: PuzzleFixture::PUZZLE_500_04,
                mergedName: 'Survivor Name',
                mergedEans: null,
                mergedBrandCodes: null,
                mergedPiecesCount: 500,
                mergedManufacturerId: null,
                selectedImagePuzzleId: null,
            ),
        );

        // The merge completed at all - before the fix this threw a foreign key violation
        $this->entityManager->clear();

        $movedRoundPuzzle = $this->entityManager->find(CompetitionRoundPuzzle::class, $roundPuzzleId);
        self::assertNotNull($movedRoundPuzzle);
        self::assertSame(PuzzleFixture::PUZZLE_500_04, $movedRoundPuzzle->puzzle->id->toString());

        $movedStopwatch = $this->entityManager->find(Stopwatch::class, $stopwatchId);
        self::assertNotNull($movedStopwatch, 'Stopwatch must survive the merge, not cascade away with the puzzle');
        self::assertNotNull($movedStopwatch->puzzle);
        self::assertSame(PuzzleFixture::PUZZLE_500_04, $movedStopwatch->puzzle->id->toString());

        $movedTag = $this->entityManager->getRepository(Tag::class)->findOneBy(['name' => 'merge-test-tag']);
        self::assertNotNull($movedTag, 'Tag must survive the merge');
        $taggedIds = array_map(static fn($p): string => $p->id->toString(), $movedTag->puzzles->toArray());
        self::assertContains(PuzzleFixture::PUZZLE_500_04, $taggedIds);

        $survivorPuzzle = $this->puzzleRepository->get(PuzzleFixture::PUZZLE_500_04);
        self::assertSame('Survivor Name', $survivorPuzzle->name);
    }

    /**
     * A puzzle that already lists two EANs must not come out of a merge with one.
     * Reducing the list loses a code that identifies a real edition - and the record
     * that carried it is deleted moments later.
     */
    public function testMergePreservesAnExistingMultiCodeListAndAddsTheNewOne(): void
    {
        $survivorPuzzle = $this->puzzleRepository->get(PuzzleFixture::PUZZLE_500_04);
        $survivorPuzzle->updateProductIdentifiers(
            EanList::fromStored('4005556147090, 4005555001997'),
            BrandCodeList::fromStored('14709, 12000199'),
        );

        $duplicatePuzzle = $this->puzzleRepository->get(PuzzleFixture::PUZZLE_500_05);
        $duplicatePuzzle->updateProductIdentifiers(EanList::fromStored('4005555012740'), BrandCodeList::fromStored('12001274'));
        $this->entityManager->flush();

        $mergeRequestId = $this->submitMergeRequest();

        $this->messageBus->dispatch(
            new ApprovePuzzleMergeRequest(
                mergeRequestId: $mergeRequestId,
                reviewerId: PlayerFixture::PLAYER_ADMIN,
                survivorPuzzleId: PuzzleFixture::PUZZLE_500_04,
                mergedName: 'Survivor Name',
                mergedEans: null,
                mergedBrandCodes: null,
                mergedPiecesCount: 500,
                mergedManufacturerId: null,
                selectedImagePuzzleId: null,
            ),
        );

        $survivorPuzzle = $this->puzzleRepository->get(PuzzleFixture::PUZZLE_500_04);
        self::assertSame('4005556147090, 4005555001997, 4005555012740', $survivorPuzzle->ean);
        self::assertSame('14709, 12000199, 12001274', $survivorPuzzle->identificationNumber);
    }

    public function testMergeDoesNotRepeatACodeBothPuzzlesAlreadyShare(): void
    {
        $survivorPuzzle = $this->puzzleRepository->get(PuzzleFixture::PUZZLE_500_04);
        $survivorPuzzle->updateProductIdentifiers(EanList::fromStored('4005556147564, 4005555002017'), BrandCodeList::fromStored(null));

        $duplicatePuzzle = $this->puzzleRepository->get(PuzzleFixture::PUZZLE_500_05);
        $duplicatePuzzle->updateProductIdentifiers(EanList::fromStored('4005556147564'), BrandCodeList::fromStored(null));
        $this->entityManager->flush();

        $mergeRequestId = $this->submitMergeRequest();

        $this->messageBus->dispatch(
            new ApprovePuzzleMergeRequest(
                mergeRequestId: $mergeRequestId,
                reviewerId: PlayerFixture::PLAYER_ADMIN,
                survivorPuzzleId: PuzzleFixture::PUZZLE_500_04,
                mergedName: 'Survivor Name',
                mergedEans: null,
                mergedBrandCodes: null,
                mergedPiecesCount: 500,
                mergedManufacturerId: null,
                selectedImagePuzzleId: null,
            ),
        );

        $survivorPuzzle = $this->puzzleRepository->get(PuzzleFixture::PUZZLE_500_04);
        self::assertSame('4005556147564, 4005555002017', $survivorPuzzle->ean);
    }

    /**
     * Regression: a returned lend leaves its transfer history behind on the puzzle.
     * Loading those transfers as entities (to list them in the audit trail) kept them
     * pointing at the merged puzzle while the database moved on, so the flush after the
     * puzzle was deleted found the deleted puzzle again and rolled the whole merge back.
     */
    public function testMergeMovesLendingHistoryOfAlreadyReturnedPuzzles(): void
    {
        $duplicatePuzzle = $this->puzzleRepository->get(PuzzleFixture::PUZZLE_500_05);
        $owner = $this->entityManager->find(Player::class, PlayerFixture::PLAYER_REGULAR);
        self::assertNotNull($owner);

        $transferId = Uuid::uuid7();
        $this->entityManager->persist(new LentPuzzleTransfer(
            id: $transferId,
            lentPuzzle: null,
            fromPlayer: null,
            fromPlayerName: 'Borrower',
            toPlayer: $owner,
            toPlayerName: null,
            transferredAt: new DateTimeImmutable('-10 days'),
            transferType: TransferType::Return,
            puzzle: $duplicatePuzzle,
            ownerPlayer: $owner,
        ));
        $this->entityManager->flush();
        $this->entityManager->clear();

        $mergeRequestId = $this->submitMergeRequest();

        $this->messageBus->dispatch(
            new ApprovePuzzleMergeRequest(
                mergeRequestId: $mergeRequestId,
                reviewerId: PlayerFixture::PLAYER_ADMIN,
                survivorPuzzleId: PuzzleFixture::PUZZLE_500_04,
                mergedName: 'Survivor Name',
                mergedEans: null,
                mergedBrandCodes: null,
                mergedPiecesCount: 500,
                mergedManufacturerId: null,
                selectedImagePuzzleId: null,
            ),
        );

        $this->entityManager->clear();

        self::assertSame(PuzzleReportStatus::Approved, $this->mergeRequestRepository->get($mergeRequestId)->status);

        $movedTransfer = $this->entityManager->find(LentPuzzleTransfer::class, $transferId);
        self::assertNotNull($movedTransfer);
        self::assertNotNull($movedTransfer->puzzle);
        self::assertSame(PuzzleFixture::PUZZLE_500_04, $movedTransfer->puzzle->id->toString());

        $audit = $this->entityManager->getRepository(PuzzleMergeAudit::class)->findOneBy(['mergeRequestId' => $mergeRequestId]);
        self::assertNotNull($audit);
        $migrated = $audit->snapshotBefore['migrated'];
        self::assertIsArray($migrated);
        self::assertIsArray($migrated['lentPuzzleTransfers']);
        self::assertContains($transferId->toString(), $migrated['lentPuzzleTransfers']);
    }

    public function testSurvivorInheritsApprovalAndTheDecisionIsLoggedWithoutSelfNotification(): void
    {
        $mergeRequestId = Uuid::uuid7()->toString();

        // A moderator merging a new puzzle from the approval queue files the request themselves
        $this->messageBus->dispatch(new SubmitPuzzleMergeRequest(
            mergeRequestId: $mergeRequestId,
            sourcePuzzleId: PuzzleFixture::PUZZLE_UNAPPROVED,
            reporterId: PlayerFixture::PLAYER_ADMIN,
            duplicatePuzzleIds: [PuzzleFixture::PUZZLE_1000_01],
        ));

        // The unapproved puzzle survives (e.g. it has more solving times)
        $this->messageBus->dispatch(new ApprovePuzzleMergeRequest(
            mergeRequestId: $mergeRequestId,
            reviewerId: PlayerFixture::PLAYER_ADMIN,
            survivorPuzzleId: PuzzleFixture::PUZZLE_UNAPPROVED,
            mergedName: 'Merged',
            mergedEans: null,
            mergedBrandCodes: null,
            mergedPiecesCount: 1000,
            mergedManufacturerId: ManufacturerFixture::MANUFACTURER_RAVENSBURGER,
            selectedImagePuzzleId: null,
        ));

        $this->entityManager->clear();

        $survivor = $this->puzzleRepository->get(PuzzleFixture::PUZZLE_UNAPPROVED);
        self::assertTrue($survivor->approved, 'An approved puzzle merged in keeps the result in the catalogue');
        self::assertSame(PlayerFixture::PLAYER_ADMIN, $survivor->approvedBy?->id->toString());

        $decisions = $this->entityManager->getRepository(PuzzleModerationDecision::class)
            ->findBy(['mergeRequestId' => Uuid::fromString($mergeRequestId)]);
        self::assertCount(1, $decisions);
        self::assertSame(PuzzleModerationAction::MergeRequestApproved, $decisions[0]->action);
        self::assertSame(PlayerFixture::PLAYER_ADMIN, $decisions[0]->decidedById->toString());
        self::assertSame([PuzzleFixture::PUZZLE_1000_01], $decisions[0]->details['mergedPuzzleIds'] ?? null);

        $selfNotifications = $this->entityManager->getRepository(Notification::class)->findBy([
            'type' => NotificationType::PuzzleMergeRequestApproved,
            'targetMergeRequest' => Uuid::fromString($mergeRequestId),
        ]);
        self::assertSame([], $selfNotifications);
    }
}
