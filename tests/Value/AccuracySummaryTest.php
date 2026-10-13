<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Value;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Value\AccuracySummary;

/**
 * The accuracy report's distribution (AccuracySummary): count, median, quartiles and IQR by linear interpolation
 * between the closest ranks - the same cases as tests/online-review-harness.mjs ("statistics"), so the review page's
 * numbers (review_logic.js summarize()) and the report's agree.
 */
final class AccuracySummaryTest extends TestCase
{
    /**
     * @return iterable<string, array{list<int|float>, int, null|float, null|float, null|float, null|float}>
     */
    public static function distributions(): iterable
    {
        yield 'none' => [[], 0, null, null, null, null];
        yield 'one' => [[400], 1, 400.0, 400.0, 400.0, 0.0];
        yield 'four, unsorted' => [[300, -100, 500, 100], 4, 200.0, 50.0, 350.0, 300.0];
        yield 'five' => [[1, 2, 3, 4, 5], 5, 3.0, 2.0, 4.0, 2.0];
        yield 'seven with an outlier' => [[-1200, 0, 0, 100, 250, 400, 3000], 7, 100.0, 0.0, 325.0, 325.0];
        yield 'two' => [[5, 7], 2, 6.0, 5.5, 6.5, 1.0];
    }

    /**
     * @param list<int|float> $values
     */
    #[DataProvider('distributions')]
    public function testTheNumbersOfADistribution(array $values, int $count, null|float $median, null|float $q1, null|float $q3, null|float $iqr): void
    {
        $summary = AccuracySummary::of($values);

        self::assertSame($count, $summary->count);
        self::assertSame($median, $summary->median);
        self::assertSame($q1, $summary->q1);
        self::assertSame($q3, $summary->q3);
        self::assertSame($iqr, $summary->iqr);
    }
}
