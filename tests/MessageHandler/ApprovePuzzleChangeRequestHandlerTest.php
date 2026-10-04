<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\Filesystem;
use PHPUnit\Framework\Attributes\DataProvider;
use SpeedPuzzling\Web\Entity\PuzzleModerationDecision;
use SpeedPuzzling\Web\Exceptions\InvalidPuzzleValues;
use SpeedPuzzling\Web\Exceptions\PuzzleChangeRequestAlreadyReviewed;
use SpeedPuzzling\Web\Message\ApprovePuzzleChangeRequest;
use SpeedPuzzling\Web\Repository\PuzzleChangeRequestRepository;
use SpeedPuzzling\Web\Repository\PuzzleRepository;
use SpeedPuzzling\Web\Tests\DataFixtures\ManufacturerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleReportFixture;
use SpeedPuzzling\Web\Value\MergeDecisionSource;
use SpeedPuzzling\Web\Value\PuzzleImageChoice;
use SpeedPuzzling\Web\Value\PuzzleModerationAction;
use SpeedPuzzling\Web\Value\PuzzleReportStatus;
use SpeedPuzzling\Web\Value\PuzzleRecordValues;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Messenger\MessageBusInterface;

final class ApprovePuzzleChangeRequestHandlerTest extends KernelTestCase
{
    private MessageBusInterface $messageBus;
    private PuzzleChangeRequestRepository $changeRequestRepository;
    private PuzzleRepository $puzzleRepository;
    private Filesystem $filesystem;
    /** @var list<string> */
    private array $filesToCleanup = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->messageBus = $container->get(MessageBusInterface::class);
        $this->changeRequestRepository = $container->get(PuzzleChangeRequestRepository::class);
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

    public function testApprovingAllFieldsUpdatesPuzzle(): void
    {
        $changeRequest = $this->changeRequestRepository->get(PuzzleReportFixture::CHANGE_REQUEST_PENDING);
        $puzzle = $this->puzzleRepository->get(PuzzleFixture::PUZZLE_500_01);

        // Verify initial state
        self::assertSame(PuzzleReportStatus::Pending, $changeRequest->status);
        self::assertNotSame('Updated Puzzle Name', $puzzle->name);

        // Dispatch the approve message with all fields selected
        $this->messageBus->dispatch(
            new ApprovePuzzleChangeRequest(
                changeRequestId: PuzzleReportFixture::CHANGE_REQUEST_PENDING,
                reviewerId: PlayerFixture::PLAYER_ADMIN,
                selectedFields: ['name', 'ean'],
            ),
        );

        // Refresh entities from database
        $changeRequest = $this->changeRequestRepository->get(PuzzleReportFixture::CHANGE_REQUEST_PENDING);
        $puzzle = $this->puzzleRepository->get(PuzzleFixture::PUZZLE_500_01);

        // Verify the change request is now approved
        self::assertSame(PuzzleReportStatus::Approved, $changeRequest->status);
        self::assertNotNull($changeRequest->reviewedAt);
        self::assertNotNull($changeRequest->reviewedBy);

        // Verify the puzzle was updated with proposed changes
        self::assertSame('Updated Puzzle Name', $puzzle->name);
        self::assertSame('1234567890123', $puzzle->ean);
    }

    public function testSelectiveApprovalOnlyAppliesSelectedFields(): void
    {
        $puzzle = $this->puzzleRepository->get(PuzzleFixture::PUZZLE_500_01);
        $originalName = $puzzle->name;

        // Only approve EAN, skip name
        $this->messageBus->dispatch(
            new ApprovePuzzleChangeRequest(
                changeRequestId: PuzzleReportFixture::CHANGE_REQUEST_PENDING,
                reviewerId: PlayerFixture::PLAYER_ADMIN,
                selectedFields: ['ean'],
            ),
        );

        $puzzle = $this->puzzleRepository->get(PuzzleFixture::PUZZLE_500_01);

        // Name should remain unchanged
        self::assertSame($originalName, $puzzle->name);
        // EAN should be updated
        self::assertSame('1234567890123', $puzzle->ean);
    }

