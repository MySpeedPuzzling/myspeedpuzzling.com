<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\Filesystem;
use PHPUnit\Framework\Attributes\DataProvider;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\FormData\PuzzleRecordFormData;
use SpeedPuzzling\Web\Message\ApprovePuzzleChangeRequest;
use SpeedPuzzling\Web\Message\SubmitPuzzleChangeRequest;
use SpeedPuzzling\Web\Query\GetPuzzleChangeRequests;
use SpeedPuzzling\Web\Repository\PuzzleChangeRequestRepository;
use SpeedPuzzling\Web\Repository\PuzzleRepository;
use SpeedPuzzling\Web\Results\PuzzleChangeRequestOverview;
use SpeedPuzzling\Web\Tests\DataFixtures\ManufacturerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Value\EanList;
use SpeedPuzzling\Web\Value\MergeDecisionSource;
use SpeedPuzzling\Web\Value\PuzzleImageChoice;
use SpeedPuzzling\Web\Value\PuzzleProposalField;
use SpeedPuzzling\Web\Value\PuzzleReportStatus;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Several pending change requests of one puzzle, each changing other fields (GetPendingPuzzleProposals::locks()): each
 * one is filed with the whole record as the puzzle was, so approving one - through the admin review or the internal
 * API - must apply only the fields it changes and keep what the other one changed meanwhile.
 */
final class ParallelPuzzleChangeRequestsTest extends KernelTestCase
{
    // Ravensburger, 1000 pieces, no pending proposal
    private const string PUZZLE = PuzzleFixture::PUZZLE_1000_05;
    private const string NEW_EAN = '4005555011897';

    private const array EVERY_FIELD = [
        'name', 'nameLanguage', 'alternativeNames', 'manufacturer', 'piecesCount', 'ean', 'identificationNumber', 'image',
    ];

