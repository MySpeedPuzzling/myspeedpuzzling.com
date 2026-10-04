<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Query;

use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\Manufacturer;
use SpeedPuzzling\Web\Entity\Puzzle;
use SpeedPuzzling\Web\Message\ApprovePuzzle;
use SpeedPuzzling\Web\Results\PuzzleDuplicateCandidate;
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

    public function testAnApprovedPuzzleLeavesTheQueue(): void
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

    public function testSimilarPuzzlesOfTheSameBrandComeBeforeTheSameTitleFromAnotherBrand(): void
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $newBrand = $entityManager->find(Manufacturer::class, ManufacturerFixture::MANUFACTURER_UNAPPROVED);
        $trefl = $entityManager->find(Manufacturer::class, ManufacturerFixture::MANUFACTURER_TREFL);

        $sameBrand = new Puzzle(id: Uuid::uuid7(), piecesCount: 1000, name: 'Puzzle 20 Deluxe', approved: true, manufacturer: $newBrand);
        $otherBrand = new Puzzle(id: Uuid::uuid7(), piecesCount: 1000, name: 'Puzzle 20', approved: true, manufacturer: $trefl);
        $entityManager->persist($sameBrand);
        $entityManager->persist($otherBrand);
        $entityManager->flush();

        $candidates = $this->candidatesById($this->query->possibleDuplicates(PuzzleFixture::PUZZLE_UNAPPROVED));
        $order = array_keys($candidates);

        self::assertSame($sameBrand->id->toString(), $order[0]);
        self::assertSame('possible', $candidates[$sameBrand->id->toString()]->likelihood());
        // The same title from another brand is usually another puzzle
        self::assertSame('unlikely', $candidates[$otherBrand->id->toString()]->likelihood());
        self::assertTrue($candidates[$otherBrand->id->toString()]->sameName());

        // Unless that brand is the one the new brand probably duplicates
        $candidates = $this->candidatesById($this->query->possibleDuplicates(
            PuzzleFixture::PUZZLE_UNAPPROVED,
            [ManufacturerFixture::MANUFACTURER_TREFL],
        ));
        self::assertSame('possible', $candidates[$otherBrand->id->toString()]->likelihood());
    }

    /**
     * @param list<PuzzleDuplicateCandidate> $candidates
     *
     * @return array<string, PuzzleDuplicateCandidate>
     */
    private function candidatesById(array $candidates): array
    {
        $byId = [];

        foreach ($candidates as $candidate) {
            $byId[$candidate->puzzleId] = $candidate;
        }

        return $byId;
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
