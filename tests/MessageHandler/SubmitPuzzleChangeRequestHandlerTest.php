<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Message\SubmitPuzzleChangeRequest;
use SpeedPuzzling\Web\Repository\PuzzleChangeRequestRepository;
use SpeedPuzzling\Web\Tests\DataFixtures\ManufacturerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Value\PuzzleReportStatus;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;

final class SubmitPuzzleChangeRequestHandlerTest extends KernelTestCase
{
    private MessageBusInterface $messageBus;
    private PuzzleChangeRequestRepository $changeRequestRepository;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->messageBus = $container->get(MessageBusInterface::class);
        $this->changeRequestRepository = $container->get(PuzzleChangeRequestRepository::class);
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
