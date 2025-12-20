<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Message\ApprovePuzzleMergeRequest;
use SpeedPuzzling\Web\Message\SubmitPuzzleMergeRequest;
use SpeedPuzzling\Web\Repository\PuzzleMergeRequestRepository;
use SpeedPuzzling\Web\Repository\PuzzleRepository;
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

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->messageBus = $container->get(MessageBusInterface::class);
        $this->mergeRequestRepository = $container->get(PuzzleMergeRequestRepository::class);
        $this->puzzleRepository = $container->get(PuzzleRepository::class);
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
}
