<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Query;

use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Message\SubmitPuzzleMergeRequest;
use SpeedPuzzling\Web\Query\GetPuzzleMergeReviewQueue;
use SpeedPuzzling\Web\Results\PuzzleMergeReviewItem;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;

final class GetPuzzleMergeReviewQueueTest extends KernelTestCase
{
    private GetPuzzleMergeReviewQueue $query;
    private MessageBusInterface $messageBus;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->query = $container->get(GetPuzzleMergeReviewQueue::class);
        $this->messageBus = $container->get(MessageBusInterface::class);
    }

    public function testPendingReturnsEveryReportedPuzzleWithTheDetailNeededToJudgeIt(): void
    {
        $mergeRequestId = Uuid::uuid7()->toString();

        $this->messageBus->dispatch(
            new SubmitPuzzleMergeRequest(
                mergeRequestId: $mergeRequestId,
                sourcePuzzleId: PuzzleFixture::PUZZLE_500_04,
                reporterId: PlayerFixture::PLAYER_REGULAR,
                duplicatePuzzleIds: [PuzzleFixture::PUZZLE_500_05],
            ),
        );

        $item = $this->findItem($mergeRequestId);

        self::assertNotNull($item, 'Freshly submitted merge request should appear in the pending queue');
        self::assertTrue($item->isActionable());
        self::assertSame([], $item->missingPuzzleIds);
        self::assertCount(2, $item->candidates);

        $puzzleIds = array_map(static fn($candidate): string => $candidate->puzzleId, $item->candidates);
        self::assertEqualsCanonicalizing(
            [PuzzleFixture::PUZZLE_500_04, PuzzleFixture::PUZZLE_500_05],
            $puzzleIds,
        );

        foreach ($item->candidates as $candidate) {
            self::assertNotSame('', $candidate->name);
            self::assertSame(500, $candidate->piecesCount);
            self::assertNotNull($candidate->manufacturerName);
            self::assertGreaterThanOrEqual(0, $candidate->solvedTimesCount);
        }
    }

    public function testCountPendingMatchesTheNumberOfPendingRequests(): void
    {
        $before = $this->query->countPending();

        $this->messageBus->dispatch(
            new SubmitPuzzleMergeRequest(
                mergeRequestId: Uuid::uuid7()->toString(),
                sourcePuzzleId: PuzzleFixture::PUZZLE_500_04,
                reporterId: PlayerFixture::PLAYER_REGULAR,
                duplicatePuzzleIds: [PuzzleFixture::PUZZLE_500_05],
            ),
        );

        self::assertSame($before + 1, $this->query->countPending());
    }

    public function testPendingIsPaged(): void
    {
        // Guarantee at least two pending requests regardless of what the fixtures carry
        $this->messageBus->dispatch(
            new SubmitPuzzleMergeRequest(
                mergeRequestId: Uuid::uuid7()->toString(),
                sourcePuzzleId: PuzzleFixture::PUZZLE_500_04,
                reporterId: PlayerFixture::PLAYER_REGULAR,
                duplicatePuzzleIds: [PuzzleFixture::PUZZLE_500_05],
            ),
        );
        $this->messageBus->dispatch(
            new SubmitPuzzleMergeRequest(
                mergeRequestId: Uuid::uuid7()->toString(),
                sourcePuzzleId: PuzzleFixture::PUZZLE_1000_01,
                reporterId: PlayerFixture::PLAYER_REGULAR,
                duplicatePuzzleIds: [PuzzleFixture::PUZZLE_1000_02],
            ),
        );

        $firstPage = $this->query->pending(limit: 1);
        $secondPage = $this->query->pending(limit: 1, offset: 1);

        self::assertCount(1, $firstPage);
        self::assertCount(1, $secondPage);
        self::assertNotSame($firstPage[0]->mergeRequestId, $secondPage[0]->mergeRequestId);
    }

    private function findItem(string $mergeRequestId): null|PuzzleMergeReviewItem
    {
        foreach ($this->query->pending(limit: 100) as $item) {
            if ($item->mergeRequestId === $mergeRequestId) {
                return $item;
            }
        }

        return null;
    }
}
