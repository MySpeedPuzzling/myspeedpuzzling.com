<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Query;

use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Message\SubmitPuzzleChangeRequest;
use SpeedPuzzling\Web\Query\GetPendingPuzzleProposals;
use SpeedPuzzling\Web\Repository\PuzzleRepository;
use SpeedPuzzling\Web\Tests\ProposesPuzzleNames;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;

final class GetPendingPuzzleProposalsTest extends KernelTestCase
{
    use ProposesPuzzleNames;

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
        self::proposeOtherName(PuzzleFixture::PUZZLE_1000_02, PlayerFixture::PLAYER_REGULAR, 'Jardín mágico', 'es');

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

    public function testAPendingChangeOfMoreThanTheNamesOrAMergeBlocksANewProposal(): void
    {
        // A change request proposing an EAN (PuzzleReportFixture), a merge request
        self::assertTrue($this->query->blocksNewProposal(PuzzleFixture::PUZZLE_500_01));
        self::assertTrue($this->query->blocksNewProposal(PuzzleFixture::PUZZLE_500_02));
        self::assertFalse($this->query->blocksNewProposal(PuzzleFixture::PUZZLE_1000_05));
    }

    public function testPendingChangesOfTheNamesOnlyBlockNothing(): void
    {
        $messageBus = self::getContainer()->get(MessageBusInterface::class);

        // Two names suggested, and a "Suggest a change" of the main title only - all waiting at once
        foreach ([['Jardín mágico', 'es'], ['Giardino magico', 'it']] as [$name, $language]) {
            self::proposeOtherName(PuzzleFixture::PUZZLE_1000_02, PlayerFixture::PLAYER_REGULAR, $name, $language);
        }

        $puzzle = self::getContainer()->get(PuzzleRepository::class)->get(PuzzleFixture::PUZZLE_1000_02);
        $messageBus->dispatch(new SubmitPuzzleChangeRequest(
            changeRequestId: Uuid::uuid7()->toString(),
            puzzleId: PuzzleFixture::PUZZLE_1000_02,
            reporterId: PlayerFixture::PLAYER_REGULAR,
            proposedName: 'Magic Garden',
            proposedBrand: $puzzle->manufacturer?->id->toString(),
            proposedPiecesCount: $puzzle->piecesCount,
            proposedEans: $puzzle->eans(),
            proposedBrandCodes: $puzzle->brandCodes(),
            proposedPhoto: null,
            originalAlternativeNames: $puzzle->alternativeNames(),
            originalNameLanguage: $puzzle->nameLanguage,
        ));

        self::assertCount(3, $this->query->forPuzzle(PuzzleFixture::PUZZLE_1000_02));
        self::assertTrue($this->query->hasPendingForPuzzle(PuzzleFixture::PUZZLE_1000_02), 'The puzzle page still says a proposal waits');
        self::assertFalse($this->query->blocksNewProposal(PuzzleFixture::PUZZLE_1000_02));
    }
}
