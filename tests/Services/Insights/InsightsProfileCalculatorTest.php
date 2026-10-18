<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services\Insights;

use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Services\Insights\InsightsProfileCalculator;
use SpeedPuzzling\Web\Value\InsightsProfileAxis;

final class InsightsProfileCalculatorTest extends TestCase
{
    private const int NOW = 1_800_000_000;

    /**
     * A puzzler at their usual 1.0 a year ago and 0.8 now (20 % faster), steady, gaining 25 % on second attempts
     * (the community gains 10 %), on Hard puzzles exactly at their usual, with too few event results and no big puzzles.
     */
    public function testEveryAxisOfAKnownHistory(): void
    {
        $results = [];

        // A year ago: 4 first tries at the puzzle's median
        foreach ([370, 360, 350, 340] as $daysAgo) {
            $results[] = self::solve("old-{$daysAgo}", 1000, $daysAgo, true, 1);
        }

        // The last 50 days: 10 first tries 20 % under the median; the 5 newest are Hard, 4 newest at events
        foreach ([50, 45, 40, 35, 30, 25, 20, 15, 10, 5] as $daysAgo) {
            $results[] = self::solve("new-{$daysAgo}", 800, $daysAgo, true, 1, tier: $daysAgo <= 25 ? 5 : 3, event: $daysAgo <= 20);
        }

        // Second attempts of three of them, 25 % faster than the first try
        foreach ([50 => 4, 40 => 3, 30 => 2] as $puzzleDaysAgo => $daysAgo) {
            $results[] = self::solve("new-{$puzzleDaysAgo}", 600, $daysAgo, false, 2);
        }

        usort($results, static fn (array $a, array $b): int => $a['at'] <=> $b['at']);

        $scores = (new InsightsProfileCalculator())->scores(
            $results,
            self::NOW,
            static fn (int $pieces, int $attempt): float => $attempt === 1 ? 1.0 : 0.9,
        );

        // 3 × 0.6, 10 × 0.8, 3 × 1.0 (the result 370 days ago is older than a year)
        self::assertEqualsWithDelta(0.8, $scores[InsightsProfileAxis::Speed->value]['score'], 1e-9);
        self::assertSame(16, $scores[InsightsProfileAxis::Speed->value]['sample_size']);

        // Form 0.8 now (12 results in the last 45 days) against 1.0 a year ago (4 results)
        self::assertEqualsWithDelta(0.2, $scores[InsightsProfileAxis::Improvement->value]['score'], 1e-9);
        self::assertSame(16, $scores[InsightsProfileAxis::Improvement->value]['sample_size']);

        // Residuals 13 × 1.0 and 3 × 0.6 / 0.8 / 0.9: the quartiles are both 1.0
        self::assertEqualsWithDelta(1.0, $scores[InsightsProfileAxis::Consistency->value]['score'], 1e-9);

        self::assertEqualsWithDelta(0.25, $scores[InsightsProfileAxis::Learning->value]['score'], 1e-9);
        self::assertSame(3, $scores[InsightsProfileAxis::Learning->value]['sample_size']);

        self::assertEqualsWithDelta(1.0, $scores[InsightsProfileAxis::HardPuzzles->value]['score'], 1e-9);
        self::assertSame(5, $scores[InsightsProfileAxis::HardPuzzles->value]['sample_size']);

        self::assertSame(['score' => null, 'sample_size' => 0], $scores[InsightsProfileAxis::BigPuzzles->value]);
        self::assertSame(['score' => null, 'sample_size' => 4], $scores[InsightsProfileAxis::Events->value]);
    }

    public function testTooFewResultsGiveNoScores(): void
    {
        $results = [
            self::solve('a', 900, 10, true, 1),
            self::solve('b', 900, 5, true, 1),
        ];

        $scores = (new InsightsProfileCalculator())->scores($results, self::NOW, static fn (int $pieces, int $attempt): float => 1.0);

        foreach (InsightsProfileAxis::cases() as $axis) {
            self::assertNull($scores[$axis->value]['score'], $axis->value);
        }

        self::assertSame(2, $scores[InsightsProfileAxis::Speed->value]['sample_size']);
    }

    public function testBigPuzzlesAndEventsAgainstYourUsual(): void
    {
        $results = [];

        // Usual: 1.0; big puzzles 10 % slower than that, event results 5 % faster
        for ($day = 100; $day >= 1; $day--) {
            $big = $day % 10 === 0;
            $event = $day % 10 === 5;
            $seconds = $big ? 1100 : ($event ? 950 : 1000);
            $results[] = self::solve("p{$day}", $seconds, $day, !$big && !$event, 1, pieces: $big ? 1000 : 500, event: $event);
        }

        $scores = (new InsightsProfileCalculator())->scores($results, self::NOW, static fn (int $pieces, int $attempt): float => 1.0);

        self::assertEqualsWithDelta(1.1, $scores[InsightsProfileAxis::BigPuzzles->value]['score'], 1e-9);
        self::assertSame(10, $scores[InsightsProfileAxis::BigPuzzles->value]['sample_size']);
        self::assertEqualsWithDelta(0.95, $scores[InsightsProfileAxis::Events->value]['score'], 1e-9);
    }

    /**
     * @return array{puzzle_id: string, seconds: int, at: int, first_attempt: bool, attempt: int, pieces: int, median: int, tier: null|int, event: bool}
     */
    private static function solve(string $puzzle, int $seconds, int $daysAgo, bool $firstAttempt, int $attempt, null|int $tier = 3, bool $event = false, int $pieces = 500): array
    {
        return [
            'puzzle_id' => $puzzle,
            'seconds' => $seconds,
            'at' => self::NOW - $daysAgo * InsightsProfileCalculator::DAY,
            'first_attempt' => $firstAttempt,
            'attempt' => $attempt,
            'pieces' => $pieces,
            'median' => 1000,
            'tier' => $tier,
            'event' => $event,
        ];
    }
}
