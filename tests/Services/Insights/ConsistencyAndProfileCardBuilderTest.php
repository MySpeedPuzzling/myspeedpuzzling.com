<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services\Insights;

use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Results\InsightsContext;
use SpeedPuzzling\Web\Results\InsightsProfileAxisScore;
use SpeedPuzzling\Web\Services\Insights\ConsistencyBuilder;
use SpeedPuzzling\Web\Services\Insights\ProfileCardBuilder;
use SpeedPuzzling\Web\Value\InsightsProfileAxis;

final class ConsistencyAndProfileCardBuilderTest extends TestCase
{
    public function testConsistencyIsTheMiddleHalfAroundYourUsual(): void
    {
        // First tries at exactly the median keep your usual at 1.0; repeats alternate 0.9 / 1.1 around it
        $results = [];

        for ($i = 0; $i < 40; $i++) {
            $results[] = InsightsTestResults::result(['days_ago' => 300 - $i * 7]);
            $results[] = InsightsTestResults::result(['seconds' => $i % 2 === 0 ? 2700 : 3300, 'days_ago' => 300 - $i * 7, 'firstAttempt' => false, 'attempt' => 2]);
        }

        $axes = [new InsightsProfileAxisScore(InsightsProfileAxis::Consistency, 1.2, 40, 63.5)];
        $section = (new ConsistencyBuilder())->build(InsightsTestResults::rows($results), $axes, InsightsTestResults::now());

        self::assertNotNull($section->spread);
        // 80 residuals: 20 × 0.9, 40 × 1.0, 20 × 1.1 → q25 = 0.975, q75 = 1.025
        self::assertEqualsWithDelta(1.025 / 0.975, $section->spread, 1e-9);
        self::assertEqualsWithDelta((1.025 / 0.975 - 1) / 2, $section->plusMinus, 1e-9);
        self::assertSame(80, $section->sampleSize);
        self::assertSame(63.5, $section->percentile);
        self::assertNotNull($section->recentSpread);
        self::assertFalse($section->notSureYet);

        self::assertTrue((new ConsistencyBuilder())->build([], [], InsightsTestResults::now())->notSureYet);
    }

    public function testTheProfileCardsStrongestAxisAndRoomToGrow(): void
    {
        $context = new InsightsContext(3, [
            new InsightsProfileAxisScore(InsightsProfileAxis::Speed, 0.9, 50, 71.0),
            new InsightsProfileAxisScore(InsightsProfileAxis::Improvement, null, 2, null),
            new InsightsProfileAxisScore(InsightsProfileAxis::Learning, 0.2, 9, 88.5),
            new InsightsProfileAxisScore(InsightsProfileAxis::Events, 1.1, 6, 12.0),
        ], null, [], [], null);

        $card = (new ProfileCardBuilder())->build($context);

        self::assertSame(InsightsProfileAxis::Learning, $card->strongest);
        self::assertSame(InsightsProfileAxis::Events, $card->roomToGrow);
        self::assertFalse($card->notSureYet);

        $empty = (new ProfileCardBuilder())->build(InsightsContext::empty());
        self::assertTrue($empty->notSureYet);
        self::assertNull($empty->strongest);
    }
}
