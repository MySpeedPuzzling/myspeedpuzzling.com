<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services\Insights\Model;

use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Services\Insights\Model\PortfolioScore;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\MspRatingFormula;

/**
 * The O(1) mean after one change equals the mean of the rebuilt portfolio, below, at and above the 100-entry cap,
 * for a replaced counted entry, a replaced uncounted one and a new one.
 */
final class PortfolioScoreTest extends TestCase
{
    public function testOneChangeEqualsARebuild(): void
    {
        mt_srand(4242);

        foreach ([0, 1, 5, 99, 100, 101, 150] as $size) {
            $points = [];

            for ($i = 0; $i < $size; $i++) {
                // Coarse values, so ties at the cut happen
                $points['p' . $i] = mt_rand(0, 40) / 40;
            }

            $score = new PortfolioScore($points);
            self::assertEqualsWithDelta(self::rebuiltMean($points), $score->mean(), 1e-12, "size {$size}");

            $changes = [['new', 0.99], ['new', 0.0], ['new', 0.5]];

            for ($i = 0; $i < min($size, 30); $i++) {
                $changes[] = ['p' . mt_rand(0, $size - 1), mt_rand(0, 40) / 40];
            }

            foreach ($changes as [$puzzleId, $newPoints]) {
                $changed = $points;
                $changed[$puzzleId] = $newPoints;

                self::assertEqualsWithDelta(self::rebuiltMean($changed), $score->meanWith($puzzleId, $newPoints), 1e-12, "size {$size}, {$puzzleId} → {$newPoints}");
                self::assertEqualsWithDelta(self::rebuiltMean($changed), $score->with([$puzzleId => $newPoints])->mean(), 1e-12);
            }
        }
    }

    public function testRatingIsTheFormula(): void
    {
        $firstAttempts = new PortfolioScore(['a' => 0.9, 'b' => 0.7]);
        $bestTimes = new PortfolioScore(['a' => 0.95, 'b' => 0.8, 'c' => 0.6]);

        self::assertEqualsWithDelta(
            MspRatingFormula::rating($firstAttempts->topPoints(), $bestTimes->topPoints()),
            PortfolioScore::rating($firstAttempts->mean(), $bestTimes->mean(), true),
            1e-15,
        );
        self::assertSame(0.0, PortfolioScore::rating(0.0, 0.8, false), 'No first attempts, no rating');
    }

    /**
     * @param array<string, float> $points
     */
    private static function rebuiltMean(array $points): float
    {
        $values = array_values($points);
        rsort($values);
        $top = array_slice($values, 0, MspRatingFormula::CAP);

        return $top === [] ? 0.0 : array_sum($top) / count($top);
    }
}
