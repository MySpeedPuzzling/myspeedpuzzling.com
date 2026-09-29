<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Query;

use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Query\GetRelatedPuzzles;
use SpeedPuzzling\Web\Results\RelatedPuzzle;
use SpeedPuzzling\Web\Results\RelatedPuzzles;
use SpeedPuzzling\Web\Tests\DataFixtures\ManufacturerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Fixtures: Ravensburger has 8 visible 500-piece puzzles - Puzzle 2 (10 solves), Puzzle 3 (8),
 * Intel Test Puzzle A and B (5 each), Puzzle 1 (11) and three unsolved API puzzles - and 6 visible
 * 1000-piece ones. Trefl has at most 2 puzzles of a size. Puzzles added here roll back with the test.
 */
final class GetRelatedPuzzlesTest extends KernelTestCase
{
    private const string INTEL_A = '018d0008-0000-0000-0000-000000000001';

    private const string INTEL_B = '018d0008-0000-0000-0000-000000000002';

    private GetRelatedPuzzles $getRelatedPuzzles;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->getRelatedPuzzles = self::getContainer()->get(GetRelatedPuzzles::class);
    }

    public function testMostSolvedThreeThenThreePickedForThisPage(): void
    {
        $solvedFillers = [];
        for ($i = 1; $i <= 6; $i++) {
            $solvedFillers[] = $this->addPuzzle(ManufacturerFixture::MANUFACTURER_RAVENSBURGER, 500, solves: 1);
        }
        // Never solved: not one of the picked ones
        $this->addPuzzle(ManufacturerFixture::MANUFACTURER_RAVENSBURGER, 500, solves: 0);

        $related = $this->forPuzzle(PuzzleFixture::PUZZLE_500_01, 500);

        self::assertTrue($related->samePiecesCount);
        self::assertCount(6, $related->puzzles);

        // The combination's most solved (a tie by solves goes by name: Intel Test Puzzle A before B)
        self::assertSame(
            [PuzzleFixture::PUZZLE_500_02, PuzzleFixture::PUZZLE_500_03, self::INTEL_A],
            array_slice(self::ids($related), 0, 3),
        );

        // ... then three solved ones picked by md5(id || current puzzle id)
        self::assertSame(
            self::pickedBy(PuzzleFixture::PUZZLE_500_01, [self::INTEL_B, ...$solvedFillers]),
            array_slice(self::ids($related), 3),
        );

        foreach ($related->puzzles as $puzzle) {
            self::assertSame(500, $puzzle->piecesCount);
        }

        // The same page always gets the same puzzles ...
        self::assertSame(self::ids($related), self::ids($this->forPuzzle(PuzzleFixture::PUZZLE_500_01, 500)));

        // ... another page of the combination its own picks
        $otherPage = $this->forPuzzle(PuzzleFixture::PUZZLE_500_02, 500);
        self::assertSame(
            self::pickedBy(PuzzleFixture::PUZZLE_500_02, [self::INTEL_B, ...$solvedFillers]),
            array_slice(self::ids($otherPage), 3),
        );
    }

    public function testNeverTheCurrentPuzzleNorUnapprovedOrHiddenOnes(): void
    {
        $unapproved = $this->addPuzzle(ManufacturerFixture::MANUFACTURER_RAVENSBURGER, 500, solves: 1000, approved: false);
        $secret = $this->addPuzzle(ManufacturerFixture::MANUFACTURER_RAVENSBURGER, 500, solves: 999, hidden: true);

        $ids = self::ids($this->forPuzzle(PuzzleFixture::PUZZLE_500_02, 500));

        self::assertNotContains(PuzzleFixture::PUZZLE_500_02, $ids);
        self::assertNotContains($unapproved, $ids);
        self::assertNotContains($secret, $ids);
        // The most solved one is Puzzle 1 now (11 solves), the current Puzzle 2 is out
        self::assertSame(PuzzleFixture::PUZZLE_500_01, $ids[0]);
    }

    public function testSmallCombinationFallsBackToTheWholeBrand(): void
    {
        // Trefl has one other 1000-piece puzzle: too few, so the module takes all of Trefl
        $related = $this->forPuzzle(PuzzleFixture::PUZZLE_1000_04, 1000, ManufacturerFixture::MANUFACTURER_TREFL);

        self::assertFalse($related->samePiecesCount);
        // Puzzle 7 (5 solves, 1000 pieces), Puzzle 5 (2, 500), Puzzle 13 (1, 1500) - nothing else of Trefl was solved
        self::assertSame(
            [PuzzleFixture::PUZZLE_1000_02, PuzzleFixture::PUZZLE_500_05, PuzzleFixture::PUZZLE_1500_02],
            self::ids($related),
        );
        self::assertSame([1000, 500, 1500], array_map(static fn (RelatedPuzzle $puzzle): int => $puzzle->piecesCount, $related->puzzles));
    }

    public function testSixOtherPuzzlesOfTheCombinationAreEnough(): void
    {
        // Ravensburger 1000: five other visible puzzles - the whole brand
        self::assertFalse($this->forPuzzle(PuzzleFixture::PUZZLE_1000_01, 1000)->samePiecesCount);

        $this->addPuzzle(ManufacturerFixture::MANUFACTURER_RAVENSBURGER, 1000, solves: 0);

        // A combination of 7 puzzles: six others, enough for the module
        $related = $this->forPuzzle(PuzzleFixture::PUZZLE_1000_01, 1000);
        self::assertTrue($related->samePiecesCount);

        foreach ($related->puzzles as $puzzle) {
            self::assertSame(1000, $puzzle->piecesCount);
        }
    }

    private function forPuzzle(string $puzzleId, int $piecesCount, string $manufacturerId = ManufacturerFixture::MANUFACTURER_RAVENSBURGER): RelatedPuzzles
    {
        return $this->getRelatedPuzzles->forPuzzle($manufacturerId, $piecesCount, $puzzleId);
    }

    private function addPuzzle(string $manufacturerId, int $piecesCount, int $solves, bool $approved = true, bool $hidden = false): string
    {
        $puzzleId = Uuid::uuid7()->toString();
        $database = self::getContainer()->get(Connection::class);

        $database->executeStatement(
            "INSERT INTO puzzle (id, pieces_count, name, approved, manufacturer_id, is_available, hide_until)
             VALUES (:id, :piecesCount, :name, :approved, :manufacturerId, true, CASE WHEN :hidden THEN now() + interval '30 days' END)",
            [
                'id' => $puzzleId,
                'piecesCount' => $piecesCount,
                'name' => 'Zz related ' . $puzzleId,
                'approved' => $approved ? 'true' : 'false',
                'manufacturerId' => $manufacturerId,
                'hidden' => $hidden ? 'true' : 'false',
            ],
        );

        $database->executeStatement(
            'INSERT INTO puzzle_statistics (puzzle_id, solved_times_count) VALUES (:id, :solves)',
            ['id' => $puzzleId, 'solves' => $solves],
        );

        return $puzzleId;
    }

    /**
     * @param list<string> $candidateIds
     * @return list<string> The three the query picks for this page: ordered by md5(id || current puzzle id)
     */
    private static function pickedBy(string $currentPuzzleId, array $candidateIds): array
    {
        usort($candidateIds, static fn (string $a, string $b): int => strcmp(md5($a . $currentPuzzleId), md5($b . $currentPuzzleId)));

        return array_slice($candidateIds, 0, GetRelatedPuzzles::PICKED_COUNT);
    }

    /**
     * @return list<string>
     */
    private static function ids(RelatedPuzzles $related): array
    {
        return array_map(static fn (RelatedPuzzle $puzzle): string => $puzzle->puzzleId, $related->puzzles);
    }
}
