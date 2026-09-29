<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Query;

use DateTimeImmutable;
use SpeedPuzzling\Web\Query\GetDifficultyRankings;
use SpeedPuzzling\Web\Results\DifficultyRankingBrand;
use SpeedPuzzling\Web\Results\DifficultyRankingEntry;
use SpeedPuzzling\Web\Tests\DataFixtures\ManufacturerFixture;
use SpeedPuzzling\Web\Tests\DifficultyRankingSeeding;
use SpeedPuzzling\Web\Value\DifficultyRankingDirection;
use SpeedPuzzling\Web\Value\DifficultyTier;
use SpeedPuzzling\Web\Value\MetricConfidence;
use SpeedPuzzling\Web\Value\PuzzleStatisticsData;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class GetDifficultyRankingsTest extends KernelTestCase
{
    use DifficultyRankingSeeding;

    private const string RAVENSBURGER = ManufacturerFixture::MANUFACTURER_RAVENSBURGER;
    private const string TREFL = ManufacturerFixture::MANUFACTURER_TREFL;

    private GetDifficultyRankings $query;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->query = self::getContainer()->get(GetDifficultyRankings::class);
    }

    public function testOnlyApprovedVisiblePuzzlesWithMediumOrHighConfidenceAreRanked(): void
    {
        $container = self::getContainer();

        $medium = $this->seedRatedPuzzle($container, self::RAVENSBURGER, 750, 'Medium confidence', 1.3);
        $high = $this->seedRatedPuzzle($container, self::RAVENSBURGER, 750, 'High confidence', 1.2, MetricConfidence::High, 25);
        $revealed = $this->seedRatedPuzzle($container, self::RAVENSBURGER, 750, 'Revealed yesterday', 1.1, hideUntil: new DateTimeImmutable('-1 day'));
        $this->seedRatedPuzzle($container, self::RAVENSBURGER, 750, 'Low confidence', 1.9, MetricConfidence::Low, 7);
        $this->seedRatedPuzzle($container, self::RAVENSBURGER, 750, 'Not rated yet', null, MetricConfidence::Insufficient, 3);
        $this->seedRatedPuzzle($container, self::RAVENSBURGER, 750, 'Waiting for approval', 1.8, approved: false);
        $this->seedRatedPuzzle($container, self::RAVENSBURGER, 750, 'Hidden until next year', 1.7, hideUntil: new DateTimeImmutable('+1 year'));
        $this->seedRatedPuzzle($container, self::RAVENSBURGER, 1000, 'Other piece count', 1.6);

        self::assertSame([$medium, $high, $revealed], self::ids($this->query->byPieces(750, DifficultyRankingDirection::Hardest, 100)));
        self::assertSame([$revealed, $high, $medium], self::ids($this->query->byPieces(750, DifficultyRankingDirection::Easiest, 100)));
        self::assertSame([750 => 3, 1000 => 1], $this->query->ratedPuzzlesPerPieces([750, 1000, 1500], 1));
    }

    public function testTiesAreBrokenBySampleSizeThenName(): void
    {
        $container = self::getContainer();

        $bravo = $this->seedRatedPuzzle($container, self::RAVENSBURGER, 750, 'Bravo', 1.5);
        $alpha = $this->seedRatedPuzzle($container, self::RAVENSBURGER, 750, 'Alpha', 1.5);
        $charlie = $this->seedRatedPuzzle($container, self::RAVENSBURGER, 750, 'Charlie', 1.5, MetricConfidence::High, 30);
        $delta = $this->seedRatedPuzzle($container, self::RAVENSBURGER, 750, 'Delta', 1.6);

        self::assertSame([$delta, $charlie, $alpha, $bravo], self::ids($this->query->byPieces(750, DifficultyRankingDirection::Hardest, 100)));
        self::assertSame([$charlie, $alpha, $bravo, $delta], self::ids($this->query->byPieces(750, DifficultyRankingDirection::Easiest, 100)));
    }

    public function testLimitCutsTheRanking(): void
    {
        $ids = $this->seedRatedPuzzles(self::getContainer(), self::RAVENSBURGER, 750, 12, 'Limited');

        self::assertSame(array_slice($ids, 0, 5), self::ids($this->query->byPieces(750, DifficultyRankingDirection::Hardest, 5)));
        self::assertSame(array_slice(array_reverse($ids), 0, 5), self::ids($this->query->byPieces(750, DifficultyRankingDirection::Easiest, 5)));
    }

    public function testPiecesCountsBelowTheMinimumAreLeftOut(): void
    {
        $container = self::getContainer();

        $this->seedRatedPuzzles($container, self::RAVENSBURGER, 750, 3, 'Three');
        $this->seedRatedPuzzles($container, self::TREFL, 1500, 2, 'Two');

        self::assertSame([750 => 3], $this->query->ratedPuzzlesPerPieces([750, 1500], 3));
        self::assertSame([750 => 3, 1500 => 2], $this->query->ratedPuzzlesPerPieces([750, 1500], 2));
        self::assertSame([], $this->query->ratedPuzzlesPerPieces([], 1));
    }

    public function testBrandRankingSpansPieceCountsOfThatBrandOnly(): void
    {
        $container = self::getContainer();

        $trefl500 = $this->seedRatedPuzzle($container, self::TREFL, 500, 'Trefl small', 1.4);
        $trefl1000 = $this->seedRatedPuzzle($container, self::TREFL, 1000, 'Trefl large', 1.6);
        $this->seedRatedPuzzle($container, self::RAVENSBURGER, 1000, 'Ravensburger large', 1.9);

        self::assertSame([$trefl1000, $trefl500], self::ids($this->query->byBrand(self::TREFL, DifficultyRankingDirection::Hardest, 100)));
    }

    public function testBrandsNeedApprovalSlugAndEnoughRatedPuzzles(): void
    {
        $container = self::getContainer();

        $this->seedRatedPuzzles($container, self::TREFL, 500, 3, 'Trefl');
        $this->seedRatedPuzzles($container, self::RAVENSBURGER, 1000, 2, 'Ravensburger');
        // Unapproved brand ("unknown-brand") - never gets a list, however many rated puzzles
        $this->seedRatedPuzzles($container, ManufacturerFixture::MANUFACTURER_UNAPPROVED, 1000, 4, 'Unapproved brand');

        $brands = $this->query->brandsWithRatedPuzzles(2);

        self::assertSame(['trefl', 'ravensburger'], array_map(
            static fn (DifficultyRankingBrand $brand): string => $brand->slug,
            $brands,
        ));
        self::assertSame(ManufacturerFixture::MANUFACTURER_TREFL, $brands[0]->brandId);
        self::assertSame('Trefl', $brands[0]->brandName);
        self::assertSame(3, $brands[0]->ratedPuzzlesCount);

        self::assertCount(1, $this->query->brandsWithRatedPuzzles(3));
    }

    public function testEntryCarriesPublicStatisticsAndHidesAnUnreleasedImage(): void
    {
        $container = self::getContainer();

        $withStatistics = $this->seedRatedPuzzle(
            $container,
            self::RAVENSBURGER,
            750,
            'With statistics',
            1.4,
            hideImageUntil: new DateTimeImmutable('+1 year'),
            statistics: new PuzzleStatisticsData(totalCount: 60, soloCount: 57, medianTimeSolo: 3723),
        );
        $this->seedRatedPuzzle($container, self::RAVENSBURGER, 750, 'Without statistics', 1.2);

        $entries = $this->query->byPieces(750, DifficultyRankingDirection::Hardest, 100);

        self::assertSame($withStatistics, $entries[0]->puzzleId);
        self::assertSame('With statistics', $entries[0]->puzzleName);
        self::assertSame('Ravensburger', $entries[0]->manufacturerName);
        self::assertSame(750, $entries[0]->piecesCount);
        self::assertSame(57, $entries[0]->soloSolvesCount);
        self::assertSame(3723, $entries[0]->medianTimeSolo);
        self::assertNull($entries[0]->puzzleImage, 'An image hidden until release must not be listed');
        self::assertSame(1.4, $entries[0]->difficultyScore);
        self::assertSame(DifficultyTier::Hard, $entries[0]->difficultyTier);

        self::assertSame(0, $entries[1]->soloSolvesCount);
        self::assertNull($entries[1]->medianTimeSolo);
        self::assertNotNull($entries[1]->puzzleImage);
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
