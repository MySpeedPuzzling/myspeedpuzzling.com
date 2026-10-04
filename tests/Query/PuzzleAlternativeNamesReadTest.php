<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Query;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use SpeedPuzzling\Web\Entity\Puzzle;
use SpeedPuzzling\Web\Query\GetPuzzleOverview;
use SpeedPuzzling\Web\Query\GetPuzzleRecord;
use SpeedPuzzling\Web\Query\GetUnsolvedPuzzles;
use SpeedPuzzling\Web\Query\SearchPuzzle;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Value\PiecesRange;
use SpeedPuzzling\Web\Value\PuzzleNames;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The reads carry every other name of a puzzle (`puzzle.alternative_names`) as PuzzleNames, in order and with their
 * languages; legacyAlternativeName() - what the pages show until the names display ships - is the Czech one even when
 * it is not the first.
 */
final class PuzzleAlternativeNamesReadTest extends KernelTestCase
{
    private const array NAMES = [
        ['name' => 'Puzzle fünfzehn', 'language' => 'de'],
        ['name' => 'Skládačka patnáct', 'language' => 'cs'],
        ['name' => 'Fifteen', 'language' => null],
    ];

    protected function setUp(): void
    {
        self::bootKernel();

        self::changeAlternativeNames(PuzzleFixture::PUZZLE_3000, PuzzleNames::fromArray(self::NAMES));
    }

    public function testPuzzleOverviewCarriesEveryNameInOrder(): void
    {
        $overview = self::getContainer()->get(GetPuzzleOverview::class)->byId(PuzzleFixture::PUZZLE_3000);

        self::assertSame(self::NAMES, $overview->puzzleAlternativeNames->toArray());
        self::assertSame('Skládačka patnáct', $overview->puzzleAlternativeNames->legacyAlternativeName());
    }

    public function testSearchResultsCarryEveryName(): void
    {
        $searchPuzzle = self::getContainer()->get(SearchPuzzle::class);

        $found = $searchPuzzle->byUserInput(null, 'Puzzle 15', PiecesRange::any(), null);
        $byEan = $searchPuzzle->allByEan(PuzzleFixture::EAN_PUZZLE_3000);

        foreach ([...$found, ...$byEan] as $overview) {
            if ($overview->puzzleId === PuzzleFixture::PUZZLE_3000) {
                self::assertSame(self::NAMES, $overview->puzzleAlternativeNames->toArray());
            }
        }

        self::assertContains(PuzzleFixture::PUZZLE_3000, array_map(static fn ($overview): string => $overview->puzzleId, $found));
        self::assertContains(PuzzleFixture::PUZZLE_3000, array_map(static fn ($overview): string => $overview->puzzleId, $byEan));
    }

    public function testModeratorRecordCarriesEveryName(): void
    {
        $record = self::getContainer()->get(GetPuzzleRecord::class)->byId(PuzzleFixture::PUZZLE_3000);

        self::assertNotNull($record);
        self::assertSame(self::NAMES, $record->alternativeNames->toArray());
    }

    public function testGroupedListCarriesEveryName(): void
    {
        // GetUnsolvedPuzzles groups by the puzzle's columns, the jsonb list included
        $unsolved = self::getContainer()->get(GetUnsolvedPuzzles::class);

        $items = array_values(array_filter(
            $unsolved->byPlayerId(PlayerFixture::PLAYER_REGULAR),
            static fn ($item): bool => $item->puzzleId === PuzzleFixture::PUZZLE_3000,
        ));
        $item = $unsolved->byPuzzleIdAndPlayerId(PuzzleFixture::PUZZLE_3000, PlayerFixture::PLAYER_REGULAR);

        self::assertCount(1, $items);
        self::assertSame(self::NAMES, $items[0]->puzzleAlternativeNames->toArray());
        self::assertNotNull($item);
        self::assertSame('Skládačka patnáct', $item->puzzleAlternativeNames->legacyAlternativeName());
    }

    public function testPuzzleWithoutOtherNamesHasAnEmptyList(): void
    {
        self::changeAlternativeNames(PuzzleFixture::PUZZLE_9000, new PuzzleNames());

        $overview = self::getContainer()->get(GetPuzzleOverview::class)->byId(PuzzleFixture::PUZZLE_9000);

        self::assertTrue($overview->puzzleAlternativeNames->isEmpty());
        self::assertNull($overview->puzzleAlternativeNames->legacyAlternativeName());
    }

    private static function changeAlternativeNames(string $puzzleId, PuzzleNames $alternativeNames): void
    {
        /** @var EntityManagerInterface $entityManager */
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $puzzle = $entityManager->find(Puzzle::class, $puzzleId);
        self::assertNotNull($puzzle);

        $puzzle->changeNames($puzzle->name, $puzzle->nameLanguage, $alternativeNames, new DateTimeImmutable());
        $entityManager->flush();
        $entityManager->clear();
    }
}
