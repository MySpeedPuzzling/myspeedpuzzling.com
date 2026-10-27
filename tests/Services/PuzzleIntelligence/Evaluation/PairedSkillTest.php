<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services\PuzzleIntelligence\Evaluation;

use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\Evaluation\PairedSkill;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\Evaluation\PredictionGroupAccumulator;
use SpeedPuzzling\Web\Value\StoredPredictionWindows;

/**
 * Skill against a reference on the same solves (PairedSkill): the errors, their paired difference with its standard
 * error, and the 80 % window width at equal coverage - pure.
 */
final class PairedSkillTest extends TestCase
{
    public function testErrorsAndTheirPairedDifference(): void
    {
        $skill = new PairedSkill();
        // Candidate right on (error 0, then |ln 1.1|), reference 10 % / 20 % off
        $skill->add(1000, self::around(1000, 0.1, 0.2), self::around(1100, 0.1, 0.2));
        $skill->add(1000, self::around(1100, 0.1, 0.2), self::around(1200, 0.1, 0.2));

        $metrics = $skill->metrics();
        $first = -log(1.1);
        $second = log(1.1) - log(1.2);

        self::assertSame(2, $metrics['rows']);
        self::assertEqualsWithDelta(round(log(1.1) / 2, 4), $metrics['maeCandidate'], 1e-9);
        self::assertEqualsWithDelta(round((log(1.1) + log(1.2)) / 2, 4), $metrics['maeReference'], 1e-9);
        self::assertEqualsWithDelta(round(log(1.1) / (log(1.1) + log(1.2)), 4), $metrics['errorRatio'], 1e-9);
        self::assertEqualsWithDelta(round(($first + $second) / 2, 5), $metrics['meanDifference'], 1e-9);
        // Sample SD of the two differences ÷ √2
        $mean = ($first + $second) / 2;
        $sd = sqrt((($first - $mean) ** 2 + ($second - $mean) ** 2) / 1);
        self::assertEqualsWithDelta(round($sd / sqrt(2), 5), $metrics['differenceSe'], 1e-9);
        self::assertArrayNotHasKey('width80Candidate', $metrics, 'Widths only when asked for');
    }

    /**
     * A narrow window holding fewer solves is not sharper: both are scaled to hold exactly 80 % before their widths
     * are compared.
     */
    public function testTheWidthAtEqualCoverage(): void
    {
        $skill = new PairedSkill(withWidths: true);

        // Ten solves at log offsets 0.02 … 0.2 from the centre: 80 % need a half-width of 0.16
        foreach (range(1, 10) as $i) {
            $actual = (int) round(1000 * exp(0.02 * $i));
            $skill->add($actual, self::around(1000, 0.05, 0.1), self::around(1000, 0.15, 0.3));
        }

        $metrics = $skill->metrics();

        // Candidate stored 0.2 wide, scaled by 1.6; reference stored 0.6 wide, scaled by 0.533: both 0.32
        self::assertNotNull($metrics['width80Candidate'] ?? null);
        self::assertEqualsWithDelta(0.32, $metrics['width80Candidate'], 0.002);
        self::assertEqualsWithDelta($metrics['width80Candidate'], $metrics['width80Reference'] ?? null, 0.002);
    }

    public function testTheKBuckets(): void
    {
        self::assertSame('repeat', PredictionGroupAccumulator::kBucket(null));
        self::assertSame(['0', '1-2', '1-2', '3-4', '3-4', '5-9', '5-9', '10-19', '20+'], array_map(
            PredictionGroupAccumulator::kBucket(...),
            [0, 1, 2, 3, 4, 5, 9, 10, 20],
        ));
    }

    private static function around(int $centre, float $half50, float $half80): StoredPredictionWindows
    {
        return new StoredPredictionWindows(
            $centre,
            (int) round($centre * exp(-$half80)),
            (int) round($centre * exp(-$half50)),
            (int) round($centre * exp($half50)),
            (int) round($centre * exp($half80)),
        );
    }
}
