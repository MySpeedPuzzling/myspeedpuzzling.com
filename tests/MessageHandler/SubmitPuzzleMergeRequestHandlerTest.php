<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Message\SubmitPuzzleMergeRequest;
use SpeedPuzzling\Web\Repository\PuzzleMergeRequestRepository;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Value\PuzzleReportStatus;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;

final class SubmitPuzzleMergeRequestHandlerTest extends KernelTestCase
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

    public function testSubmittingMergeRequestCreatesEntity(): void
    {
        $mergeRequestId = Uuid::uuid7()->toString();

        $this->messageBus->dispatch(
            new SubmitPuzzleMergeRequest(
                mergeRequestId: $mergeRequestId,
                sourcePuzzleId: PuzzleFixture::PUZZLE_1000_01,
                reporterId: PlayerFixture::PLAYER_REGULAR,
                duplicatePuzzleIds: [
                    PuzzleFixture::PUZZLE_1000_02,
                    PuzzleFixture::PUZZLE_1000_03,
                ],
            ),
        );

        $mergeRequest = $this->mergeRequestRepository->get($mergeRequestId);

        // Verify the merge request was created with correct values
        self::assertSame(PuzzleReportStatus::Pending, $mergeRequest->status);
        self::assertSame(PuzzleFixture::PUZZLE_1000_01, $mergeRequest->sourcePuzzle->id->toString());
        self::assertNull($mergeRequest->reviewedAt);
        self::assertNull($mergeRequest->reviewedBy);

        // Verify all puzzle IDs are included (source + duplicates)
        $expectedPuzzleIds = [
            PuzzleFixture::PUZZLE_1000_01,
            PuzzleFixture::PUZZLE_1000_02,
            PuzzleFixture::PUZZLE_1000_03,
        ];
        self::assertCount(3, $mergeRequest->reportedDuplicatePuzzleIds);
        foreach ($expectedPuzzleIds as $expectedId) {
            self::assertContains($expectedId, $mergeRequest->reportedDuplicatePuzzleIds);
        }
    }

    public function testSubmittingMergeRequestIncludesSourcePuzzleAutomatically(): void
    {
        $mergeRequestId = Uuid::uuid7()->toString();

        // Submit without source puzzle in duplicatePuzzleIds
        $this->messageBus->dispatch(
            new SubmitPuzzleMergeRequest(
                mergeRequestId: $mergeRequestId,
                sourcePuzzleId: PuzzleFixture::PUZZLE_1000_04,
                reporterId: PlayerFixture::PLAYER_REGULAR,
                duplicatePuzzleIds: [
                    PuzzleFixture::PUZZLE_1000_05,
                ],
            ),
        );

        $mergeRequest = $this->mergeRequestRepository->get($mergeRequestId);

        // Source puzzle should be included automatically
        self::assertContains(PuzzleFixture::PUZZLE_1000_04, $mergeRequest->reportedDuplicatePuzzleIds);
        self::assertContains(PuzzleFixture::PUZZLE_1000_05, $mergeRequest->reportedDuplicatePuzzleIds);
        self::assertCount(2, $mergeRequest->reportedDuplicatePuzzleIds);
    }
}
