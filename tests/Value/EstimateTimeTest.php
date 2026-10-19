<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Value;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Value\EstimateTime;
use SpeedPuzzling\Web\Value\PredictionWindow;

/**
 * The time of a personal estimate as shown (docs/features/insights/estimates.md): 5 minutes under an hour, 10 from an
 * hour on, the minute under 10 minutes, never 0 - and never outside the window shown next to it.
 */
final class EstimateTimeTest extends TestCase
{
    /**
     * @return iterable<string, array{int, int}>
     */
    public static function times(): iterable
    {
        yield '58:40 on the 5-minute step' => [3520, 3600];
        yield '42:20 on the 5-minute step' => [2540, 2400];
        yield '3:04:00 on the 10-minute step' => [11040, 10800];
        yield '3:06:00 on the 10-minute step' => [11160, 11400];
        yield '1:00:00 exactly' => [3600, 3600];
        yield '7:20 on the minute' => [440, 420];
        yield '9:40 on the minute' => [580, 600];
        yield 'never 0' => [25, 60];
    }

    #[DataProvider('times')]
    public function testRoundsToWhatPeopleCanUse(int $seconds, int $shown): void
    {
        self::assertSame($shown, EstimateTime::rounded($seconds));
    }

    public function testNeverLeavesTheShownWindow(): void
    {
        // 2:04:00 in a narrow window on the minute grid (2:01-2:06): the 10-minute step would say 2 h, outside it
        $window = PredictionWindow::rounded(7260, 7560, 7440);
        self::assertSame(60, $window->stepSeconds);
        self::assertSame(7440, EstimateTime::rounded(7440, $window));

        // A wide window keeps the 10-minute step
        $wide = PredictionWindow::rounded(9600, 12600, 11160);
        self::assertSame(11400, EstimateTime::rounded(11160, $wide));
    }

    public function testEveryRoundedTimeLiesInsideItsWindow(): void
    {
        for ($centre = 120; $centre <= 6 * 3600; $centre += 37) {
            foreach ([0.04, 0.08, 0.15, 0.3] as $halfWidth) {
                $window = PredictionWindow::rounded((int) round($centre * (1 - $halfWidth)), (int) round($centre * (1 + $halfWidth)), $centre);
                $shown = EstimateTime::rounded($centre, $window);

                self::assertTrue($window->contains($shown), sprintf('%d s ± %.2f → %d s outside %d-%d', $centre, $halfWidth, $shown, $window->lowSeconds, $window->highSeconds));
            }
        }
    }
}
