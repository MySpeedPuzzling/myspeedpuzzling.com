<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services\Insights;

use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Services\Insights\InsightsCriteria;
use SpeedPuzzling\Web\Value\InsightsImageTrait;
use SpeedPuzzling\Web\Value\InsightsInclusionFilter;
use SpeedPuzzling\Web\Value\InsightsPeriod;
use SpeedPuzzling\Web\Value\InsightsPuzzlePersonality;
use SpeedPuzzling\Web\Value\InsightsResultsFilter;
use SpeedPuzzling\Web\Value\InsightsTimeOfDay;

final class InsightsCriteriaTest extends TestCase
{
    private const string BRAND = '0192f2b4-7a2e-7d4e-9a1b-1234567890ab';

    public function testFreePlayersGetThreeOrSixMonthsAndThePieceCountOnly(): void
    {
        $criteria = InsightsCriteria::fromUserInput([
            'period' => '24m',
            'pieces' => '500',
            'results' => 'first',
            'brands' => [self::BRAND],
            'difficulty' => ['3'],
            'events' => 'only',
            'weekdays' => ['6', '7'],
            'personality' => ['memorable'],
            'traits' => ['dark'],
            'timeOfDay' => ['evening'],
        ], isMember: false, hasTimezone: true);

        self::assertSame(InsightsPeriod::Last6Months, $criteria->period);
        self::assertSame('500', $criteria->pieces->toParam());
        self::assertSame(InsightsResultsFilter::All, $criteria->results);
        self::assertSame([], $criteria->brandIds);
        self::assertSame([], $criteria->difficultyTiers);
        self::assertSame(InsightsInclusionFilter::Any, $criteria->events);
        self::assertSame([], $criteria->weekdays);
        self::assertSame([], $criteria->personalities);
        self::assertSame([], $criteria->imageTraits);
        self::assertSame([], $criteria->timesOfDay);
        self::assertSame(['period', 'results', 'brands', 'difficulty', 'events', 'weekdays', 'personality', 'traits', 'timeOfDay'], $criteria->strippedForMembers);
        self::assertSame(['pieces' => '500'], $criteria->toQueryParameters());

        $threeMonths = InsightsCriteria::fromUserInput(['period' => '3m'], isMember: false);
        self::assertSame(InsightsPeriod::Last3Months, $threeMonths->period);
        self::assertSame([], $threeMonths->strippedForMembers);
    }

    public function testMembersGetEveryFilter(): void
    {
        $criteria = InsightsCriteria::fromUserInput([
            'period' => 'custom',
            'from' => '2026-05-31',
            'to' => '2026-01-01',
            'results' => 'repeat',
            'brands' => [strtoupper(self::BRAND), 'not-a-uuid', self::BRAND],
            'difficulty' => ['0', '6', '9', 'x'],
            'unboxed' => 'exclude',
            'shelf' => 'only',
            'weekdays' => ['7', '1', '8'],
            'personality' => ['memorable', 'rewards_skill', 'nope'],
            'traits' => ['photo', 'dark'],
            'timeOfDay' => ['night', 'morning'],
        ], isMember: true, hasTimezone: true);

        self::assertSame(InsightsPeriod::Custom, $criteria->period);
        self::assertSame('2026-01-01', $criteria->customFrom?->format('Y-m-d'));
        self::assertSame('2026-05-31', $criteria->customTo?->format('Y-m-d'));
        self::assertSame([self::BRAND], $criteria->brandIds);
        self::assertSame([0, 6], $criteria->difficultyTiers);
        self::assertSame(InsightsInclusionFilter::Exclude, $criteria->unboxed);
        self::assertSame(InsightsInclusionFilter::Only, $criteria->shelf);
        self::assertSame([1, 7], $criteria->weekdays);
        self::assertSame([InsightsPuzzlePersonality::RewardsSkill, InsightsPuzzlePersonality::Memorable], $criteria->personalities);
        self::assertSame([InsightsImageTrait::Dark, InsightsImageTrait::Photo], $criteria->imageTraits);
        self::assertSame([InsightsTimeOfDay::Morning, InsightsTimeOfDay::Night], $criteria->timesOfDay);
        self::assertSame([], $criteria->strippedForMembers);

        // The URL is the state: the applied values read back give the same criteria
        $again = InsightsCriteria::fromUserInput($criteria->toQueryParameters(), isMember: true, hasTimezone: true);
        self::assertEquals($criteria, $again);
        self::assertSame($criteria->cacheKey('Europe/Prague'), $again->cacheKey('Europe/Prague'));
        // Solve days and the time of day depend on the zone: another zone is another page
        self::assertNotSame($criteria->cacheKey('Europe/Prague'), $criteria->cacheKey('America/New_York'));
        self::assertNotSame($criteria->cacheKey('Europe/Prague'), $criteria->cacheKey(null));
    }

    public function testTheTimeOfDayNeedsATimeZone(): void
    {
        $criteria = InsightsCriteria::fromUserInput(['timeOfDay' => ['evening']], isMember: true, hasTimezone: false);

        self::assertSame([], $criteria->timesOfDay);
    }

