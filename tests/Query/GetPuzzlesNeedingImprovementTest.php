<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Query;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Query\GetPuzzlesNeedingImprovement;
use SpeedPuzzling\Web\Results\BrandNeedingImprovement;
use SpeedPuzzling\Web\Results\PuzzleNeedingImprovement;
use SpeedPuzzling\Web\Tests\ChangesPuzzleRecords;
use SpeedPuzzling\Web\Tests\DataFixtures\ManufacturerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Tests\SeedsCropTrustAnalyses;
use SpeedPuzzling\Web\Value\ImprovementFilter;
use SpeedPuzzling\Web\Value\ImprovementNeed;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Puzzles with an EAN that the seeded crop_trust analyses mark (SeedsCropTrustAnalyses::seedImageMarks()):
 * PUZZLE_300 and PUZZLE_2000 need a better image, PUZZLE_500_02 too (with a pending proposal of a new photo),
 * PUZZLE_1500_01 has the wrong picture, PUZZLE_6000's mark describes an older picture, PUZZLE_3000 is fine.
 * PLAYER_REGULAR's collections hold PUZZLE_1500_01, PUZZLE_2000 (not PUZZLE_300, PUZZLE_9000).
 */
final class GetPuzzlesNeedingImprovementTest extends KernelTestCase
{
    use ChangesPuzzleRecords;
    use SeedsCropTrustAnalyses;

