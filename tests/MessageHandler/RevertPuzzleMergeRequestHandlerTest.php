<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\PuzzleMergeAudit;
use SpeedPuzzling\Web\Entity\PuzzleModerationDecision;
use SpeedPuzzling\Web\Entity\PuzzleSolvingTime;
use SpeedPuzzling\Web\Entity\SellSwapListItem;
use SpeedPuzzling\Web\Entity\SoldSwappedItem;
use SpeedPuzzling\Web\Entity\WishListItem;
use SpeedPuzzling\Web\Exceptions\InvalidPuzzleValues;
use SpeedPuzzling\Web\Message\ApprovePuzzleMergeRequest;
use SpeedPuzzling\Web\Message\RevertPuzzleMergeRequest;
use SpeedPuzzling\Web\Message\SubmitPuzzleMergeRequest;
use SpeedPuzzling\Web\Repository\PuzzleMergeRequestRepository;
use SpeedPuzzling\Web\Repository\PuzzleRedirectRepository;
use SpeedPuzzling\Web\Repository\PuzzleRepository;
use SpeedPuzzling\Web\Repository\PuzzleStatisticsRepository;
use SpeedPuzzling\Web\Tests\DataFixtures\ManufacturerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Tests\TestClock;
use SpeedPuzzling\Web\Value\PuzzleModerationAction;
use SpeedPuzzling\Web\Value\PuzzleNames;
use SpeedPuzzling\Web\Value\PuzzleReportStatus;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;

