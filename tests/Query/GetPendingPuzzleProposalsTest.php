<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Query;

use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Message\SuggestPuzzleName;
use SpeedPuzzling\Web\Query\GetPendingPuzzleProposals;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;

final class GetPendingPuzzleProposalsTest extends KernelTestCase
{
    private GetPendingPuzzleProposals $query;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->query = $container->get(GetPendingPuzzleProposals::class);
    }

    public function testForPuzzleReturnsPendingProposals(): void
    {
        // PUZZLE_500_01 has a pending change request and a pending merge request (as source puzzle)
        $proposals = $this->query->forPuzzle(PuzzleFixture::PUZZLE_500_01);

        self::assertNotEmpty($proposals);
    }

    public function testANamesSuggestionIsSummedUpAsOtherNames(): void
    {
        self::getContainer()->get(MessageBusInterface::class)->dispatch(new SuggestPuzzleName(
            suggestionId: Uuid::uuid7()->toString(),
            puzzleId: PuzzleFixture::PUZZLE_1000_02,
            playerId: PlayerFixture::PLAYER_REGULAR,
            name: 'Jardín mágico',
            language: 'es',
        ));

        $proposals = $this->query->forPuzzle(PuzzleFixture::PUZZLE_1000_02);

        self::assertCount(1, $proposals);
        self::assertSame('Other names', $proposals[0]->summary);
    }

    public function testForPuzzleWithMergeRequestFetchesPuzzleDetails(): void
    {
        // PUZZLE_500_01 has a pending merge request with reported_duplicate_puzzle_ids
        // containing PUZZLE_500_01 and PUZZLE_500_02 - this triggers fetchPuzzleDetails
        // which previously had a parameter ordering bug (UUID passed as timestamp)
        $proposals = $this->query->forPuzzle(PuzzleFixture::PUZZLE_500_01);

        $mergeProposals = array_filter(
            $proposals,
            static fn($p) => $p->type === 'merge_request',
        );

        self::assertNotEmpty($mergeProposals);

        $mergeProposal = reset($mergeProposals);
        self::assertNotEmpty($mergeProposal->mergePuzzles);
    }

    public function testHasPendingForPuzzleReturnsTrueWhenPending(): void
    {
        self::assertTrue($this->query->hasPendingForPuzzle(PuzzleFixture::PUZZLE_500_01));
    }

    public function testHasPendingForPuzzleReturnsFalseWhenNoPending(): void
    {
        // PUZZLE_1000_05 has no pending proposals
        self::assertFalse($this->query->hasPendingForPuzzle(PuzzleFixture::PUZZLE_1000_05));
    }
}