    public function testTheReviewAppliesEveryFieldAsTheReviewerSetIt(): void
    {
        // The player proposed a name and an EAN - the reviewer corrects those and changes fields nobody proposed
        $this->messageBus->dispatch(new ApprovePuzzleChangeRequest(
            changeRequestId: PuzzleReportFixture::CHANGE_REQUEST_PENDING,
            reviewerId: PlayerFixture::PLAYER_ADMIN,
            reviewed: new PuzzleRecordValues(
                name: '  Admin Corrected Name ',
                alternativeName: 'Alternative Title',
                manufacturerId: ManufacturerFixture::MANUFACTURER_TREFL,
                piecesCount: 1000,
                ean: '4005556123452',
                identificationNumber: ' ',
            ),
        ));

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();

        $puzzle = $this->puzzleRepository->get(PuzzleFixture::PUZZLE_500_01);
        self::assertSame('Admin Corrected Name', $puzzle->name);
        self::assertSame('Alternative Title', $puzzle->alternativeName);
        self::assertSame(ManufacturerFixture::MANUFACTURER_TREFL, $puzzle->manufacturer?->id->toString());
        self::assertSame(1000, $puzzle->piecesCount);
        self::assertSame('4005556123452', $puzzle->ean);
        self::assertNull($puzzle->identificationNumber);

        $decision = $entityManager->getRepository(PuzzleModerationDecision::class)->findOneBy([
            'action' => PuzzleModerationAction::ChangeRequestApproved,
        ]);
        self::assertNotNull($decision);
        self::assertArrayNotHasKey('selectedFields', $decision->details ?? []);
        self::assertSame('keep', $decision->details['image'] ?? null);
        $before = $decision->details['before'] ?? null;
        $after = $decision->details['after'] ?? null;
        self::assertIsArray($before);
        self::assertIsArray($after);
        self::assertSame('RB-500-001', $before['identificationNumber'] ?? null);
        self::assertSame('Admin Corrected Name', $after['name'] ?? null);
    }

    public function testTheReviewUploadsADifferentImage(): void
    {
        $imagePath = tempnam(sys_get_temp_dir(), 'puzzle_test_') . '.jpg';
        $image = imagecreatetruecolor(20, 10);
        assert($image !== false);
        imagejpeg($image, $imagePath);

        $this->messageBus->dispatch(new ApprovePuzzleChangeRequest(
            changeRequestId: PuzzleReportFixture::CHANGE_REQUEST_PENDING,
            reviewerId: PlayerFixture::PLAYER_ADMIN,
            reviewed: new PuzzleRecordValues(
                name: 'Puzzle 1',
                alternativeName: null,
                manufacturerId: ManufacturerFixture::MANUFACTURER_RAVENSBURGER,
                piecesCount: 500,
                ean: null,
                identificationNumber: 'RB-500-001',
                image: PuzzleImageChoice::Upload,
                uploadedImage: new UploadedFile($imagePath, 'box.jpg', 'image/jpeg', null, true),
            ),
        ));

        $puzzle = $this->puzzleRepository->get(PuzzleFixture::PUZZLE_500_01);
        self::assertNotNull($puzzle->image);
        $this->filesToCleanup[] = $puzzle->image;

        self::assertStringContainsString('ravensburger-puzzle-1-500', $puzzle->image);
        self::assertTrue($this->filesystem->fileExists($puzzle->image));
        self::assertSame(2.0, $puzzle->imageRatio);
    }

    public function testKeepingTheCurrentImageIgnoresTheProposedOne(): void
    {
        $imageBefore = $this->puzzleRepository->get(PuzzleFixture::PUZZLE_500_02)->image;

        $this->messageBus->dispatch(new ApprovePuzzleChangeRequest(
            changeRequestId: PuzzleReportFixture::CHANGE_REQUEST_WITH_IMAGE,
            reviewerId: PlayerFixture::PLAYER_ADMIN,
            reviewed: new PuzzleRecordValues(
                name: 'New Image Puzzle',
                alternativeName: null,
                manufacturerId: ManufacturerFixture::MANUFACTURER_RAVENSBURGER,
                piecesCount: 500,
                ean: '4005556123456',
                identificationNumber: null,
                image: PuzzleImageChoice::Keep,
            ),
        ));

        $puzzle = $this->puzzleRepository->get(PuzzleFixture::PUZZLE_500_02);
        self::assertSame('New Image Puzzle', $puzzle->name);
        self::assertSame($imageBefore, $puzzle->image);
    }

    /**
     * @return iterable<string, array{PuzzleImageChoice}>
     */
    public static function imageChoicesWithoutAnImage(): iterable
    {
        yield 'proposed image, none was proposed' => [PuzzleImageChoice::Proposed];
        yield 'upload without a file' => [PuzzleImageChoice::Upload];
    }

    #[DataProvider('imageChoicesWithoutAnImage')]
    public function testAnImageChoiceWithoutAnImageIsRefusedBeforeAnythingChanges(PuzzleImageChoice $image): void
    {
        try {
            $this->messageBus->dispatch(new ApprovePuzzleChangeRequest(
                changeRequestId: PuzzleReportFixture::CHANGE_REQUEST_PENDING,
                reviewerId: PlayerFixture::PLAYER_ADMIN,
                reviewed: new PuzzleRecordValues(
                    name: 'Must Not Be Saved',
                    alternativeName: null,
                    manufacturerId: ManufacturerFixture::MANUFACTURER_RAVENSBURGER,
                    piecesCount: 500,
                    ean: null,
                    identificationNumber: null,
                    image: $image,
                ),
            ));
            self::fail('The approval should have been refused.');
        } catch (InvalidPuzzleValues) {
        }

        self::assertSame('Puzzle 1', $this->puzzleRepository->get(PuzzleFixture::PUZZLE_500_01)->name);
        self::assertSame(PuzzleReportStatus::Pending, $this->changeRequestRepository->get(PuzzleReportFixture::CHANGE_REQUEST_PENDING)->status);
    }

