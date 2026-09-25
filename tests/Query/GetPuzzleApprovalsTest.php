<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Query;

use SpeedPuzzling\Web\Message\ApprovePuzzle;
use SpeedPuzzling\Web\Query\GetPuzzleApprovals;
use SpeedPuzzling\Web\Tests\DataFixtures\ManufacturerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Value\PuzzleApprovalBrandChoice;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;

final class GetPuzzleApprovalsTest extends KernelTestCase
{
    private GetPuzzleApprovals $query;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->query = self::getContainer()->get(GetPuzzleApprovals::class);
    }

    public function testPendingListsOnlyUnapprovedPuzzles(): void
    {
        $pending = $this->query->pending();

        $ids = array_map(static fn($puzzle) => $puzzle->puzzleId, $pending);
        self::assertContains(PuzzleFixture::PUZZLE_UNAPPROVED, $ids);
        self::assertNotContains(PuzzleFixture::PUZZLE_500_01, $ids);
        self::assertSame(count($pending), $this->query->countPending());

        $unapproved = $this->query->byPuzzleId(PuzzleFixture::PUZZLE_UNAPPROVED);
        self::assertNotNull($unapproved);
        self::assertFalse($unapproved->approved);
        self::assertFalse($unapproved->manufacturerApproved);
        self::assertSame(PlayerFixture::PLAYER_REGULAR, $unapproved->addedById);
    }

    public function testAnApprovalMovesThePuzzleToTheApprovedTab(): void
    {
        self::getContainer()->get(MessageBusInterface::class)->dispatch(new ApprovePuzzle(
            puzzleId: PuzzleFixture::PUZZLE_UNAPPROVED,
            reviewerId: PlayerFixture::PLAYER_ADMIN,
            name: 'Puzzle 20',
            piecesCount: 1000,
            ean: null,
            identificationNumber: null,
            brandChoice: PuzzleApprovalBrandChoice::UseExisting,
            targetManufacturerId: ManufacturerFixture::MANUFACTURER_RAVENSBURGER,
        ));

        self::assertNull($this->findPending(PuzzleFixture::PUZZLE_UNAPPROVED));

        $approved = $this->query->recentlyApproved();
        self::assertSame(1, $this->query->countApproved());
        self::assertSame(PuzzleFixture::PUZZLE_UNAPPROVED, $approved[0]->puzzleId);
        self::assertTrue($approved[0]->puzzleExists);
        self::assertSame(PlayerFixture::PLAYER_ADMIN, $approved[0]->decidedById);
    }

    public function testPossibleDuplicatesAndBrandSuggestionsRun(): void
    {
        // SQL validity on the fixture data and never the puzzle / brand itself - the
        // similarity ranking is Postgres' job
        $duplicateIds = array_map(
            static fn($candidate) => $candidate->puzzleId,
            $this->query->possibleDuplicates(PuzzleFixture::PUZZLE_UNAPPROVED),
        );
        self::assertNotContains(PuzzleFixture::PUZZLE_UNAPPROVED, $duplicateIds);

        $suggestedIds = array_map(
            static fn($suggestion) => $suggestion->manufacturerId,
            $this->query->brandSuggestions(ManufacturerFixture::MANUFACTURER_UNAPPROVED, '4005556000000'),
        );
        self::assertNotContains(ManufacturerFixture::MANUFACTURER_UNAPPROVED, $suggestedIds);
    }

    private function findPending(string $puzzleId): null|object
    {
        foreach ($this->query->pending() as $puzzle) {
            if ($puzzle->puzzleId === $puzzleId) {
                return $puzzle;
            }
        }

        return null;
    }
}
