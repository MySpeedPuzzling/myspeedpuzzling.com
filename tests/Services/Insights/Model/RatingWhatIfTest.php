<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services\Insights\Model;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Services\Insights\Model\RatingWhatIf;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\MspRatingFormula;

/**
 * The what-if percentiles are exactly what MspRatingFormula::percentileInSortedTimes() gives on the changed list.
 */
final class RatingWhatIfTest extends TestCase
{
    /**
     * @return iterable<string, array{list<int>, int, int}>
     */
    public static function replacements(): iterable
    {
        $times = [1200, 1500, 1500, 1800, 2000, 2000, 2000, 2400, 3000, 3600];

        yield 'same time' => [$times, 2000, 2000];
        yield 'better, past others' => [$times, 2400, 1400];
        yield 'better, onto a tie' => [$times, 3000, 1500];
        yield 'new fastest' => [$times, 1800, 900];
        yield 'own tied, now alone' => [$times, 2000, 1900];
        yield 'own slowest to fastest' => [$times, 3600, 1000];
        yield 'two solvers' => [[1000, 2000], 2000, 1500];
    }

    /**
     * @param list<int> $times
     */
    #[DataProvider('replacements')]
    public function testReplacingIsTheFormulaOnTheChangedList(array $times, int $own, int $new): void
    {
        $changed = $times;
        array_splice($changed, MspRatingFormula::lowerBound($changed, $own), 1);
        $changed[] = $new;
        sort($changed);

        self::assertSame(MspRatingFormula::percentileInSortedTimes($new, $changed), RatingWhatIf::percentileReplacing($times, $own, $new));
    }

    public function testReplacingTheOwnTimeByItselfGivesTheStoredPercentile(): void
    {
        $times = [1200, 1500, 1500, 1800, 2000, 2000, 2400];

        foreach ($times as $time) {
            self::assertSame(MspRatingFormula::percentileInSortedTimes($time, $times), RatingWhatIf::percentileReplacing($times, $time, $time));
        }
    }

    public function testInsertingIsTheFormulaWithOneSolverMore(): void
    {
        $times = [1200, 1500, 1500, 1800, 2000, 2000, 2400];

        foreach ([900, 1500, 1700, 2000, 5000] as $new) {
            $changed = [...$times, $new];
            sort($changed);

            self::assertSame(MspRatingFormula::percentileInSortedTimes($new, $changed), RatingWhatIf::percentileInserting($times, $new), (string) $new);
        }

        self::assertSame(0.5, RatingWhatIf::percentileInserting([], 1000));
    }

    public function testAnOwnTimeMissingFromTheListIsInserted(): void
    {
        $times = [1200, 1500, 1800];

        // 1600 is nobody's time: the player's result came after the batch run
        self::assertSame(RatingWhatIf::percentileInserting($times, 1400), RatingWhatIf::percentileReplacing($times, 1600, 1400));
    }
}
