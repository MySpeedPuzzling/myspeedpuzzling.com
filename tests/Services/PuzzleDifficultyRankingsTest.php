<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services;

use SpeedPuzzling\Web\Results\DifficultyRankingEntry;
use SpeedPuzzling\Web\Services\PuzzleDifficultyRankings;
use SpeedPuzzling\Web\Tests\DataFixtures\ManufacturerFixture;
use SpeedPuzzling\Web\Tests\DifficultyRankingSeeding;
use SpeedPuzzling\Web\Value\DifficultyRankingDirection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class PuzzleDifficultyRankingsTest extends KernelTestCase
{
    use DifficultyRankingSeeding;

    private PuzzleDifficultyRankings $rankings;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->rankings = self::getContainer()->get(PuzzleDifficultyRankings::class);
    }

    public function testPiecesListNeedsFiftyRatedPuzzles(): void
    {
        $container = self::getContainer();

        $this->seedRatedPuzzles($container, ManufacturerFixture::MANUFACTURER_RAVENSBURGER, 750, PuzzleDifficultyRankings::MINIMUM_PUZZLES_PER_PIECES - 1, 'Almost');
        self::assertNull($this->rankings->forPieces(750, DifficultyRankingDirection::Hardest, true));
        self::assertSame([], $this->rankings->availability()->piecesCounts());

        $this->seedRatedPuzzles($container, ManufacturerFixture::MANUFACTURER_TREFL, 750, 1, 'Fiftieth');
        $this->clearDifficultyRankingsCache($container);

        $ranking = $this->rankings->forPieces(750, DifficultyRankingDirection::Hardest, true);
        self::assertNotNull($ranking);
        self::assertSame(PuzzleDifficultyRankings::MINIMUM_PUZZLES_PER_PIECES, $ranking->ratedPuzzlesCount);
        self::assertSame([750], $this->rankings->availability()->piecesCounts());
    }

    public function testOnlyHubPieceCountsGetAList(): void
    {
        // 751 is not one of the piece counts with a hub page
        $this->seedRatedPuzzles(self::getContainer(), ManufacturerFixture::MANUFACTURER_RAVENSBURGER, 751, 60, 'Odd size');

        self::assertNull($this->rankings->forPieces(751, DifficultyRankingDirection::Hardest, true));
    }

    public function testBrandListNeedsFortyRatedPuzzles(): void
    {
        $container = self::getContainer();

        $this->seedRatedPuzzles($container, ManufacturerFixture::MANUFACTURER_TREFL, 500, PuzzleDifficultyRankings::MINIMUM_PUZZLES_PER_BRAND - 1, 'Trefl');
        self::assertNull($this->rankings->availability()->brand('trefl'));

        $this->seedRatedPuzzles($container, ManufacturerFixture::MANUFACTURER_TREFL, 1000, 1, 'Trefl large');
        $this->clearDifficultyRankingsCache($container);

        $brand = $this->rankings->availability()->brand('trefl');
        self::assertNotNull($brand);
        self::assertSame(PuzzleDifficultyRankings::MINIMUM_PUZZLES_PER_BRAND, $brand->ratedPuzzlesCount);

        $ranking = $this->rankings->forBrand($brand, DifficultyRankingDirection::Easiest, true);
        self::assertCount(PuzzleDifficultyRankings::MINIMUM_PUZZLES_PER_BRAND, $ranking->entries);
        self::assertSame(DifficultyRankingDirection::Easiest, $ranking->direction);
    }

    public function testMembersGetDifficultyAndEverybodyElseTheFirstNamesOnly(): void
    {
        $ids = $this->seedRatedPuzzles(self::getContainer(), ManufacturerFixture::MANUFACTURER_RAVENSBURGER, 750, 105, 'Ranked', highestScore: 2.4, step: 0.01);

        $members = $this->rankings->forPieces(750, DifficultyRankingDirection::Hardest, true);
        self::assertNotNull($members);
        self::assertTrue($members->withDifficulty);
        self::assertSame(105, $members->ratedPuzzlesCount);
        self::assertCount(PuzzleDifficultyRankings::MEMBERS_LIMIT, $members->entries);
        self::assertSame(array_slice($ids, 0, PuzzleDifficultyRankings::MEMBERS_LIMIT), self::ids($members->entries));
        self::assertSame(2.4, $members->entries[0]->difficultyScore);
        self::assertNotNull($members->entries[0]->difficultyTier);

        $public = $this->rankings->forPieces(750, DifficultyRankingDirection::Hardest, false);
        self::assertNotNull($public);
        self::assertFalse($public->withDifficulty);
        self::assertSame(105, $public->ratedPuzzlesCount);
        self::assertSame(array_slice($ids, 0, PuzzleDifficultyRankings::PUBLIC_LIMIT), self::ids($public->entries));

        foreach ($public->entries as $entry) {
            self::assertNull($entry->difficultyScore);
            self::assertNull($entry->difficultyTier);
        }
    }

    public function testListsAreCachedUntilTheCacheIsCleared(): void
    {
        $container = self::getContainer();
        $ids = $this->seedRatedPuzzles($container, ManufacturerFixture::MANUFACTURER_RAVENSBURGER, 750, 50, 'Cached');

        $first = $this->rankings->forPieces(750, DifficultyRankingDirection::Hardest, true);
        self::assertNotNull($first);
        self::assertSame($ids[0], $first->entries[0]->puzzleId);

        // A new hardest puzzle is not visible until the cached list expires
        $newHardest = $this->seedRatedPuzzle($container, ManufacturerFixture::MANUFACTURER_RAVENSBURGER, 750, 'Newcomer', 4.5);

        $cached = $this->rankings->forPieces(750, DifficultyRankingDirection::Hardest, true);
        self::assertNotNull($cached);
        self::assertSame($ids[0], $cached->entries[0]->puzzleId);
        self::assertSame(50, $cached->ratedPuzzlesCount);

        $this->clearDifficultyRankingsCache($container);

        $fresh = $this->rankings->forPieces(750, DifficultyRankingDirection::Hardest, true);
        self::assertNotNull($fresh);
        self::assertSame($newHardest, $fresh->entries[0]->puzzleId);
        self::assertSame(51, $fresh->ratedPuzzlesCount);
    }

    /**
     * @param list<DifficultyRankingEntry> $entries
     * @return list<string>
     */
    private static function ids(array $entries): array
    {
        return array_map(static fn (DifficultyRankingEntry $entry): string => $entry->puzzleId, $entries);
    }
}
