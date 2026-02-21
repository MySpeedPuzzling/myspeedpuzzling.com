<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

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

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->messageBus = $container->get(MessageBusInterface::class);
        $this->changeRequestRepository = $container->get(PuzzleChangeRequestRepository::class);
        $this->puzzleRepository = $container->get(PuzzleRepository::class);
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
}
