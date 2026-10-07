<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\Notification;
use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Entity\PuzzleChangeRequest;
use SpeedPuzzling\Web\Entity\PuzzleMergeAudit;
use SpeedPuzzling\Web\Entity\PuzzleMergeRequest;
use SpeedPuzzling\Web\Entity\PuzzleModerationDecision;
use SpeedPuzzling\Web\Entity\PuzzleRedirect;
use SpeedPuzzling\Web\Exceptions\InvalidPuzzleValues;
use SpeedPuzzling\Web\Exceptions\PuzzleNotFound;
use SpeedPuzzling\Web\Message\ApprovePuzzleChangeRequest;
use SpeedPuzzling\Web\Message\ApprovePuzzleMergeRequest;
use SpeedPuzzling\Web\Message\CloseOutdatedPuzzleRequests;
use SpeedPuzzling\Web\Message\EditPuzzle;
use SpeedPuzzling\Web\Message\SubmitPuzzleMergeRequest;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Repository\PuzzleChangeRequestRepository;
use SpeedPuzzling\Web\Repository\PuzzleMergeRequestRepository;
use SpeedPuzzling\Web\Repository\PuzzleRepository;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleReportFixture;
use SpeedPuzzling\Web\Value\BrandCodeList;
use SpeedPuzzling\Web\Value\EanList;
use SpeedPuzzling\Web\Value\MergeDecisionSource;
use SpeedPuzzling\Web\Value\PuzzleModerationAction;
use SpeedPuzzling\Web\Value\PuzzleRecordValues;
use SpeedPuzzling\Web\Value\PuzzleReportOutdatedReason;
use SpeedPuzzling\Web\Value\PuzzleReportStatus;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

/**
 * Requests the catalogue already took care of close by themselves (OutdatedPuzzleRequests,
 * docs/features/puzzle-approvals.md "Outdated requests").
 */
