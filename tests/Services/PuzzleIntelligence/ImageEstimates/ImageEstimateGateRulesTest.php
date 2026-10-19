<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services\PuzzleIntelligence\ImageEstimates;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\ImageEstimates\ImageEstimateGate;

/**
 * The gate's thresholds on one group's numbers (ImageEstimateGate::failures()) - every rule fails on its own, a
 * missing number fails.
 */
final class ImageEstimateGateRulesTest extends TestCase
{
    public function testAGroupMeetingEveryGatePasses(): void
    {
        self::assertSame([], ImageEstimateGate::failures(self::passing()));
        // Exactly on the edges
        self::assertSame([], ImageEstimateGate::failures([
            'measuredPuzzles' => 50,
            'spearman' => 0.6,
            'tierWithinOne' => 0.8,
            'tierExact' => 0.56,
            'alwaysAverage' => 0.40,
            'window50Hit' => 0.47,
            'window80Hit' => 0.83,
        ]));
    }

    /**
     * @param array<string, mixed> $change
     */
    #[DataProvider('failingGroups')]
    public function testEveryGateFailsOnItsOwn(array $change, string $failure): void
    {
        $failures = ImageEstimateGate::failures([...self::passing(), ...$change]);

        self::assertCount(1, $failures);
        self::assertStringContainsString($failure, $failures[0]);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function failingGroups(): iterable
    {
        yield 'too few measured puzzles' => [['measuredPuzzles' => 49], 'measured puzzles'];
        yield 'rank correlation' => [['spearman' => 0.59], 'Spearman'];
        yield 'no rank correlation' => [['spearman' => null], 'Spearman'];
        yield 'within one tier' => [['tierWithinOne' => 0.79], 'tier within one'];
        yield 'exact tier not clearly above 46 %' => [['tierExact' => 0.55], 'exact tier'];
        yield 'exact tier not clearly above always-Average of the same puzzles' => [['alwaysAverage' => 0.55, 'tierExact' => 0.64], 'exact tier'];
        yield '50 % window too often hit' => [['window50Hit' => 0.531], 'window50Hit'];
        yield '80 % window too rarely hit' => [['window80Hit' => 0.769], 'window80Hit'];
        yield 'no window numbers' => [['window80Hit' => null], 'window80Hit'];
    }

    public function testAnEmptyGroupFailsEverything(): void
    {
        self::assertCount(6, ImageEstimateGate::failures([]));
    }

    /**
     * @return array<string, int|float>
     */
    private static function passing(): array
    {
        return [
            'measuredPuzzles' => 120,
            'spearman' => 0.72,
            'tierWithinOne' => 0.9,
            'tierExact' => 0.61,
            'alwaysAverage' => 0.46,
            'window50Hit' => 0.51,
            'window80Hit' => 0.79,
        ];
    }
}