    private GetPuzzlesNeedingImprovement $query;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->query = self::getContainer()->get(GetPuzzlesNeedingImprovement::class);
        self::seedImageMarks(self::getContainer()->get(Connection::class));
    }

    public function testEanFilterListsApprovedVisiblePuzzlesWithoutEanOnly(): void
    {
        $filter = new ImprovementFilter(need: ImprovementNeed::Ean);
        $ids = $this->ids($this->query->page($filter, 1000, 0));

        self::assertContains(PuzzleFixture::PUZZLE_9000, $ids);
        self::assertNotContains(PuzzleFixture::PUZZLE_300, $ids, 'has an EAN');
        self::assertNotContains(PuzzleFixture::PUZZLE_UNAPPROVED, $ids);
        self::assertNotContains(PuzzleFixture::PUZZLE_HIDDEN_IMAGE, $ids);
        self::assertSame(count($ids), $this->query->count($filter));
    }

    public function testImageFilterListsCurrentResolutionMarksOnly(): void
    {
        $filter = new ImprovementFilter(need: ImprovementNeed::Image);
        $rows = $this->byId($this->query->page($filter, 1000, 0));

        self::assertSame([PuzzleFixture::PUZZLE_500_02, PuzzleFixture::PUZZLE_300, PuzzleFixture::PUZZLE_2000], $this->sorted(array_keys($rows)));
        self::assertSame(3, $this->query->count($filter));
        self::assertTrue($rows[PuzzleFixture::PUZZLE_300]->imageNeedsImprovement);
        self::assertFalse($rows[PuzzleFixture::PUZZLE_300]->eanMissing);
        self::assertFalse($rows[PuzzleFixture::PUZZLE_300]->imageProposed);
        // CHANGE_REQUEST_WITH_IMAGE proposes a new photo of PUZZLE_500_02
        self::assertTrue($rows[PuzzleFixture::PUZZLE_500_02]->imageProposed);
    }

    public function testAStaleAnalysisIsIgnored(): void
    {
        $ids = $this->ids($this->query->page(new ImprovementFilter(viewerModerates: true), 1000, 0));

        self::assertNotContains(PuzzleFixture::PUZZLE_6000, $ids, 'its mark describes the picture it had before');
        self::assertNotContains(PuzzleFixture::PUZZLE_3000, $ids, 'nothing to improve');
    }

    public function testAllListsEveryNeedInOneRowEach(): void
    {
        $filter = new ImprovementFilter();
        $ids = $this->ids($this->query->page($filter, 1000, 0));

        self::assertContains(PuzzleFixture::PUZZLE_9000, $ids);
        self::assertContains(PuzzleFixture::PUZZLE_300, $ids);
        self::assertCount(count($ids), array_unique($ids));
        self::assertSame(count($ids), $this->query->count($filter));
        self::assertSame(
            $this->query->count(new ImprovementFilter(need: ImprovementNeed::Ean)) + $this->query->count(new ImprovementFilter(need: ImprovementNeed::Image)),
            $this->query->count($filter),
            'no fixture puzzle misses both',
        );
    }

    public function testWrongPictureIsForModeratorsOnly(): void
    {
        $player = $this->byId($this->query->page(new ImprovementFilter(), 1000, 0));
        self::assertArrayNotHasKey(PuzzleFixture::PUZZLE_1500_01, $player);
        foreach ($player as $row) {
            self::assertFalse($row->wrongPicture);
        }
        self::assertSame(0, $this->query->count(new ImprovementFilter(need: ImprovementNeed::WrongPicture)), 'even when asked for');
        self::assertNotContains(PuzzleFixture::PUZZLE_1500_01, $this->ids($this->query->page(new ImprovementFilter(need: ImprovementNeed::Image), 1000, 0)));

        $moderator = $this->byId($this->query->page(new ImprovementFilter(viewerModerates: true), 1000, 0));
        self::assertTrue($moderator[PuzzleFixture::PUZZLE_1500_01]->wrongPicture);
        self::assertFalse($moderator[PuzzleFixture::PUZZLE_1500_01]->imageNeedsImprovement);

        $wrongOnly = new ImprovementFilter(need: ImprovementNeed::WrongPicture, viewerModerates: true);
        self::assertSame([PuzzleFixture::PUZZLE_1500_01], $this->ids($this->query->page($wrongOnly, 1000, 0)));
        self::assertSame(1, $this->query->count($wrongOnly));
        self::assertContains(PuzzleFixture::PUZZLE_1500_01, $this->ids($this->query->page(new ImprovementFilter(need: ImprovementNeed::Image, viewerModerates: true), 1000, 0)));
    }

    public function testFiltersByBrand(): void
    {
        $ravensburger = $this->ids($this->query->page(new ImprovementFilter(manufacturerId: ManufacturerFixture::MANUFACTURER_RAVENSBURGER), 1000, 0));
        $trefl = $this->ids($this->query->page(new ImprovementFilter(manufacturerId: ManufacturerFixture::MANUFACTURER_TREFL), 1000, 0));

        self::assertContains(PuzzleFixture::PUZZLE_9000, $ravensburger);
        self::assertNotContains(PuzzleFixture::PUZZLE_9000, $trefl);
        self::assertSame(count($ravensburger), $this->query->count(new ImprovementFilter(manufacturerId: ManufacturerFixture::MANUFACTURER_RAVENSBURGER)));
    }

    public function testBrandChoicesFollowTheNeedFilter(): void
    {
        // Image: Ravensburger has PUZZLE_300, PUZZLE_500_02, PUZZLE_2000 - exactly 3
        $image = $this->brandCounts($this->query->brands(new ImprovementFilter(need: ImprovementNeed::Image)));
        self::assertSame([ManufacturerFixture::MANUFACTURER_RAVENSBURGER => 3], $image);

        $ean = $this->brandCounts($this->query->brands(new ImprovementFilter(need: ImprovementNeed::Ean)));
        self::assertSame($this->query->count(new ImprovementFilter(need: ImprovementNeed::Ean, manufacturerId: ManufacturerFixture::MANUFACTURER_RAVENSBURGER)), $ean[ManufacturerFixture::MANUFACTURER_RAVENSBURGER]);

        // The selected brand stays a choice even below the minimum
        $selected = $this->brandCounts($this->query->brands(new ImprovementFilter(need: ImprovementNeed::Image, manufacturerId: ManufacturerFixture::MANUFACTURER_TREFL)));
        self::assertArrayNotHasKey(ManufacturerFixture::MANUFACTURER_TREFL, $selected, 'Trefl has no puzzle needing a better image');
    }

    public function testCollectionFilterKeepsThePlayersPuzzlesOnly(): void
    {
        $filter = new ImprovementFilter(need: ImprovementNeed::Image, collectionPlayerId: PlayerFixture::PLAYER_REGULAR);
        self::assertSame([PuzzleFixture::PUZZLE_500_02, PuzzleFixture::PUZZLE_2000], $this->sorted($this->ids($this->query->page($filter, 1000, 0))));
        self::assertSame(2, $this->query->count($filter));
        self::assertSame([ManufacturerFixture::MANUFACTURER_RAVENSBURGER => 2], $this->brandCounts($this->query->brands($filter)));

        $all = $this->ids($this->query->page(new ImprovementFilter(collectionPlayerId: PlayerFixture::PLAYER_REGULAR), 1000, 0));
        self::assertNotContains(PuzzleFixture::PUZZLE_9000, $all, 'not in the collections');
        self::assertNotContains(PuzzleFixture::PUZZLE_300, $all, 'not in the collections');
        // In the private collection and the system one alike
        self::assertContains(PuzzleFixture::PUZZLE_2000, $all);
        self::assertContains(PuzzleFixture::PUZZLE_1000_02, $all);
    }

    public function testMarksAPuzzleWhoseEanWaitsForReview(): void
    {
        // CHANGE_REQUEST_PENDING proposes an EAN for PUZZLE_500_01
        self::changePuzzleEan(PuzzleFixture::PUZZLE_500_01, null);

        $rows = $this->byId($this->query->page(new ImprovementFilter(need: ImprovementNeed::Ean), 1000, 0));

        self::assertTrue($rows[PuzzleFixture::PUZZLE_500_01]->eanProposed);
        self::assertFalse($rows[PuzzleFixture::PUZZLE_9000]->eanProposed);
    }

    public function testHintForOnePuzzle(): void
    {
        $missingEan = $this->query->hintForPuzzle(PuzzleFixture::PUZZLE_9000, false);
        self::assertNotNull($missingEan);
        self::assertTrue($missingEan->eanMissing);
        self::assertFalse($missingEan->imageNeedsImprovement);

        $image = $this->query->hintForPuzzle(PuzzleFixture::PUZZLE_300, false);
        self::assertNotNull($image);
        self::assertFalse($image->eanMissing);
        self::assertTrue($image->imageNeedsImprovement);

        $wrongForPlayer = $this->query->hintForPuzzle(PuzzleFixture::PUZZLE_1500_01, false);
        self::assertNotNull($wrongForPlayer);
        self::assertTrue($wrongForPlayer->isEmpty());
        self::assertTrue($this->query->hintForPuzzle(PuzzleFixture::PUZZLE_1500_01, true)?->wrongPicture);

        self::assertTrue($this->query->hintForPuzzle(PuzzleFixture::PUZZLE_6000, true)?->isEmpty(), 'stale mark');
        self::assertNull($this->query->hintForPuzzle(PuzzleFixture::PUZZLE_UNAPPROVED, true));
    }

    /**
     * @param list<PuzzleNeedingImprovement> $rows
     * @return list<string>
     */
    private function ids(array $rows): array
    {
        return array_map(static fn (PuzzleNeedingImprovement $row): string => $row->puzzleId, $rows);
    }

    /**
     * @param list<PuzzleNeedingImprovement> $rows
     * @return array<string, PuzzleNeedingImprovement>
     */
    private function byId(array $rows): array
    {
        $byId = [];
        foreach ($rows as $row) {
            $byId[$row->puzzleId] = $row;
        }

        return $byId;
    }

    /**
     * @param list<BrandNeedingImprovement> $brands
     * @return array<string, int>
     */
    private function brandCounts(array $brands): array
    {
        $counts = [];
        foreach ($brands as $brand) {
            $counts[$brand->manufacturerId] = $brand->puzzlesCount;
        }

        return $counts;
    }

    /**
     * @param list<string> $ids
     * @return list<string>
     */
    private function sorted(array $ids): array
    {
        sort($ids);

        return $ids;
    }
}