    public function testInvalidValuesFallBackToTheDefaults(): void
    {
        $criteria = InsightsCriteria::fromUserInput([
            'period' => 'custom',
            'from' => '2026-02-31',
            'to' => 'yesterday',
            'pieces' => 'lots',
            'results' => ['first'],
            'events' => 'sometimes',
        ], isMember: true);

        self::assertSame(InsightsPeriod::Last24Months, $criteria->period);
        self::assertNull($criteria->customFrom);
        self::assertTrue($criteria->pieces->isUnbounded());
        self::assertSame(InsightsResultsFilter::All, $criteria->results);
        self::assertSame(InsightsInclusionFilter::Any, $criteria->events);
        self::assertSame([], $criteria->toQueryParameters());

        $pastYear = InsightsCriteria::fromUserInput(['period' => 'past_year', 'year' => '1899'], isMember: true);
        self::assertSame(InsightsPeriod::Last24Months, $pastYear->period);
    }

    public function testPeriodBounds(): void
    {
        $now = InsightsTestResults::now();

        $threeMonths = InsightsCriteria::fromUserInput(['period' => '3m'], isMember: true);
        self::assertSame('2026-07-15 00:00:00', $threeMonths->periodFrom($now)?->format('Y-m-d H:i:s'));
        self::assertNull($threeMonths->periodBefore());

        $thisYear = InsightsCriteria::fromUserInput(['period' => 'year'], isMember: true);
        self::assertSame('2026-01-01', $thisYear->periodFrom($now)?->format('Y-m-d'));

        $pastYear = InsightsCriteria::fromUserInput(['period' => 'past_year', 'year' => '2024'], isMember: true);
        self::assertSame('2024-01-01', $pastYear->periodFrom($now)?->format('Y-m-d'));
        self::assertSame('2025-01-01', $pastYear->periodBefore()?->format('Y-m-d'));
        self::assertTrue($pastYear->inPeriod(new \DateTimeImmutable('2024-12-31 23:00:00', new \DateTimeZone('UTC')), $now));
        self::assertFalse($pastYear->inPeriod(new \DateTimeImmutable('2025-01-01 00:00:00', new \DateTimeZone('UTC')), $now));

        $custom = InsightsCriteria::fromUserInput(['period' => 'custom', 'to' => '2026-03-01'], isMember: true);
        self::assertNull($custom->periodFrom($now));
        self::assertTrue($custom->inPeriod(new \DateTimeImmutable('2026-03-01 21:00:00', new \DateTimeZone('UTC')), $now));
        self::assertFalse($custom->inPeriod(new \DateTimeImmutable('2026-03-02 00:00:00', new \DateTimeZone('UTC')), $now));

        self::assertNull(InsightsCriteria::fromUserInput(['period' => 'all'], isMember: true)->periodFrom($now));

        // Month ends never roll over into the next month
        $sixMonths = InsightsCriteria::fromUserInput(['period' => '6m'], isMember: true);
        $endOfAugust = new \DateTimeImmutable('2026-08-31 15:00:00', new \DateTimeZone('UTC'));
        self::assertSame('2026-02-28 00:00:00', $sixMonths->periodFrom($endOfAugust)?->format('Y-m-d H:i:s'));
        self::assertSame('2027-02-28', InsightsCriteria::fromUserInput(['period' => '12m'], isMember: true)->periodFrom(new \DateTimeImmutable('2028-02-29', new \DateTimeZone('UTC')))?->format('Y-m-d'));
        self::assertSame('2026-04-30', InsightsCriteria::fromUserInput(['period' => '3m'], isMember: true)->periodFrom(new \DateTimeImmutable('2026-07-31', new \DateTimeZone('UTC')))?->format('Y-m-d'));
    }

    public function testFiltersMatchResultsAndPuzzles(): void
    {
        $criteria = InsightsCriteria::fromUserInput([
            'pieces' => '500',
            'personality' => ['memorable'],
            'traits' => ['photo'],
            'weekdays' => ['6'],
            'results' => 'first',
        ], isMember: true);

        $matching = InsightsTestResults::result(['memorabilityScore' => 1.5, 'imageTagKeys' => ['style.photo_aerial', 'trait.dark']]);

        self::assertTrue($criteria->matchesPuzzle($matching));
        self::assertTrue($criteria->matchesResult($matching, 6, null));
        self::assertFalse($criteria->matchesResult($matching, 5, null), 'weekday');
        self::assertFalse($criteria->matchesPuzzle(InsightsTestResults::result(['memorabilityScore' => 1.5])), 'no photo tag');
        self::assertFalse($criteria->matchesPuzzle(InsightsTestResults::result(['memorabilityScore' => 1.2, 'imageTagKeys' => ['style.photo']])), 'not memorable');
        self::assertFalse($criteria->matchesPuzzle(InsightsTestResults::result(['piecesCount' => 1000, 'memorabilityScore' => 1.5, 'imageTagKeys' => ['style.photo']])), 'pieces');
        self::assertFalse($criteria->matchesResult(InsightsTestResults::result(['firstAttempt' => false, 'memorabilityScore' => 1.5, 'imageTagKeys' => ['style.photo']]), 6, null), 'repeat');

        self::assertSame(500, $criteria->singlePiecesClass());
        self::assertNull(InsightsCriteria::fromUserInput(['pieces' => '300-1000'], isMember: true)->singlePiecesClass());
    }
}
