<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use SpeedPuzzling\Web\Message\RejectPuzzleChangeRequest;
use SpeedPuzzling\Web\Repository\PuzzleChangeRequestRepository;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleReportFixture;
use SpeedPuzzling\Web\Value\PuzzleReportStatus;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;

final class RejectPuzzleChangeRequestHandlerTest extends KernelTestCase
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

    public function testRejectingChangeRequestSetsRejectedStatus(): void
    {
        $changeRequest = $this->changeRequestRepository->get(PuzzleReportFixture::CHANGE_REQUEST_PENDING);

        // Verify initial state
        self::assertSame(PuzzleReportStatus::Pending, $changeRequest->status);
        self::assertNull($changeRequest->rejectionReason);

        // Dispatch the reject message
        $this->messageBus->dispatch(
            new RejectPuzzleChangeRequest(
                changeRequestId: PuzzleReportFixture::CHANGE_REQUEST_PENDING,
                reviewerId: PlayerFixture::PLAYER_ADMIN,
                rejectionReason: 'This change is not accurate',
            ),
        );

        // Refresh entity from database
        $changeRequest = $this->changeRequestRepository->get(PuzzleReportFixture::CHANGE_REQUEST_PENDING);

        // Verify the change request is now rejected
        self::assertSame(PuzzleReportStatus::Rejected, $changeRequest->status);
        self::assertNotNull($changeRequest->reviewedAt);
        self::assertNotNull($changeRequest->reviewedBy);
        self::assertSame('This change is not accurate', $changeRequest->rejectionReason);
    }
}
