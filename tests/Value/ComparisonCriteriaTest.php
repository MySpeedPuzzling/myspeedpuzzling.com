<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Value;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Value\ComparisonCriteria;
use SpeedPuzzling\Web\Value\ComparisonPeriod;
use SpeedPuzzling\Web\Value\ComparisonShow;
use SpeedPuzzling\Web\Value\ComparisonSort;
use SpeedPuzzling\Web\Value\ComparisonTimes;

final class ComparisonCriteriaTest extends TestCase
{
    private const string BRAND_A = '018d0002-0000-0000-0000-000000000001';
    private const string BRAND_B = '018d0002-0000-0000-0000-000000000002';
    private const string PLAYER_A = '018d0000-0000-0000-0000-000000000001';
    private const string PLAYER_B = '018d0000-0000-0000-0000-000000000002';

    public function testDefaultsFollowTheLineUpSize(): void
    {
        $two = ComparisonCriteria::fromUserInput(subjectCount: 2, isMember: false);

        self::assertSame(ComparisonShow::Everyone, $two->show);
        self::assertSame(ComparisonTimes::Best, $two->times);
        self::assertSame(ComparisonPeriod::All, $two->period);
        self::assertSame(ComparisonSort::Recent, $two->sort);
        self::assertTrue($two->pieces->isUnbounded());
        self::assertSame([], $two->brandIds);
        self::assertSame([], $two->difficultyTiers);
        self::assertSame(0, $two->offset);
        self::assertSame(ComparisonCriteria::PAGE_SIZE, $two->limit);
        self::assertNull($two->highlightA);
        self::assertNull($two->highlightB);
        self::assertSame([], $two->toQueryParameters());

        // D10: three and more compare what at least two solved
        $five = ComparisonCriteria::fromUserInput(subjectCount: 5, isMember: false);
        self::assertSame(ComparisonShow::TwoPlus, $five->show);
        self::assertSame(2, $five->show->minimumSolvers(5));
        self::assertSame([], $five->toQueryParameters());

        // An explicit "everyone" in a big line-up is not the default, so it is kept in the URL
        $everyone = ComparisonCriteria::fromUserInput(subjectCount: 5, isMember: false, show: 'everyone');
        self::assertSame(ComparisonShow::Everyone, $everyone->show);
        self::assertSame(5, $everyone->show->minimumSolvers(5));
        self::assertSame(['show' => 'everyone'], $everyone->toQueryParameters());
    }

    public function testTwoPlusWithTwoSubjectsIsEveryone(): void
    {
        $criteria = ComparisonCriteria::fromUserInput(subjectCount: 2, isMember: true, show: 'two_plus');

        self::assertSame(ComparisonShow::Everyone, $criteria->show);
        self::assertSame('everyone', $criteria->normalized()['show']);

        $all = ComparisonCriteria::fromUserInput(subjectCount: 2, isMember: false, show: 'all');
        self::assertSame(ComparisonShow::All, $all->show);
        self::assertSame(1, $all->show->minimumSolvers(2));
        self::assertSame(['show' => 'all'], $all->toQueryParameters());
    }

    public function testInvalidValuesFallBackToDefaults(): void
    {
        $criteria = ComparisonCriteria::fromUserInput(
            subjectCount: 3,
            isMember: true,
            show: 'nonsense',
            times: 'worst',
            period: '7y',
            pieces: 'abc',
            brands: ['not-a-uuid', ['nested'], 42],
            difficulty: ['7', '-1', 'x', ['1'], '1.5'],
            sort: 'random',
            highlightA: 'x-123',
            highlightB: 'p-not-a-uuid',
            offset: '-5',
            limit: 'lots',
        );

        self::assertSame(ComparisonShow::TwoPlus, $criteria->show);
        self::assertSame(ComparisonTimes::Best, $criteria->times);
        self::assertSame(ComparisonPeriod::All, $criteria->period);
        self::assertTrue($criteria->pieces->isUnbounded());
        self::assertSame([], $criteria->brandIds);
        self::assertSame([], $criteria->difficultyTiers);
        self::assertSame(ComparisonSort::Recent, $criteria->sort);
        self::assertNull($criteria->highlightA);
        self::assertNull($criteria->highlightB);
        self::assertSame(0, $criteria->offset);
        self::assertSame(ComparisonCriteria::PAGE_SIZE, $criteria->limit);
    }

