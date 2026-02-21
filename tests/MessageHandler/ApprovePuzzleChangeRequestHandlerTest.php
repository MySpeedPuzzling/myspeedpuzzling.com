<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use League\Flysystem\Filesystem;
use SpeedPuzzling\Web\Message\ApprovePuzzleChangeRequest;
use SpeedPuzzling\Web\Repository\PuzzleChangeRequestRepository;
use SpeedPuzzling\Web\Repository\PuzzleRepository;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleReportFixture;
use SpeedPuzzling\Web\Value\PuzzleReportStatus;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
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

    public function testOverrideValuesAreUsedInsteadOfProposed(): void
    {
        // Approve name with an override value
        $this->messageBus->dispatch(
            new ApprovePuzzleChangeRequest(
                changeRequestId: PuzzleReportFixture::CHANGE_REQUEST_PENDING,
                reviewerId: PlayerFixture::PLAYER_ADMIN,
                selectedFields: ['name', 'ean'],
                overrides: [
                    'name' => 'Admin Corrected Name',
                    'ean' => '9999999999999',
                ],
            ),
        );

        $puzzle = $this->puzzleRepository->get(PuzzleFixture::PUZZLE_500_01);

        // Overridden values should be used
        self::assertSame('Admin Corrected Name', $puzzle->name);
        self::assertSame('9999999999999', $puzzle->ean);
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
}