    private MessageBusInterface $messageBus;
    private PuzzleRepository $puzzleRepository;
    private Filesystem $filesystem;
    /** @var list<string> */
    private array $filesToCleanup = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->messageBus = $container->get(MessageBusInterface::class);
        $this->puzzleRepository = $container->get(PuzzleRepository::class);
        $this->filesystem = $container->get(Filesystem::class);
    }

    protected function tearDown(): void
    {
        foreach ($this->filesToCleanup as $path) {
            if ($this->filesystem->fileExists($path)) {
                $this->filesystem->delete($path);
            }
        }

        parent::tearDown();
    }

    /**
     * @return iterable<string, array{string, string, bool, bool}>
     */
    public static function pairs(): iterable
    {
        foreach ([['ean', 'image'], ['ean', 'pieces'], ['brand', 'name']] as [$first, $second]) {
            foreach (['admin review' => true, 'internal API' => false] as $path => $throughReview) {
                yield "{$first} + {$second}, {$first} approved first, {$path}" => [$first, $second, true, $throughReview];
                yield "{$first} + {$second}, {$second} approved first, {$path}" => [$first, $second, false, $throughReview];
            }
        }
    }

    #[DataProvider('pairs')]
    public function testNeitherApprovalRevertsTheOther(string $first, string $second, bool $firstApprovedFirst, bool $throughReview): void
    {
        $before = $this->puzzleRepository->get(self::PUZZLE);
        $originalName = $before->name;
        $originalPieces = $before->piecesCount;
        $originalEans = $before->eans()->toStored();

        // Both filed against the puzzle as it is - neither knows the other
        $firstId = $this->propose($first);
        $secondId = $this->propose($second);

        $order = $firstApprovedFirst ? [$firstId, $secondId] : [$secondId, $firstId];

        foreach ($order as $changeRequestId) {
            $throughReview ? $this->approveThroughReview($changeRequestId) : $this->approveThroughApi($changeRequestId);
        }

        $this->entityManagerClear();
        $puzzle = $this->puzzleRepository->get(self::PUZZLE);

        foreach ([$first, $second] as $field) {
            match ($field) {
                'ean' => self::assertSame(self::NEW_EAN, $puzzle->eans()->toStored()),
                'pieces' => self::assertSame(1500, $puzzle->piecesCount),
                'brand' => self::assertSame(ManufacturerFixture::MANUFACTURER_TREFL, $puzzle->manufacturer?->id->toString()),
                'name' => self::assertSame('Puzzle 9 - corrected', $puzzle->name),
                'image' => self::assertNotNull($puzzle->image),
                default => self::fail($field),
            };
        }

        // And nothing else moved
        if (!in_array('ean', [$first, $second], true)) {
            self::assertSame($originalEans, $puzzle->eans()->toStored());
        }

        if (!in_array('pieces', [$first, $second], true)) {
            self::assertSame($originalPieces, $puzzle->piecesCount);
        }

        if (!in_array('name', [$first, $second], true)) {
            self::assertSame($originalName, $puzzle->name);
        }

        if (!in_array('brand', [$first, $second], true)) {
            self::assertSame(ManufacturerFixture::MANUFACTURER_RAVENSBURGER, $puzzle->manufacturer?->id->toString());
        }

        $requests = self::getContainer()->get(PuzzleChangeRequestRepository::class);
        self::assertSame(PuzzleReportStatus::Approved, $requests->get($firstId)->status);
        self::assertSame(PuzzleReportStatus::Approved, $requests->get($secondId)->status);

        if ($puzzle->image !== null) {
            $this->filesToCleanup[] = $puzzle->image;
        }
    }

    public function testTheReviewStartsFromThePuzzleAsItIsNowWithOnlyThisProposalsChanges(): void
    {
        $piecesId = $this->propose('pieces');
        $eanId = $this->propose('ean');

        $this->approveThroughApi($piecesId);

        $review = $this->overview($eanId);
        $data = PuzzleRecordFormData::fromChangeRequest($review);

        // The other proposal's pieces - the puzzle's now, not this proposal's snapshot (1000)
        self::assertSame(1500, $data->piecesCount);
        self::assertSame(EanList::fromStored(self::NEW_EAN)->display(), $data->eans);
        self::assertSame(ManufacturerFixture::MANUFACTURER_RAVENSBURGER, $data->manufacturerId);
        self::assertSame(PuzzleImageChoice::Keep, $data->image);
        self::assertTrue($review->hasEanChange());
        self::assertFalse($review->hasPiecesCountChange(), 'Its snapshot of the pieces is no change');
        self::assertSame([], $review->changedSinceProposed());
    }

    public function testAFieldChangedSinceTheProposalIsPointedOut(): void
    {
        // Two proposals of the same field - filed before the lock existed (the handler alone does not check it)
        $firstId = $this->propose('ean');
        $secondId = $this->propose('ean', ean: '4005555006299');

        $this->approveThroughApi($firstId);

        $review = $this->overview($secondId);
        self::assertSame(['EAN'], $review->changedSinceProposed());
        self::assertSame(PuzzleReportStatus::Pending, $review->status);
    }

    public function testAnotherProposalsSnapshotOfAFieldDoesNotKeepItFromClosingAsDone(): void
    {
        // A pieces proposal waits; the EAN proposal is approved - then a moderator sets the pieces by hand
        $piecesId = $this->propose('pieces');
        $eanId = $this->propose('ean');
        $this->approveThroughApi($eanId);

        $pieces = self::getContainer()->get(PuzzleChangeRequestRepository::class)->get($piecesId);
        self::assertSame(PuzzleReportStatus::Pending, $pieces->status);
        self::assertFalse($pieces->nothingLeftToApply());

        $puzzle = $this->puzzleRepository->get(self::PUZZLE);
        $puzzle->piecesCount = 1500;

        // Its EAN snapshot (none proposed) and name snapshot do not count - the pieces are all it changes
        self::assertTrue($pieces->nothingLeftToApply());
        self::assertSame([PuzzleProposalField::PiecesCount], $pieces->proposedChanges()->changedFields());
    }

    private function propose(string $field, string $ean = self::NEW_EAN): string
    {
        $puzzle = $this->puzzleRepository->get(self::PUZZLE);
        $changeRequestId = Uuid::uuid7()->toString();

        $photo = null;

        if ($field === 'image') {
            $path = tempnam(sys_get_temp_dir(), 'parallel_') . '.jpg';
            $image = imagecreatetruecolor(400, 300);
            assert($image !== false);
            imagejpeg($image, $path);
            $photo = new UploadedFile($path, 'box.jpg', 'image/jpeg', null, true);
        }

        // The whole record as the form files it - only the proposed field differs
        $this->messageBus->dispatch(new SubmitPuzzleChangeRequest(
            changeRequestId: $changeRequestId,
            puzzleId: self::PUZZLE,
            reporterId: PlayerFixture::PLAYER_REGULAR,
            proposedName: $field === 'name' ? 'Puzzle 9 - corrected' : $puzzle->name,
            proposedBrand: $field === 'brand' ? ManufacturerFixture::MANUFACTURER_TREFL : $puzzle->manufacturer?->id->toString(),
            proposedPiecesCount: $field === 'pieces' ? 1500 : $puzzle->piecesCount,
            proposedEans: $field === 'ean' ? EanList::fromStored($ean) : $puzzle->eans(),
            proposedBrandCodes: $puzzle->brandCodes(),
            proposedPhoto: $photo,
            originalAlternativeNames: $puzzle->alternativeNames(),
            originalNameLanguage: $puzzle->nameLanguage,
        ));

        if ($field === 'image') {
            $this->filesToCleanup[] = 'proposal-' . $changeRequestId . '.jpg';
        }

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
            reviewerId: PlayerFixture::PLAYER_ADMIN,
            reviewed: $data->toValues(),
            reviewedFrom: $review->puzzleAlternativeNames,
        ));
    }

    /**
     * Every field selected - the strictest caller: only the fields the proposal changes may move
     */
    private function approveThroughApi(string $changeRequestId): void
    {
        $this->messageBus->dispatch(new ApprovePuzzleChangeRequest(
            changeRequestId: $changeRequestId,
            puzzleId: self::PUZZLE,
            reviewerId: PlayerFixture::PLAYER_ADMIN,
            selectedFields: self::EVERY_FIELD,
            decisionSource: MergeDecisionSource::InternalApi,
        ));
    }

    private function overview(string $changeRequestId): PuzzleChangeRequestOverview
    {
        $this->entityManagerClear();
        $review = self::getContainer()->get(GetPuzzleChangeRequests::class)->byId($changeRequestId, includeSecret: true);
        self::assertNotNull($review);

        return $review;
    }

    private function entityManagerClear(): void
    {
        self::getContainer()->get(EntityManagerInterface::class)->clear();
    }
}