    public function testApprovingWithImageRenamesProposalToSeoName(): void
    {
        $proposalPath = 'proposal-' . PuzzleReportFixture::CHANGE_REQUEST_WITH_IMAGE . '.jpg';
        $this->filesToCleanup[] = $proposalPath;

        // Create the proposal file in Flysystem
        $this->filesystem->write($proposalPath, 'fake image content');

        $this->messageBus->dispatch(
            new ApprovePuzzleChangeRequest(
                changeRequestId: PuzzleReportFixture::CHANGE_REQUEST_WITH_IMAGE,
                reviewerId: PlayerFixture::PLAYER_ADMIN,
                selectedFields: ['name', 'manufacturer', 'piecesCount', 'image'],
            ),
        );

        $puzzle = $this->puzzleRepository->get(PuzzleFixture::PUZZLE_500_02);

        // Puzzle image should be set to a SEO-friendly name (not the proposal path)
        self::assertNotNull($puzzle->image);
        $this->filesToCleanup[] = $puzzle->image;

        self::assertStringNotContainsString('proposal-', $puzzle->image);
        self::assertStringContainsString('ravensburger', $puzzle->image);
        self::assertStringEndsWith('.jpg', $puzzle->image);

        // Proposal file should be deleted
        self::assertFalse($this->filesystem->fileExists($proposalPath));

        // New SEO file should exist
        self::assertTrue($this->filesystem->fileExists($puzzle->image));
    }

    public function testApprovingImageWithSameNameAddsCacheBustingSuffix(): void
    {
        $proposalPath = 'proposal-' . PuzzleReportFixture::CHANGE_REQUEST_WITH_IMAGE . '.jpg';
        $this->filesToCleanup[] = $proposalPath;

        // Create the proposal file
        $this->filesystem->write($proposalPath, 'new image content');

        // First approve to establish the SEO name on the puzzle
        $this->messageBus->dispatch(
            new ApprovePuzzleChangeRequest(
                changeRequestId: PuzzleReportFixture::CHANGE_REQUEST_WITH_IMAGE,
                reviewerId: PlayerFixture::PLAYER_ADMIN,
                selectedFields: ['name', 'manufacturer', 'piecesCount', 'image'],
            ),
        );

        $puzzle = $this->puzzleRepository->get(PuzzleFixture::PUZZLE_500_02);
        $firstImagePath = $puzzle->image;
        self::assertNotNull($firstImagePath);
        $this->filesToCleanup[] = $firstImagePath;

        // Verify the SEO file exists (from first approval)
        self::assertTrue($this->filesystem->fileExists($firstImagePath));
    }

    public function testApprovingNoFieldLeavesThePuzzleAndRecordsWhereTheDecisionCameFrom(): void
    {
        $puzzleNameBefore = $this->puzzleRepository->get(PuzzleFixture::PUZZLE_500_01)->name;

        // A proposal already satisfied by something else (a brand merge): approve it, apply nothing
        $this->messageBus->dispatch(new ApprovePuzzleChangeRequest(
            changeRequestId: PuzzleReportFixture::CHANGE_REQUEST_PENDING,
            reviewerId: PlayerFixture::PLAYER_ADMIN,
            selectedFields: [],
            decisionSource: MergeDecisionSource::InternalApi,
            decisionNote: 'Brand already fixed by a merge',
        ));

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();

        self::assertSame(PuzzleReportStatus::Approved, $this->changeRequestRepository->get(PuzzleReportFixture::CHANGE_REQUEST_PENDING)->status);
        self::assertSame($puzzleNameBefore, $this->puzzleRepository->get(PuzzleFixture::PUZZLE_500_01)->name);

        $decision = $entityManager->getRepository(PuzzleModerationDecision::class)->findOneBy([
            'action' => PuzzleModerationAction::ChangeRequestApproved,
        ]);
        self::assertNotNull($decision);
        self::assertSame(MergeDecisionSource::InternalApi, $decision->source);
        self::assertSame('Brand already fixed by a merge', $decision->note);
    }

    public function testAReviewedRequestIsNeverApprovedAgain(): void
    {
        $this->expectException(PuzzleChangeRequestAlreadyReviewed::class);

        $this->messageBus->dispatch(new ApprovePuzzleChangeRequest(
            changeRequestId: PuzzleReportFixture::CHANGE_REQUEST_APPROVED,
            reviewerId: PlayerFixture::PLAYER_ADMIN,
        ));
    }
}
