<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use SpeedPuzzling\Web\Message\RejectPuzzleMergeRequest;
use SpeedPuzzling\Web\Repository\PuzzleMergeRequestRepository;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleReportFixture;
use SpeedPuzzling\Web\Value\PuzzleReportStatus;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;

final class RejectPuzzleMergeRequestHandlerTest extends KernelTestCase
{
    private MessageBusInterface $messageBus;
    private PuzzleMergeRequestRepository $mergeRequestRepository;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->messageBus = $container->get(MessageBusInterface::class);
        $this->mergeRequestRepository = $container->get(PuzzleMergeRequestRepository::class);
    }

    public function testRejectingMergeRequestSetsRejectedStatus(): void
    {
        $mergeRequest = $this->mergeRequestRepository->get(PuzzleReportFixture::MERGE_REQUEST_PENDING);

        // Verify initial state
        self::assertSame(PuzzleReportStatus::Pending, $mergeRequest->status);
        self::assertNull($mergeRequest->rejectionReason);

        // Dispatch the reject message
        $this->messageBus->dispatch(
            new RejectPuzzleMergeRequest(
                mergeRequestId: PuzzleReportFixture::MERGE_REQUEST_PENDING,
                reviewerId: PlayerFixture::PLAYER_ADMIN,
                rejectionReason: 'These puzzles are not duplicates',
            ),
        );

        // Refresh entity from database
        $mergeRequest = $this->mergeRequestRepository->get(PuzzleReportFixture::MERGE_REQUEST_PENDING);

        // Verify the merge request is now rejected
        self::assertSame(PuzzleReportStatus::Rejected, $mergeRequest->status);
        self::assertNotNull($mergeRequest->reviewedAt);
        self::assertNotNull($mergeRequest->reviewedBy);
        self::assertSame('These puzzles are not duplicates', $mergeRequest->rejectionReason);
    }
}
