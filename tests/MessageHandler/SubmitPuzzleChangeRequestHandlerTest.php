<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use League\Flysystem\Filesystem;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Message\SubmitPuzzleChangeRequest;
use SpeedPuzzling\Web\Repository\PuzzleChangeRequestRepository;
use SpeedPuzzling\Web\Tests\DataFixtures\ManufacturerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Value\PuzzleReportStatus;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Messenger\MessageBusInterface;

final class SubmitPuzzleChangeRequestHandlerTest extends KernelTestCase
{
    private MessageBusInterface $messageBus;
    private PuzzleChangeRequestRepository $changeRequestRepository;
    private Filesystem $filesystem;
    /** @var list<string> */
    private array $filesToCleanup = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->messageBus = $container->get(MessageBusInterface::class);
        $this->changeRequestRepository = $container->get(PuzzleChangeRequestRepository::class);
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

    public function testSubmittingChangeRequestCreatesEntity(): void
    {
        $changeRequestId = Uuid::uuid7()->toString();

        $this->messageBus->dispatch(
            new SubmitPuzzleChangeRequest(
                changeRequestId: $changeRequestId,
                puzzleId: PuzzleFixture::PUZZLE_500_01,
                reporterId: PlayerFixture::PLAYER_REGULAR,
                proposedName: 'New Puzzle Name',
                proposedManufacturerId: ManufacturerFixture::MANUFACTURER_TREFL,
                proposedPiecesCount: 600,
                proposedEan: '1234567890123',
                proposedIdentificationNumber: 'NEW-001',
                proposedPhoto: null,
            ),
        );

        $changeRequest = $this->changeRequestRepository->get($changeRequestId);

        // Verify the change request was created with correct values
        self::assertSame(PuzzleReportStatus::Pending, $changeRequest->status);
        self::assertSame('New Puzzle Name', $changeRequest->proposedName);
        self::assertSame(600, $changeRequest->proposedPiecesCount);
        self::assertSame('1234567890123', $changeRequest->proposedEan);
        self::assertSame('NEW-001', $changeRequest->proposedIdentificationNumber);
        self::assertNotNull($changeRequest->proposedManufacturer);
        self::assertSame(ManufacturerFixture::MANUFACTURER_TREFL, $changeRequest->proposedManufacturer->id->toString());

        // Verify original values were captured
        self::assertSame('Puzzle 1', $changeRequest->originalName);
        self::assertSame(500, $changeRequest->originalPiecesCount);
        self::assertNull($changeRequest->reviewedAt);
        self::assertNull($changeRequest->reviewedBy);
    }

    public function testSubmittingChangeRequestWithImageUsesProposalPrefix(): void
    {
        $changeRequestId = Uuid::uuid7()->toString();

        // Create a valid JPEG image file using GD
        $imagePath = tempnam(sys_get_temp_dir(), 'puzzle_test_') . '.jpg';
        $image = imagecreatetruecolor(10, 10);
        assert($image !== false);
        imagejpeg($image, $imagePath);

        $uploadedFile = new UploadedFile($imagePath, 'my-puzzle-photo.jpg', 'image/jpeg', null, true);

        $this->messageBus->dispatch(
            new SubmitPuzzleChangeRequest(
                changeRequestId: $changeRequestId,
                puzzleId: PuzzleFixture::PUZZLE_500_01,
                reporterId: PlayerFixture::PLAYER_REGULAR,
                proposedName: 'Puzzle With Image',
                proposedManufacturerId: ManufacturerFixture::MANUFACTURER_RAVENSBURGER,
                proposedPiecesCount: 500,
                proposedEan: null,
                proposedIdentificationNumber: null,
                proposedPhoto: $uploadedFile,
            ),
        );

        $changeRequest = $this->changeRequestRepository->get($changeRequestId);

        $expectedPath = "proposal-{$changeRequestId}.jpg";
        $this->filesToCleanup[] = $expectedPath;

        self::assertSame($expectedPath, $changeRequest->proposedImage);
        self::assertTrue($this->filesystem->fileExists($expectedPath));
    }

    public function testSubmittingChangeRequestWithoutManufacturerChange(): void
    {
        $changeRequestId = Uuid::uuid7()->toString();

        $this->messageBus->dispatch(
            new SubmitPuzzleChangeRequest(
                changeRequestId: $changeRequestId,
                puzzleId: PuzzleFixture::PUZZLE_500_02,
                reporterId: PlayerFixture::PLAYER_REGULAR,
                proposedName: 'Updated Name Only',
                proposedManufacturerId: null,
                proposedPiecesCount: 500,
                proposedEan: null,
                proposedIdentificationNumber: null,
                proposedPhoto: null,
            ),
        );

        $changeRequest = $this->changeRequestRepository->get($changeRequestId);

        self::assertSame(PuzzleReportStatus::Pending, $changeRequest->status);
        self::assertSame('Updated Name Only', $changeRequest->proposedName);
        self::assertNull($changeRequest->proposedManufacturer);
        self::assertNull($changeRequest->proposedEan);
        self::assertNull($changeRequest->proposedIdentificationNumber);
    }
}