    public function testMembersOnlyValuesAreStrippedForFreePlayers(): void
    {
        $input = [
            'subjectCount' => 3,
            'times' => 'first',
            'period' => 'custom',
            'from' => '2026-01-01',
            'to' => '2026-03-31',
            'brands' => [self::BRAND_A],
            'difficulty' => ['3', '0'],
            'sort' => 'difficulty',
        ];

        $free = ComparisonCriteria::fromUserInput(...[...$input, 'isMember' => false]);

        self::assertSame(ComparisonTimes::Best, $free->times);
        self::assertSame(ComparisonPeriod::All, $free->period);
        self::assertNull($free->customFrom);
        self::assertNull($free->customTo);
        self::assertSame([], $free->brandIds);
        self::assertSame([], $free->difficultyTiers);
        self::assertSame(ComparisonSort::Recent, $free->sort);
        self::assertFalse($free->needsDifficulty());
        self::assertSame([], $free->toQueryParameters());

        $member = ComparisonCriteria::fromUserInput(...[...$input, 'isMember' => true]);

        self::assertSame(ComparisonTimes::FirstTries, $member->times);
        self::assertSame(ComparisonPeriod::Custom, $member->period);
        self::assertSame('2026-01-01', $member->customFrom?->format('Y-m-d'));
        self::assertSame('2026-03-31', $member->customTo?->format('Y-m-d'));
        self::assertSame([self::BRAND_A], $member->brandIds);
        self::assertSame([0, 3], $member->difficultyTiers);
        self::assertSame(ComparisonSort::Difficulty, $member->sort);
        self::assertTrue($member->needsDifficulty());
    }

    public function testFreeFiltersStayForEveryone(): void
    {
        $criteria = ComparisonCriteria::fromUserInput(
            subjectCount: 2,
            isMember: false,
            period: '6m',
            pieces: '500',
            sort: 'pieces',
        );

        self::assertSame(ComparisonPeriod::Last6Months, $criteria->period);
        self::assertSame(500, $criteria->pieces->minPieces);
        self::assertSame(500, $criteria->pieces->maxPieces);
        self::assertSame(ComparisonSort::Pieces, $criteria->sort);
        self::assertSame(['period' => '6m', 'pieces' => '500', 'sort' => 'pieces'], $criteria->toQueryParameters());
    }

    public function testRelativePeriodsStartAtMidnight(): void
    {
        $now = new DateTimeImmutable('2026-10-03 14:35:10');

        $twelve = ComparisonCriteria::fromUserInput(subjectCount: 2, isMember: false, period: '12m');
        self::assertSame('2025-10-03 00:00:00', $twelve->solvedFrom($now)?->format('Y-m-d H:i:s'));
        self::assertNull($twelve->solvedBefore());

        $three = ComparisonCriteria::fromUserInput(subjectCount: 2, isMember: false, period: '3m');
        self::assertSame('2026-07-03 00:00:00', $three->solvedFrom($now)?->format('Y-m-d H:i:s'));

        $all = ComparisonCriteria::fromUserInput(subjectCount: 2, isMember: false);
        self::assertNull($all->solvedFrom($now));
        self::assertNull($all->solvedBefore());
    }

    public function testCustomRangeIsValidatedAndOrdered(): void
    {
        $now = new DateTimeImmutable('2026-10-03 14:35:10');

        $swapped = ComparisonCriteria::fromUserInput(subjectCount: 2, isMember: true, period: 'custom', from: '2026-05-31', to: '2026-02-01');
        self::assertSame('2026-02-01 00:00:00', $swapped->solvedFrom($now)?->format('Y-m-d H:i:s'));
        // Inclusive last day: the bound is the midnight after it
        self::assertSame('2026-06-01 00:00:00', $swapped->solvedBefore()?->format('Y-m-d H:i:s'));
        self::assertSame(['period' => 'custom', 'from' => '2026-02-01', 'to' => '2026-05-31'], $swapped->toQueryParameters());

        $openEnd = ComparisonCriteria::fromUserInput(subjectCount: 2, isMember: true, period: 'custom', from: '2026-02-01', to: '2026-02-31');
        self::assertSame(ComparisonPeriod::Custom, $openEnd->period);
        self::assertNull($openEnd->customTo, 'A date that does not exist is no date');
        self::assertNull($openEnd->solvedBefore());

        $nothing = ComparisonCriteria::fromUserInput(subjectCount: 2, isMember: true, period: 'custom', from: '1800-01-01', to: 'yesterday');
        self::assertSame(ComparisonPeriod::All, $nothing->period, 'Custom without a usable bound is all time');
    }

