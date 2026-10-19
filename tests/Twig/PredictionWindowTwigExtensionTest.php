<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Twig;

use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Results\TimePredictionResult;
use SpeedPuzzling\Web\Twig\PredictionWindowTwigExtension;

final class PredictionWindowTwigExtensionTest extends TestCase
{
    public function testTheLikelyWindowIsRoundedAroundThePrediction(): void
    {
        $window = PredictionWindowTwigExtension::windowOf(new TimePredictionResult(3600, 3440, 3820, 1.0));

        self::assertSame([3420, 3840], [$window->lowSeconds, $window->highSeconds]);
    }

    public function testThe80PercentRangeIsRoundedLikeTheWindow(): void
    {
        $range = PredictionWindowTwigExtension::range80Of(new TimePredictionResult(3600, 3440, 3820, 1.0, range80LowSeconds: 3150, range80HighSeconds: 4180));

        self::assertNotNull($range);
        // 17:10 wide → the minute grid
        self::assertSame([3180, 4200], [$range->lowSeconds, $range->highSeconds]);
    }

    public function testAPredictionStoredBeforeModelV3HasNo80PercentRange(): void
    {
        self::assertNull(PredictionWindowTwigExtension::range80Of(new TimePredictionResult(3600, 3440, 3820, 1.0)));
        self::assertNull(PredictionWindowTwigExtension::range80Of(new TimePredictionResult(3600, 3440, 3820, 1.0, range80LowSeconds: 3150)));
    }
}
