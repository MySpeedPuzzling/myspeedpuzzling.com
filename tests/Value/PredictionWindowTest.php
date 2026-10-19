<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Value;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Value\PredictionWindow;

/**
 * The one rounding rule of shown prediction windows (docs/features/insights/README.md §3 "Prediction windows"):
 * nearest step, ties inward, at least one step, never a low end of 0.
 */
final class PredictionWindowTest extends TestCase
{
    /**
     * @return iterable<string, array{int, int, null|int, int, int, int}>
     */
    public static function windows(): iterable
    {
        // raw low, raw high, centre => shown low, shown high, step
        yield '1-minute grid, nearest' => [3440, 3820, 3600, 3420, 3840, 60];
        yield 'both ends halfway go inward' => [3390, 3810, 3600, 3420, 3780, 60];
        yield 'rounds to nothing: the step holding the midpoint' => [3700, 3730, 3715, 3660, 3720, 60];
        yield 'midpoint on a step: the step starting there' => [3590, 3610, 3600, 3600, 3660, 60];
        yield 'rounds to nothing: the step holding the prediction, not the midpoint' => [176, 184, 179, 170, 180, 10];
        yield 'rounds to nothing, prediction off-centre: its own step' => [3700, 3730, 3725, 3720, 3780, 60];
        yield 'rounds to nothing, prediction outside the raw window counts as its nearer end' => [3700, 3730, 3500, 3660, 3720, 60];
        yield 'ties collapsing a one-step window' => [3630, 3690, 3660, 3660, 3720, 60];
        yield '5-minute grid under an hour' => [2640, 4080, 3300, 2700, 4200, 300];
        yield '5-minute grid crossing the hour' => [3000, 4320, 3500, 3000, 4200, 300];
        yield '10-minute grid from an hour on' => [6420, 8160, 7200, 6600, 8400, 600];
        yield '10-minute grid, ties inward' => [6900, 8100, 7200, 7200, 7800, 600];
        yield 'width just under 20 min stays on the minute grid' => [3000, 4199, 3600, 3000, 4200, 60];
        yield 'tiny puzzle: 10-second grid' => [64, 116, 90, 60, 120, 10];
        yield 'seconds under a minute' => [32, 58, 45, 30, 60, 10];
        yield 'the low end is never 0 (minute grid)' => [20, 400, 200, 60, 420, 60];
        yield 'an empty window is still one step, not at 0' => [0, 0, 0, 10, 20, 10];
        yield 'swapped ends are read as a window' => [3820, 3440, 3600, 3420, 3840, 60];
        yield 'no centre: the midpoint decides the grid' => [6420, 8160, null, 6600, 8400, 600];
    }

    #[DataProvider('windows')]
    public function testRounding(int $rawLow, int $rawHigh, null|int $centre, int $low, int $high, int $step): void
    {
        $window = PredictionWindow::rounded($rawLow, $rawHigh, $centre);

        self::assertSame([$low, $high, $step], [$window->lowSeconds, $window->highSeconds, $window->stepSeconds]);
    }

    public function testStepBoundaries(): void
    {
        self::assertSame(10, PredictionWindow::stepFor(5000, 179));
        self::assertSame(60, PredictionWindow::stepFor(1199, 180));
        self::assertSame(60, PredictionWindow::stepFor(1199, 7200));
        self::assertSame(300, PredictionWindow::stepFor(1200, 3599));
        self::assertSame(600, PredictionWindow::stepFor(1200, 3600));
    }

    /**
     * Never outward: whenever the raw window is at least one step wide, each shown end lies within half a step of
     * its raw end - never floored/ceiled to the outer step - and the window is at least one step wide.
     */
    public function testNeverMovesAnEndMoreThanHalfAStepOutward(): void
    {
        // The minute / 5 / 10-minute grids
        $this->sweep(1800, 9000, 37, 3000, 53);
        // The 10-second grid: predictions under 3 min
        $this->sweep(1, 400, 7, 300, 11);
    }

    /**
     * Every window of the range, with the prediction at its low end, a quarter in, the middle, three quarters in, its
     * high end and outside it on both sides: at least one step wide, on the grid, and - whenever the raw window is at
     * least one step wide and its low end is not lifted off 0 - no end more than half a step outward. (The step a
     * window that rounds to nothing falls back to is pinned by the cases of windows().)
     */
    private function sweep(int $fromLow, int $toLow, int $lowStep, int $maxWidth, int $widthStep): void
    {
        for ($low = $fromLow; $low <= $toLow; $low += $lowStep) {
            for ($width = 0; $width <= $maxWidth; $width += $widthStep) {
                $high = $low + $width;

                foreach ([$low, $low + intdiv($width, 4), $low + intdiv($width, 2), $high - intdiv($width, 4), $high, max(0, $low - 40), $high + 40] as $centre) {
                    $window = PredictionWindow::rounded($low, $high, $centre);
                    $step = $window->stepSeconds;
                    $context = sprintf('%d-%d (centre %d) → %d-%d', $low, $high, $centre, $window->lowSeconds, $window->highSeconds);

                    self::assertGreaterThanOrEqual($step, $window->highSeconds - $window->lowSeconds, $context);
                    self::assertGreaterThanOrEqual($step, $window->lowSeconds, $context);
                    self::assertSame(0, $window->lowSeconds % $step, $context);
                    self::assertSame(0, $window->highSeconds % $step, $context);

                    if ($width >= $step && $low >= $step) {
                        self::assertGreaterThanOrEqual($low - $step / 2, $window->lowSeconds, $context);
                        self::assertLessThanOrEqual($high + $step / 2, $window->highSeconds, $context);
                    }
                }
            }
        }
    }

    public function testContainsAndMarginAreMeasuredAgainstTheShownEnds(): void
    {
        // Shown 57-64 min
        $window = PredictionWindow::rounded(3440, 3820, 3600);

        self::assertTrue($window->contains(3420));
        self::assertTrue($window->contains(3840));
        self::assertTrue($window->contains(3830), 'past the raw high end, inside the shown one');
        self::assertFalse($window->contains(3419));
        self::assertFalse($window->contains(3841));

        self::assertSame(0, $window->marginOf(3600));
        self::assertSame(-180, $window->marginOf(3240));
        self::assertSame(300, $window->marginOf(4140));
    }
}