    public function testBrandsAreUniqueLowercaseAndCapped(): void
    {
        $brands = [strtoupper(self::BRAND_A), self::BRAND_A, self::BRAND_B];

        for ($i = 0; $i < 30; $i++) {
            $brands[] = sprintf('018d0002-0000-0000-0000-%012d', 100 + $i);
        }

        $criteria = ComparisonCriteria::fromUserInput(subjectCount: 2, isMember: true, brands: $brands);

        self::assertCount(ComparisonCriteria::MAX_BRANDS, $criteria->brandIds);
        self::assertSame([self::BRAND_A, self::BRAND_B], array_slice($criteria->brandIds, 0, 2));
    }

    public function testDifficultyTiersAreUniqueAndSorted(): void
    {
        $criteria = ComparisonCriteria::fromUserInput(subjectCount: 2, isMember: true, difficulty: ['6', 2, '0', '2', 6]);

        self::assertSame([0, 2, 6], $criteria->difficultyTiers);
        self::assertSame(['difficulty' => ['0', '2', '6']], $criteria->toQueryParameters());
    }

    public function testHighlightRefs(): void
    {
        $criteria = ComparisonCriteria::fromUserInput(subjectCount: 3, isMember: false, highlightA: 'P-' . strtoupper(self::PLAYER_A), highlightB: 'p-' . self::PLAYER_B);

        self::assertSame('p-' . self::PLAYER_A, $criteria->highlightA?->toString());
        self::assertSame('p-' . self::PLAYER_B, $criteria->highlightB?->toString());
        self::assertSame(['highlightA' => 'p-' . self::PLAYER_A, 'highlightB' => 'p-' . self::PLAYER_B], $criteria->toQueryParameters());

        $same = ComparisonCriteria::fromUserInput(subjectCount: 3, isMember: false, highlightA: 'p-' . self::PLAYER_A, highlightB: 'p-' . self::PLAYER_A);
        self::assertNotNull($same->highlightA);
        self::assertNull($same->highlightB, 'A subject is not highlighted against itself');
    }

    public function testLeadAndLagNeedTwoSubjects(): void
    {
        self::assertSame(ComparisonSort::Lead, ComparisonCriteria::fromUserInput(subjectCount: 2, isMember: false, sort: 'lead')->sort);
        self::assertSame(ComparisonSort::Lag, ComparisonCriteria::fromUserInput(subjectCount: 4, isMember: false, sort: 'lag')->sort);
        self::assertSame(ComparisonSort::Recent, ComparisonCriteria::fromUserInput(subjectCount: 1, isMember: false, sort: 'lead')->sort);
    }

    public function testPagingIsClampedToSteps(): void
    {
        $criteria = ComparisonCriteria::fromUserInput(subjectCount: 2, isMember: false, offset: '100', limit: '120');
        self::assertSame(100, $criteria->offset);
        self::assertSame(150, $criteria->limit, 'Whole "Show more" steps');

        $huge = ComparisonCriteria::fromUserInput(subjectCount: 2, isMember: false, offset: 99999999, limit: 99999);
        self::assertSame(ComparisonCriteria::MAX_OFFSET, $huge->offset);
        self::assertSame(ComparisonCriteria::MAX_LIMIT, $huge->limit);

        $tiny = ComparisonCriteria::fromUserInput(subjectCount: 2, isMember: false, limit: 1);
        self::assertSame(ComparisonCriteria::PAGE_SIZE, $tiny->limit);

        self::assertArrayNotHasKey('offset', $criteria->toQueryParameters(), 'Paging is never shared');
    }

    public function testNormalizedReflectsAppliedValues(): void
    {
        $criteria = ComparisonCriteria::fromUserInput(
            subjectCount: 4,
            isMember: true,
            times: 'first',
            period: '12m',
            pieces: '1000-500',
            brands: [self::BRAND_B],
            difficulty: ['4'],
            sort: 'name',
            highlightA: 'p-' . self::PLAYER_A,
        );

        self::assertSame([
            'show' => 'two_plus',
            'times' => 'first',
            'period' => '12m',
            'from' => null,
            'to' => null,
            'pieces' => '500-1000',
            'brands' => [self::BRAND_B],
            'difficulty' => [4],
            'sort' => 'name',
            'highlightA' => 'p-' . self::PLAYER_A,
            'highlightB' => null,
            'offset' => 0,
            'limit' => ComparisonCriteria::PAGE_SIZE,
        ], $criteria->normalized());
        self::assertTrue($criteria->needsPuzzleNames());
    }
}