final class OutdatedPuzzleRequestsTest extends KernelTestCase
{
    private MessageBusInterface $messageBus;
    private EntityManagerInterface $entityManager;
    private PuzzleMergeRequestRepository $mergeRequestRepository;
    private PuzzleChangeRequestRepository $changeRequestRepository;
    private PuzzleRepository $puzzleRepository;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->messageBus = $container->get(MessageBusInterface::class);
        $this->entityManager = $container->get(EntityManagerInterface::class);
        $this->mergeRequestRepository = $container->get(PuzzleMergeRequestRepository::class);
        $this->changeRequestRepository = $container->get(PuzzleChangeRequestRepository::class);
        $this->puzzleRepository = $container->get(PuzzleRepository::class);
    }

    public function testAnotherReportOfTheSamePuzzlesIsClosedAsAlreadyDoneByTheMerge(): void
    {
        $merged = $this->report(PuzzleFixture::PUZZLE_500_04, [PuzzleFixture::PUZZLE_500_05]);
        $sameAgain = $this->report(PuzzleFixture::PUZZLE_500_05, [PuzzleFixture::PUZZLE_500_04]);

        $this->approve($merged, PuzzleFixture::PUZZLE_500_04);

        $request = $this->mergeRequestRepository->get($sameAgain);
        self::assertSame(PuzzleReportStatus::Outdated, $request->status);
        self::assertSame(PuzzleReportOutdatedReason::AlreadyMerged, $request->outdatedReason);
        self::assertSame($merged, $request->outdatedByMergeRequestId?->toString());
        self::assertSame(PuzzleFixture::PUZZLE_500_04, $request->survivorPuzzleId?->toString());
        self::assertNotNull($request->reviewedAt);
        self::assertNull($request->reviewedBy);

        // In the log without a decider - and the reporter is told nothing
        $decision = $this->entityManager->getRepository(PuzzleModerationDecision::class)->findOneBy([
            'mergeRequestId' => Uuid::fromString($sameAgain),
        ]);
        self::assertNotNull($decision);
        self::assertSame(PuzzleModerationAction::MergeRequestOutdated, $decision->action);
        self::assertSame(MergeDecisionSource::Automatic, $decision->source);
        self::assertNull($decision->decidedById);
        self::assertSame(PuzzleFixture::PUZZLE_500_04, $decision->puzzleId?->toString());
        self::assertSame(0, $this->entityManager->getRepository(Notification::class)->count(['targetMergeRequest' => $request]));
    }

    public function testAReportWithAnotherPuzzleStaysAndMergesThePuzzleItsDuplicateWasMergedInto(): void
    {
        $merged = $this->report(PuzzleFixture::PUZZLE_500_04, [PuzzleFixture::PUZZLE_500_05]);
        $withAnother = $this->report(PuzzleFixture::PUZZLE_500_05, [PuzzleFixture::PUZZLE_1000_05]);

        $this->approve($merged, PuzzleFixture::PUZZLE_500_04);

        self::assertSame(PuzzleReportStatus::Pending, $this->mergeRequestRepository->get($withAnother)->status);

        // 500_05 is 500_04 now - that one is the puzzle to keep or merge
        $this->approve($withAnother, PuzzleFixture::PUZZLE_500_04);

        self::assertSame(PuzzleReportStatus::Approved, $this->mergeRequestRepository->get($withAnother)->status);
        $this->puzzleRepository->get(PuzzleFixture::PUZZLE_500_04);
        $this->expectException(PuzzleNotFound::class);
        $this->puzzleRepository->get(PuzzleFixture::PUZZLE_1000_05);
    }

    public function testADecidedRequestIsNotApprovedAgain(): void
    {
        $merged = $this->report(PuzzleFixture::PUZZLE_500_04, [PuzzleFixture::PUZZLE_500_05]);
        $this->approve($merged, PuzzleFixture::PUZZLE_500_04);

        $this->expectException(InvalidPuzzleValues::class);
        $this->approve($merged, PuzzleFixture::PUZZLE_500_04);
    }

    public function testNothingLeftToMergeIsNotApproved(): void
    {
        $request = $this->persistedReport(
            [PuzzleFixture::PUZZLE_500_04, Uuid::uuid7()->toString()],
            self::getContainer()->get(PlayerRepository::class)->get(PlayerFixture::PLAYER_REGULAR),
        );
        $this->entityManager->flush();

        $this->expectException(InvalidPuzzleValues::class);
        $this->approve($request, PuzzleFixture::PUZZLE_500_04);
    }

    public function testChangeRequestsOfTheMergedPuzzleMoveToTheSurvivor(): void
    {
        $merged = $this->report(PuzzleFixture::PUZZLE_500_04, [PuzzleFixture::PUZZLE_500_05]);
        $pending = $this->proposal(PuzzleFixture::PUZZLE_500_05, proposedPiecesCount: 999);
        $rejected = $this->proposal(PuzzleFixture::PUZZLE_500_05, proposedPiecesCount: 998, reject: true);

        $this->approve($merged, PuzzleFixture::PUZZLE_500_04);

        $request = $this->changeRequestRepository->get($pending);
        self::assertSame(PuzzleReportStatus::Pending, $request->status);
        self::assertSame(PuzzleFixture::PUZZLE_500_04, $request->puzzle->id->toString());
        self::assertSame(PuzzleFixture::PUZZLE_500_05, $request->mergedFromPuzzleId?->toString());

        // A decided one stays in the history
        $request = $this->changeRequestRepository->get($rejected);
        self::assertSame(PuzzleReportStatus::Rejected, $request->status);
        self::assertSame(PuzzleFixture::PUZZLE_500_04, $request->puzzle->id->toString());

        $audit = $this->entityManager->getRepository(PuzzleMergeAudit::class)->findOneBy(['mergeRequestId' => Uuid::fromString($merged)]);
        self::assertNotNull($audit);
        $migrated = $audit->snapshotBefore['migrated'] ?? null;
        self::assertIsArray($migrated);
        self::assertEqualsCanonicalizing([$pending, $rejected], $migrated['changeRequests'] ?? null);
    }

    public function testAProposalTheMergeCarriedOutIsClosed(): void
    {
        $merged = $this->report(PuzzleFixture::PUZZLE_500_04, [PuzzleFixture::PUZZLE_500_05]);
        $carriedOut = $this->proposal(PuzzleFixture::PUZZLE_500_05, proposedName: 'Merged Puzzle Name');
        $notYet = $this->proposal(PuzzleFixture::PUZZLE_500_04, proposedName: 'Merged Puzzle Name', proposedPiecesCount: 1500);

        $this->approve($merged, PuzzleFixture::PUZZLE_500_04, 'Merged Puzzle Name');

        $request = $this->changeRequestRepository->get($carriedOut);
        self::assertSame(PuzzleReportStatus::Outdated, $request->status);
        self::assertSame(PuzzleReportOutdatedReason::AlreadyApplied, $request->outdatedReason);
        self::assertSame(PuzzleReportStatus::Pending, $this->changeRequestRepository->get($notYet)->status);

        $decision = $this->entityManager->getRepository(PuzzleModerationDecision::class)->findOneBy([
            'changeRequestId' => Uuid::fromString($carriedOut),
        ]);
        self::assertNotNull($decision);
        self::assertSame(PuzzleModerationAction::ChangeRequestOutdated, $decision->action);
        self::assertNull($decision->decidedById);
    }

    public function testApprovingAProposalClosesTheOnesAskingForNothingMore(): void
    {
        // The fixture proposes the name and an EAN for PUZZLE_500_01
        $sameName = $this->proposal(PuzzleFixture::PUZZLE_500_01, proposedName: 'Updated Puzzle Name');
        $more = $this->proposal(PuzzleFixture::PUZZLE_500_01, proposedName: 'Updated Puzzle Name', proposedPiecesCount: 1500);

        $this->messageBus->dispatch(new ApprovePuzzleChangeRequest(
            changeRequestId: PuzzleReportFixture::CHANGE_REQUEST_PENDING,
            puzzleId: PuzzleFixture::PUZZLE_500_01,
            reviewerId: PlayerFixture::PLAYER_ADMIN,
            selectedFields: ['name', 'ean'],
        ));
        $this->entityManager->clear();

        self::assertSame(PuzzleReportStatus::Approved, $this->changeRequestRepository->get(PuzzleReportFixture::CHANGE_REQUEST_PENDING)->status);
        self::assertSame(PuzzleReportStatus::Outdated, $this->changeRequestRepository->get($sameName)->status);
        self::assertSame(PuzzleReportStatus::Pending, $this->changeRequestRepository->get($more)->status);
    }

    public function testADirectEditClosesTheProposalsItCarriedOut(): void
    {
        $puzzle = $this->puzzleRepository->get(PuzzleFixture::PUZZLE_1000_03);
        $carriedOut = $this->proposal(PuzzleFixture::PUZZLE_1000_03, proposedPiecesCount: 1001);

        $this->messageBus->dispatch(new EditPuzzle(
            puzzleId: PuzzleFixture::PUZZLE_1000_03,
            editorId: PlayerFixture::PLAYER_ADMIN,
            values: new PuzzleRecordValues(
                name: $puzzle->name,
                nameLanguage: $puzzle->nameLanguage,
                alternativeNames: $puzzle->alternativeNames(),
                manufacturerId: $puzzle->manufacturer?->id->toString(),
                piecesCount: 1001,
                eans: EanList::fromStored($puzzle->ean),
                brandCodes: BrandCodeList::fromStored($puzzle->identificationNumber),
            ),
        ));
        $this->entityManager->clear();

        self::assertSame(PuzzleReportStatus::Outdated, $this->changeRequestRepository->get($carriedOut)->status);
    }

    public function testTheDailyCheckClosesWhatWentAroundTheHandlers(): void
    {
        $reporter = self::getContainer()->get(PlayerRepository::class)->get(PlayerFixture::PLAYER_REGULAR);
        $deletedBySql = Uuid::uuid7()->toString();
        $mergedLongAgo = Uuid::uuid7()->toString();

        // A puzzle deleted without a merge, and one merged before redirects reached this request
        $this->entityManager->persist(new PuzzleRedirect(Uuid::uuid7(), Uuid::fromString($mergedLongAgo), Uuid::fromString(PuzzleFixture::PUZZLE_1000_04), new DateTimeImmutable()));
        $gone = $this->persistedReport([PuzzleFixture::PUZZLE_1000_03, $deletedBySql], $reporter);
        $alreadyMerged = $this->persistedReport([$mergedLongAgo, PuzzleFixture::PUZZLE_1000_04], $reporter);
        $stillToMerge = $this->persistedReport([$mergedLongAgo, PuzzleFixture::PUZZLE_1000_03], $reporter);
        $this->entityManager->flush();

        // A proposal the puzzle got some other way
        $puzzle = $this->puzzleRepository->get(PuzzleFixture::PUZZLE_1000_03);
        $applied = $this->proposal(PuzzleFixture::PUZZLE_1000_03, proposedPiecesCount: $puzzle->piecesCount);

        self::assertSame(['mergeRequests' => 2, 'changeRequests' => 1], $this->closeOutdated());
        $this->entityManager->clear();

        $request = $this->mergeRequestRepository->get($gone);
        self::assertSame(PuzzleReportStatus::Outdated, $request->status);
        self::assertSame(PuzzleReportOutdatedReason::PuzzlesGone, $request->outdatedReason);
        self::assertNull($request->outdatedByMergeRequestId);

        $request = $this->mergeRequestRepository->get($alreadyMerged);
        self::assertSame(PuzzleReportOutdatedReason::AlreadyMerged, $request->outdatedReason);
        self::assertSame(PuzzleFixture::PUZZLE_1000_04, $request->survivorPuzzleId?->toString());

        self::assertSame(PuzzleReportStatus::Pending, $this->mergeRequestRepository->get($stillToMerge)->status);
        self::assertSame(PuzzleReportStatus::Pending, $this->mergeRequestRepository->get(PuzzleReportFixture::MERGE_REQUEST_PENDING)->status);
        self::assertSame(PuzzleReportStatus::Outdated, $this->changeRequestRepository->get($applied)->status);
        self::assertSame(PuzzleReportStatus::Pending, $this->changeRequestRepository->get(PuzzleReportFixture::CHANGE_REQUEST_PENDING)->status);

        // Nothing more the second time
        self::assertSame(['mergeRequests' => 0, 'changeRequests' => 0], $this->closeOutdated());
    }

    /**
     * @return array{mergeRequests: int, changeRequests: int}
     */
    private function closeOutdated(): array
    {
        $handled = $this->messageBus->dispatch(new CloseOutdatedPuzzleRequests())->last(HandledStamp::class);
        self::assertNotNull($handled);
        /** @var array{mergeRequests: int, changeRequests: int} $closed */
        $closed = $handled->getResult();

        return $closed;
    }

    /**
     * @param list<string> $duplicateIds
     */
    private function report(string $sourceId, array $duplicateIds): string
    {
        $id = Uuid::uuid7()->toString();

        $this->messageBus->dispatch(new SubmitPuzzleMergeRequest(
            mergeRequestId: $id,
            sourcePuzzleId: $sourceId,
            reporterId: PlayerFixture::PLAYER_REGULAR,
            duplicatePuzzleIds: $duplicateIds,
        ));

        return $id;
    }

    /**
     * A report as SQL could leave it - naming puzzles that no longer exist.
     *
     * @param list<string> $puzzleIds
     */
    private function persistedReport(array $puzzleIds, Player $reporter): string
    {
        $id = Uuid::uuid7();

        $this->entityManager->persist(new PuzzleMergeRequest(
            id: $id,
            sourcePuzzle: null,
            reporter: $reporter,
            submittedAt: new DateTimeImmutable(),
            reportedDuplicatePuzzleIds: $puzzleIds,
        ));

        return $id->toString();
    }

    private function approve(string $mergeRequestId, string $survivorId, string $name = 'Merged Puzzle Name'): void
    {
        $this->messageBus->dispatch(new ApprovePuzzleMergeRequest(
            mergeRequestId: $mergeRequestId,
            reviewerId: PlayerFixture::PLAYER_ADMIN,
            survivorPuzzleId: $survivorId,
            mergedName: $name,
            mergedEans: null,
            mergedBrandCodes: null,
            mergedPiecesCount: $this->puzzleRepository->get($survivorId)->piecesCount,
            mergedManufacturerId: null,
            selectedImagePuzzleId: null,
        ));
        $this->entityManager->clear();
    }

    private function proposal(string $puzzleId, null|string $proposedName = null, null|int $proposedPiecesCount = null, bool $reject = false): string
    {
        $puzzle = $this->puzzleRepository->get($puzzleId);
        $players = self::getContainer()->get(PlayerRepository::class);

        $request = new PuzzleChangeRequest(
            id: Uuid::uuid7(),
            puzzle: $puzzle,
            reporter: $players->get(PlayerFixture::PLAYER_REGULAR),
            submittedAt: new DateTimeImmutable(),
            proposedName: $proposedName,
            proposedPiecesCount: $proposedPiecesCount,
            originalName: $puzzle->name,
            originalPiecesCount: $puzzle->piecesCount,
        );

        if ($reject) {
            $request->reject($players->get(PlayerFixture::PLAYER_ADMIN), new DateTimeImmutable(), 'Not on the box');
        }

        $this->entityManager->persist($request);
        $this->entityManager->flush();

        return $request->id->toString();
    }
}
