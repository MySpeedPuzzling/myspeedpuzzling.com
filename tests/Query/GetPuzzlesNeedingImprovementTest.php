<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Query;

use SpeedPuzzling\Web\Query\GetPuzzlesNeedingImprovement;
use SpeedPuzzling\Web\Results\BrandNeedingImprovement;
use SpeedPuzzling\Web\Results\PuzzleNeedingImprovement;
use SpeedPuzzling\Web\Tests\ChangesPuzzleRecords;
use SpeedPuzzling\Web\Tests\DataFixtures\ManufacturerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class GetPuzzlesNeedingImprovementTest extends KernelTestCase
{
    use ChangesPuzzleRecords;

    private GetPuzzlesNeedingImprovement $query;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->query = self::getContainer()->get(GetPuzzlesNeedingImprovement::class);
    }

    public function testListsApprovedVisiblePuzzlesWithoutEanOnly(): void
    {
        $ids = $this->ids($this->query->missingEan(null, 1000, 0));

        self::assertContains(PuzzleFixture::PUZZLE_9000, $ids);
        self::assertNotContains(PuzzleFixture::PUZZLE_300, $ids, 'has an EAN');
        self::assertNotContains(PuzzleFixture::PUZZLE_UNAPPROVED, $ids);
        self::assertNotContains(PuzzleFixture::PUZZLE_HIDDEN_IMAGE, $ids);
        self::assertSame(count($ids), $this->query->countMissingEan(null));
    }

    public function testFiltersByBrand(): void
    {
        $ravensburger = $this->ids($this->query->missingEan(ManufacturerFixture::MANUFACTURER_RAVENSBURGER, 1000, 0));
        $trefl = $this->ids($this->query->missingEan(ManufacturerFixture::MANUFACTURER_TREFL, 1000, 0));

        self::assertContains(PuzzleFixture::PUZZLE_9000, $ravensburger);
        self::assertNotContains(PuzzleFixture::PUZZLE_9000, $trefl);
        self::assertSame(count($ravensburger), $this->query->countMissingEan(ManufacturerFixture::MANUFACTURER_RAVENSBURGER));

        $brandIds = array_map(static fn (BrandNeedingImprovement $brand): string => $brand->manufacturerId, $this->query->brandsMissingEan(ManufacturerFixture::MANUFACTURER_RAVENSBURGER));
        self::assertContains(ManufacturerFixture::MANUFACTURER_RAVENSBURGER, $brandIds);
    }

    public function testMarksAPuzzleWhoseEanWaitsForReview(): void
    {
        // CHANGE_REQUEST_PENDING proposes an EAN for PUZZLE_500_01
        self::changePuzzleEan(PuzzleFixture::PUZZLE_500_01, null);

        $rows = [];
        foreach ($this->query->missingEan(null, 1000, 0) as $row) {
            $rows[$row->puzzleId] = $row;
        }

        self::assertTrue($rows[PuzzleFixture::PUZZLE_500_01]->eanProposed);
        self::assertFalse($rows[PuzzleFixture::PUZZLE_9000]->eanProposed);
    }

    /**
     * @param list<PuzzleNeedingImprovement> $rows
     * @return list<string>
     */
    private function ids(array $rows): array
    {
        return array_map(static fn (PuzzleNeedingImprovement $row): string => $row->puzzleId, $rows);
    }
}
