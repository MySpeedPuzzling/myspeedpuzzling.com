<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\Filesystem;
use PHPUnit\Framework\Attributes\DataProvider;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Entity\Puzzle;
use SpeedPuzzling\Web\Exceptions\InvalidPuzzleValues;
use SpeedPuzzling\Web\FormData\PuzzleRecordFormData;
use SpeedPuzzling\Web\Message\ApprovePuzzleChangeRequest;
use SpeedPuzzling\Web\Message\EditPuzzle;
use SpeedPuzzling\Web\Message\LinkEanToPuzzle;
use SpeedPuzzling\Web\Message\SubmitPuzzleChangeRequest;
use SpeedPuzzling\Web\Query\GetPendingPuzzleProposals;
use SpeedPuzzling\Web\Query\GetPuzzleChangeRequests;
use SpeedPuzzling\Web\Query\GetPuzzleHistory;
use SpeedPuzzling\Web\Query\GetPuzzleRecord;
use SpeedPuzzling\Web\Repository\PuzzleChangeRequestRepository;
use SpeedPuzzling\Web\Repository\PuzzleRepository;
use SpeedPuzzling\Web\Results\PuzzleChangeRequestOverview;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Tests\TestClock;
use SpeedPuzzling\Web\Value\EanList;
use SpeedPuzzling\Web\Value\MergeDecisionSource;
use SpeedPuzzling\Web\Value\PuzzleProposalField;
use SpeedPuzzling\Web\Value\PuzzleRecordValues;
use SpeedPuzzling\Web\Value\PuzzleRecordVersion;
use SpeedPuzzling\Web\Value\PuzzleReportStatus;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * "The box has no barcode (EAN)" (docs/features/puzzle-approvals.md "No barcode on the box"): proposed like any other
 * field, locked together with the EAN while pending, approved by a moderator through the review or the internal API,
 * and cleared by every EAN that reaches the puzzle afterwards.
 */
final class EanAbsentTest extends KernelTestCase
{
    // Ravensburger, 9000 pieces, approved, no EAN, no pending proposal
    private const string PUZZLE = PuzzleFixture::PUZZLE_9000;
    private const string EAN = '4005555011897';
    private const string MODERATOR = PlayerFixture::PLAYER_WITH_STRIPE;

    private MessageBusInterface $messageBus;
    private PuzzleRepository $puzzleRepository;
    /** @var list<string> */
    private array $filesToCleanup = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $this->messageBus = self::getContainer()->get(MessageBusInterface::class);
        $this->puzzleRepository = self::getContainer()->get(PuzzleRepository::class);

