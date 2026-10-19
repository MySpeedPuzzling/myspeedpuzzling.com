<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services\Insights;

use DateTimeZone;
use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Services\Insights\HabitsBuilder;

final class HabitsBuilderTest extends TestCase
{
    public function testWeekdaysBreaksAndTimesOfDay(): void
    {
        // 2026-10-15 is a Thursday: 0, 7, 14 … days ago are Thursdays
        $results = [];

        for ($week = 0; $week < 6; $week++) {
            $results[] = InsightsTestResults::result(['days_ago' => 7 * $week]);
        }

        $results[] = InsightsTestResults::result(['days_ago' => 2]); // Tuesday

        $zone = new DateTimeZone('Europe/London');
        $habits = (new HabitsBuilder())->build(InsightsTestResults::rows($results, $zone), true);

        self::assertSame(4, $habits->busiestWeekday);
        self::assertSame('1', $habits->weekdays[0]->key);
        self::assertSame(6, $habits->weekdays[3]->count);
        self::assertSame(1, $habits->weekdays[1]->count);
        self::assertSame(7, $habits->sampleSize);

        $breaks = [];

        foreach ($habits->breaks as $bucket) {
            $breaks[$bucket->key] = $bucket->count;
        }

        // 35 → 28 → 21 → 14 → 7 (Tue +5) → 0 (+2): weekly gaps of 7, then 5 and 2
        self::assertSame(['0' => 0, '1' => 0, '2-7' => 6, '8-14' => 0, '15+' => 0], $breaks);

        self::assertNotNull($habits->timesOfDay);
        $evening = array_values(array_filter($habits->timesOfDay, static fn ($bucket) => $bucket->key === 'evening'));
        self::assertSame(7, $evening[0]->count, '18:30 UTC is evening in London');

        self::assertNull((new HabitsBuilder())->build(InsightsTestResults::rows($results), false)->timesOfDay);
    }

    public function testBreakBuckets(): void
    {
        self::assertSame('0', HabitsBuilder::breakBucket(0));
        self::assertSame('1', HabitsBuilder::breakBucket(1));
        self::assertSame('2-7', HabitsBuilder::breakBucket(7));
        self::assertSame('8-14', HabitsBuilder::breakBucket(14));
        self::assertSame('15+', HabitsBuilder::breakBucket(15));
    }
}