final class RevertPuzzleMergeRequestHandlerTest extends KernelTestCase
{
    private MessageBusInterface $messageBus;
    private PuzzleMergeRequestRepository $mergeRequestRepository;
    private PuzzleRepository $puzzleRepository;
    private PuzzleRedirectRepository $puzzleRedirectRepository;
    private PuzzleStatisticsRepository $statisticsRepository;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->messageBus = $container->get(MessageBusInterface::class);
        $this->mergeRequestRepository = $container->get(PuzzleMergeRequestRepository::class);
        $this->puzzleRepository = $container->get(PuzzleRepository::class);
        $this->puzzleRedirectRepository = $container->get(PuzzleRedirectRepository::class);
        $this->statisticsRepository = $container->get(PuzzleStatisticsRepository::class);
        $this->entityManager = $container->get(EntityManagerInterface::class);
    }

    public function testRevertRestoresTheMergedPuzzleAndMovesItsRecordsBack(): void
    {
        $survivorBefore = $this->puzzleRepository->get(PuzzleFixture::PUZZLE_500_04);
        $survivorName = $survivorBefore->name;
        $survivorEan = $survivorBefore->ean;
        $duplicate = $this->puzzleRepository->get(PuzzleFixture::PUZZLE_500_05);
        $duplicateName = $duplicate->name;
        $duplicateEan = $duplicate->ean;
        $duplicateManufacturerId = $duplicate->manufacturer?->id->toString();
        $timeIds = $this->idsOn(PuzzleSolvingTime::class, $duplicate);
        $soldIds = $this->idsOn(SoldSwappedItem::class, $duplicate);
        self::assertCount(2, $timeIds);

        $mergeRequestId = $this->merge('Merged Name');
        $this->entityManager->clear();

        // Moved by the merge (not dropped as a duplicate of the survivor's own row) - what the revert moves back
        $audit = $this->entityManager->getRepository(PuzzleMergeAudit::class)->findOneBy(['mergeRequestId' => Uuid::fromString($mergeRequestId)]);
        self::assertNotNull($audit);
        /** @var array{sellSwapListItems: array{moved: list<string>}, wishListItems: array{moved: list<string>}} $migrated */
        $migrated = $audit->snapshotBefore['migrated'];

        $this->messageBus->dispatch(new RevertPuzzleMergeRequest(
            mergeRequestId: $mergeRequestId,
            adminId: PlayerFixture::PLAYER_ADMIN,
            survivorPuzzleId: PuzzleFixture::PUZZLE_500_04,
            note: 'Two different boxes',
        ));
        $this->entityManager->clear();

        // The merged puzzle is back under its own id, as it was
        $restored = $this->puzzleRepository->get(PuzzleFixture::PUZZLE_500_05);
        self::assertSame($duplicateName, $restored->name);
        self::assertSame($duplicateEan, $restored->ean);
        self::assertSame($duplicateManufacturerId, $restored->manufacturer?->id->toString());
        self::assertNull($this->puzzleRedirectRepository->findByOldPuzzleId(PuzzleFixture::PUZZLE_500_05));

        // Its records are on it again
        self::assertEqualsCanonicalizing($timeIds, $this->idsOn(PuzzleSolvingTime::class, $restored));
        // The fixtures' wishlist and sell/swap items were dropped as duplicates of the same player's rows on the
        // survivor - gone for good
        self::assertEqualsCanonicalizing($migrated['wishListItems']['moved'], $this->idsOn(WishListItem::class, $restored));
        self::assertEqualsCanonicalizing($migrated['sellSwapListItems']['moved'], $this->idsOn(SellSwapListItem::class, $restored));
        self::assertEqualsCanonicalizing($soldIds, $this->idsOn(SoldSwappedItem::class, $restored));

        // The survivor is what it was before the merge, its statistics without the moved results
        $survivor = $this->puzzleRepository->get(PuzzleFixture::PUZZLE_500_04);
        self::assertSame($survivorName, $survivor->name);
        self::assertSame($survivorEan, $survivor->ean);
        self::assertSame(
            count($this->idsOn(PuzzleSolvingTime::class, $survivor)),
            $this->statisticsRepository->findByPuzzleId($survivor->id)->solvedTimesCount ?? 0,
        );
        self::assertSame(2, $this->statisticsRepository->findByPuzzleId($restored->id)?->solvedTimesCount);

        $mergeRequest = $this->mergeRequestRepository->get($mergeRequestId);
        self::assertSame(PuzzleReportStatus::Reverted, $mergeRequest->status);
        self::assertNotNull($mergeRequest->revertedAt);
        self::assertSame(PlayerFixture::PLAYER_ADMIN, $mergeRequest->revertedBy?->id->toString());

        $decision = $this->entityManager->getRepository(PuzzleModerationDecision::class)->findOneBy([
            'mergeRequestId' => $mergeRequest->id,
            'action' => PuzzleModerationAction::MergeRequestReverted,
        ]);
        self::assertNotNull($decision);
        self::assertSame('Two different boxes', $decision->note);
    }

    public function testRevertKeepsWhatWasChangedOnTheSurvivorAfterTheMerge(): void
    {
        $mergeRequestId = $this->merge('Merged Name');
        $this->entityManager->clear();

        // A moderator renames the survivor after the merge - the revert must not undo that
        $survivor = $this->puzzleRepository->get(PuzzleFixture::PUZZLE_500_04);
        $survivor->changeNames('Renamed Later', null, new PuzzleNames(), TestClock::now());
        $this->entityManager->flush();
        $this->entityManager->clear();

        $this->messageBus->dispatch(new RevertPuzzleMergeRequest(
            mergeRequestId: $mergeRequestId,
            adminId: PlayerFixture::PLAYER_ADMIN,
            survivorPuzzleId: PuzzleFixture::PUZZLE_500_04,
        ));
        $this->entityManager->clear();

        self::assertSame('Renamed Later', $this->puzzleRepository->get(PuzzleFixture::PUZZLE_500_04)->name);
        $this->puzzleRepository->get(PuzzleFixture::PUZZLE_500_05);

        $decision = $this->entityManager->getRepository(PuzzleModerationDecision::class)->findOneBy([
            'mergeRequestId' => Uuid::fromString($mergeRequestId),
            'action' => PuzzleModerationAction::MergeRequestReverted,
        ]);
        self::assertNotNull($decision);
        self::assertSame(['names'], $decision->details['keptOnSurvivor'] ?? null);
    }

    public function testRevertingTwiceIsRefused(): void
    {
        $mergeRequestId = $this->merge('Merged Name');

        $this->messageBus->dispatch(new RevertPuzzleMergeRequest(
            mergeRequestId: $mergeRequestId,
            adminId: PlayerFixture::PLAYER_ADMIN,
            survivorPuzzleId: PuzzleFixture::PUZZLE_500_04,
        ));

        $this->expectException(InvalidPuzzleValues::class);

        $this->messageBus->dispatch(new RevertPuzzleMergeRequest(
            mergeRequestId: $mergeRequestId,
            adminId: PlayerFixture::PLAYER_ADMIN,
            survivorPuzzleId: PuzzleFixture::PUZZLE_500_04,
        ));
    }

    public function testPendingRequestCannotBeReverted(): void
    {
        $mergeRequestId = Uuid::uuid7()->toString();
        $this->messageBus->dispatch(new SubmitPuzzleMergeRequest(
            mergeRequestId: $mergeRequestId,
            sourcePuzzleId: PuzzleFixture::PUZZLE_500_04,
            reporterId: PlayerFixture::PLAYER_REGULAR,
            duplicatePuzzleIds: [PuzzleFixture::PUZZLE_500_05],
        ));

        $this->expectException(InvalidPuzzleValues::class);

        $this->messageBus->dispatch(new RevertPuzzleMergeRequest(
            mergeRequestId: $mergeRequestId,
            adminId: PlayerFixture::PLAYER_ADMIN,
            survivorPuzzleId: PuzzleFixture::PUZZLE_500_04,
        ));
    }

    private function merge(string $mergedName): string
    {
        $mergeRequestId = Uuid::uuid7()->toString();

        $this->messageBus->dispatch(new SubmitPuzzleMergeRequest(
            mergeRequestId: $mergeRequestId,
            sourcePuzzleId: PuzzleFixture::PUZZLE_500_04,
            reporterId: PlayerFixture::PLAYER_REGULAR,
            duplicatePuzzleIds: [PuzzleFixture::PUZZLE_500_05],
        ));

        $this->messageBus->dispatch(new ApprovePuzzleMergeRequest(
            mergeRequestId: $mergeRequestId,
            reviewerId: PlayerFixture::PLAYER_ADMIN,
            survivorPuzzleId: PuzzleFixture::PUZZLE_500_04,
            mergedName: $mergedName,
            mergedEans: null,
            mergedBrandCodes: null,
            mergedPiecesCount: 500,
            mergedManufacturerId: ManufacturerFixture::MANUFACTURER_RAVENSBURGER,
            selectedImagePuzzleId: null,
        ));

        return $mergeRequestId;
    }

    /**
     * @param class-string<PuzzleSolvingTime|WishListItem|SellSwapListItem|SoldSwappedItem> $class
     * @return list<string>
     */
    private function idsOn(string $class, object $puzzle): array
    {
        return array_map(
            static fn (object $row): string => $row->id->toString(),
            $this->entityManager->getRepository($class)->findBy(['puzzle' => $puzzle]),
        );
    }
}