        // A community moderator, not an admin
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $moderator = $entityManager->find(Player::class, self::MODERATOR);
        self::assertNotNull($moderator);
        $moderator->moderatorSince = TestClock::now()->modify('-1 month');
        $entityManager->flush();
    }

    protected function tearDown(): void
    {
        $filesystem = self::getContainer()->get(Filesystem::class);

        foreach ($this->filesToCleanup as $path) {
            if ($filesystem->fileExists($path)) {
                $filesystem->delete($path);
            }
        }

        parent::tearDown();
    }

    public function testTheProposalWaitsLockingTheEanToo(): void
    {
        $changeRequestId = $this->propose('eanAbsent');

        $changeRequest = self::getContainer()->get(PuzzleChangeRequestRepository::class)->get($changeRequestId);
        self::assertTrue($changeRequest->proposedEanAbsent);
        self::assertFalse($changeRequest->originalEanAbsent);
        self::assertSame([PuzzleProposalField::EanAbsent], $changeRequest->proposedChanges()->changedFields());
        self::assertFalse($changeRequest->nothingLeftToApply());

        $locks = self::getContainer()->get(GetPendingPuzzleProposals::class)->locks(self::PUZZLE);
        self::assertTrue($locks->isLocked(PuzzleProposalField::EanAbsent));
        // They say the opposite of each other - an EAN waits for the same review
        self::assertTrue($locks->isLocked(PuzzleProposalField::Ean));
        self::assertTrue($locks->waitingForEanAbsent());
        self::assertFalse($locks->isLocked(PuzzleProposalField::Image));
        self::assertFalse($locks->locksEveryField());

        // Not yet on the puzzle
        self::assertFalse($this->puzzle()->isEanAbsent());
    }

    public function testAModeratorApprovesItInTheReview(): void
    {
        $changeRequestId = $this->propose('eanAbsent');

        $this->approveThroughReview($changeRequestId);

        self::assertTrue($this->puzzle()->isEanAbsent());
        self::assertSame(PuzzleReportStatus::Approved, $this->changeRequestStatus($changeRequestId));

        // The history names it
        $history = self::getContainer()->get(GetPuzzleHistory::class)->forPuzzle(self::PUZZLE);
        $labels = [];
        foreach ($history as $entry) {
            foreach ($entry->changes as $change) {
                $labels[] = [$change->label, $change->before, $change->after];
            }
        }
        self::assertContains(['No barcode on the box', null, 'Yes'], $labels);
    }

    public function testTheInternalApiAppliesItOnlyWhenSelected(): void
    {
        $changeRequestId = $this->propose('eanAbsent');

        $this->approveThroughApi($changeRequestId, ['ean', 'image']);
        self::assertFalse($this->puzzle()->isEanAbsent(), 'not selected');

        $other = $this->propose('eanAbsent');
        $this->approveThroughApi($other, ['eanAbsent']);
        self::assertTrue($this->puzzle()->isEanAbsent());
    }

    public function testAnApprovalAfterAnEanCameIsRefused(): void
    {
        $changeRequestId = $this->propose('eanAbsent');
        $this->messageBus->dispatch(new LinkEanToPuzzle(self::PUZZLE, PlayerFixture::PLAYER_REGULAR, self::EAN));

        $review = $this->overview($changeRequestId);
        self::assertSame(['No barcode on the box'], $review->changedSinceProposed());

        // A box with a barcode is no box without one
        $this->expectException(InvalidPuzzleValues::class);
        $this->approveThroughApi($changeRequestId, ['eanAbsent']);
    }

    /**
     * @return iterable<string, array{bool, bool}>
     */
    public static function parallelWithAnImage(): iterable
    {
        foreach (['admin review' => true, 'internal API' => false] as $path => $throughReview) {
            yield "no barcode approved first, {$path}" => [true, $throughReview];
            yield "image approved first, {$path}" => [false, $throughReview];
        }
    }

    #[DataProvider('parallelWithAnImage')]
    public function testWaitsNextToAnImageProposalAndNeitherRevertsTheOther(bool $eanAbsentFirst, bool $throughReview): void
    {
        $eanAbsentId = $this->propose('eanAbsent');
        $imageId = $this->propose('image');

        foreach ($eanAbsentFirst ? [$eanAbsentId, $imageId] : [$imageId, $eanAbsentId] as $changeRequestId) {
            $throughReview
                ? $this->approveThroughReview($changeRequestId)
                : $this->approveThroughApi($changeRequestId, array_map(static fn (PuzzleProposalField $field): string => $field->value, PuzzleProposalField::cases()));
        }

        $puzzle = $this->puzzle();
        self::assertTrue($puzzle->isEanAbsent());
        self::assertNotNull($puzzle->image);
        $this->filesToCleanup[] = $puzzle->image;
        self::assertSame(PuzzleReportStatus::Approved, $this->changeRequestStatus($eanAbsentId));
        self::assertSame(PuzzleReportStatus::Approved, $this->changeRequestStatus($imageId));
    }

    public function testAnEanAndNoBarcodeAreNeverProposedTogether(): void
    {
        $this->expectException(InvalidPuzzleValues::class);
        $this->propose('eanAbsent', eans: EanList::fromStored(self::EAN));
    }

    public function testAPuzzleConfirmedAlreadyStoresNoProposalOfIt(): void
    {
        $this->confirmEanAbsent();

        $changeRequestId = $this->propose('eanAbsent', name: 'Puzzle 19 - renamed');
        $changeRequest = self::getContainer()->get(PuzzleChangeRequestRepository::class)->get($changeRequestId);

        self::assertNull($changeRequest->proposedEanAbsent);
        self::assertTrue($changeRequest->originalEanAbsent);
    }

    public function testAScannedEanClearsIt(): void
    {
        $this->confirmEanAbsent();

        $this->messageBus->dispatch(new LinkEanToPuzzle(self::PUZZLE, PlayerFixture::PLAYER_REGULAR, self::EAN));

        self::assertFalse($this->puzzle()->isEanAbsent());
    }

    public function testAnApprovedEanProposalClearsIt(): void
    {
        $this->confirmEanAbsent();

        $changeRequestId = $this->propose('ean');
        $this->approveThroughApi($changeRequestId, ['ean']);

        $puzzle = $this->puzzle();
        self::assertSame(self::EAN, $puzzle->ean);
        self::assertFalse($puzzle->isEanAbsent());
    }

    public function testAModeratorsEditSetsAndClearsIt(): void
    {
        $this->edit(eanAbsent: true, eans: EanList::fromStored(null));
        self::assertTrue($this->puzzle()->isEanAbsent());

        // An EAN typed in clears it, whatever the box was said to have
        $this->edit(eanAbsent: null, eans: EanList::fromStored(self::EAN));
        self::assertFalse($this->puzzle()->isEanAbsent());

        $this->edit(eanAbsent: true, eans: EanList::fromStored(null));
        $this->edit(eanAbsent: false, eans: EanList::fromStored(null));
        self::assertFalse($this->puzzle()->isEanAbsent());
    }

    public function testAnEditCannotSaveAnEanWithNoBarcode(): void
    {
        $this->expectException(InvalidPuzzleValues::class);

        $this->edit(eanAbsent: true, eans: EanList::fromStored(self::EAN));
    }

    public function testTheRecordVersionChangesWithIt(): void
    {
        $before = PuzzleRecordVersion::ofPuzzle($this->puzzle());
        $record = self::getContainer()->get(GetPuzzleRecord::class)->byId(self::PUZZLE);
        self::assertNotNull($record);
        self::assertSame($before, $record->recordVersion(), 'unchanged for a puzzle without it');

        $this->confirmEanAbsent();

        $record = self::getContainer()->get(GetPuzzleRecord::class)->byId(self::PUZZLE);
        self::assertNotNull($record);
        self::assertTrue($record->eanAbsent);
        self::assertNotSame($before, $record->recordVersion());
        self::assertSame(PuzzleRecordVersion::ofPuzzle($this->puzzle()), $record->recordVersion());
    }

    private function propose(string $field, null|EanList $eans = null, null|string $name = null): string
    {
        $puzzle = $this->puzzle();
        $changeRequestId = Uuid::uuid7()->toString();
        $photo = null;

        if ($field === 'image') {
            $path = tempnam(sys_get_temp_dir(), 'ean_absent_') . '.jpg';
            $image = imagecreatetruecolor(400, 300);
            assert($image !== false);
            imagejpeg($image, $path);
            $photo = new UploadedFile($path, 'box.jpg', 'image/jpeg', null, true);
            $this->filesToCleanup[] = 'proposal-' . $changeRequestId . '.jpg';
        }

        $this->messageBus->dispatch(new SubmitPuzzleChangeRequest(
            changeRequestId: $changeRequestId,
            puzzleId: self::PUZZLE,
            reporterId: PlayerFixture::PLAYER_REGULAR,
            proposedName: $name ?? $puzzle->name,
            proposedBrand: $puzzle->manufacturer?->id->toString(),
            proposedPiecesCount: $puzzle->piecesCount,
            proposedEans: $eans ?? ($field === 'ean' ? EanList::fromStored(self::EAN) : $puzzle->eans()),
            proposedBrandCodes: $puzzle->brandCodes(),
            proposedPhoto: $photo,
            originalAlternativeNames: $puzzle->alternativeNames(),
            originalNameLanguage: $puzzle->nameLanguage,
            proposedEanAbsent: $field === 'eanAbsent',
        ));

        return $changeRequestId;
    }

    /**
     * What the admin review posts when the moderator approves without editing anything
     */
    private function approveThroughReview(string $changeRequestId): void
    {
        $review = $this->overview($changeRequestId);
        $data = PuzzleRecordFormData::fromChangeRequest($review);

        $this->messageBus->dispatch(new ApprovePuzzleChangeRequest(
            changeRequestId: $changeRequestId,
            puzzleId: self::PUZZLE,
            reviewerId: self::MODERATOR,
            reviewed: $data->toValues(),
            reviewedFrom: $review->puzzleAlternativeNames,
        ));
    }

    /**
     * @param list<string> $selectedFields
     */
    private function approveThroughApi(string $changeRequestId, array $selectedFields): void
    {
        $this->messageBus->dispatch(new ApprovePuzzleChangeRequest(
            changeRequestId: $changeRequestId,
            puzzleId: self::PUZZLE,
            reviewerId: PlayerFixture::PLAYER_ADMIN,
            selectedFields: $selectedFields,
            decisionSource: MergeDecisionSource::InternalApi,
        ));
    }

    private function edit(null|bool $eanAbsent, EanList $eans): void
    {
        $record = self::getContainer()->get(GetPuzzleRecord::class)->byId(self::PUZZLE);
        self::assertNotNull($record);
        $data = PuzzleRecordFormData::fromPuzzle($record);
        $values = $data->toValues();

        $this->messageBus->dispatch(new EditPuzzle(
            puzzleId: self::PUZZLE,
            editorId: self::MODERATOR,
            values: new PuzzleRecordValues(
                name: $values->name,
                nameLanguage: $values->nameLanguage,
                alternativeNames: $values->alternativeNames,
                manufacturerId: $values->manufacturerId,
                piecesCount: $values->piecesCount,
                eans: $eans,
                brandCodes: $values->brandCodes,
                recordVersion: $values->recordVersion,
                eanAbsent: $eanAbsent,
            ),
        ));
        self::getContainer()->get(EntityManagerInterface::class)->clear();
    }

    private function confirmEanAbsent(): void
    {
        $this->edit(eanAbsent: true, eans: EanList::fromStored(null));
        self::assertTrue($this->puzzle()->isEanAbsent());
    }

    private function puzzle(): Puzzle
    {
        self::getContainer()->get(EntityManagerInterface::class)->clear();

        return $this->puzzleRepository->get(self::PUZZLE);
    }

    private function changeRequestStatus(string $changeRequestId): PuzzleReportStatus
    {
        self::getContainer()->get(EntityManagerInterface::class)->clear();

        return self::getContainer()->get(PuzzleChangeRequestRepository::class)->get($changeRequestId)->status;
    }

    private function overview(string $changeRequestId): PuzzleChangeRequestOverview
    {
        self::getContainer()->get(EntityManagerInterface::class)->clear();
        $review = self::getContainer()->get(GetPuzzleChangeRequests::class)->byId($changeRequestId, includeSecret: true);
        self::assertNotNull($review);

        return $review;
    }
}
