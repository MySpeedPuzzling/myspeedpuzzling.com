<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\Insights;

use DateTimeImmutable;
use DateTimeZone;
use SpeedPuzzling\Web\Results\Insights\InsightsFormMonth;
use SpeedPuzzling\Web\Results\Insights\InsightsFormSection;

/**
 * "Am I getting better?" - monthly medians of your ratio to the puzzle median over the period, and the rolling usual
 * (median of your boxed first tries within ±75 days, the profile card's "form") at each mid-month. Every filter but
 * the period applies to the rows; the period only picks the months (README §3: free players see 6 months).
 */
readonly final class FormBuilder
{
    public const int MIN_MONTHS = 4;

    /** At most this many months on the chart (an all-time period of a ten-year player is 120 months) */
    public const int MAX_MONTHS = 60;

    /**
     * @param list<InsightsRow> $rows the selection without its period, oldest first
     */
    public function build(array $rows, null|DateTimeImmutable $periodFrom, null|DateTimeImmutable $periodBefore, DateTimeImmutable $now): InsightsFormSection
    {
        $utc = new DateTimeZone('UTC');
        $end = ($periodBefore ?? $now)->setTimezone($utc);

        if ($periodBefore !== null) {
            $end = $end->modify('-1 second');
        }

        // From the period's start, but never before your first result in the selection (the prototype's rule)
        $firstResult = $rows !== [] ? new DateTimeImmutable($rows[0]->solveDate . ' 00:00:00', $utc) : null;
        $start = $periodFrom?->setTimezone($utc);

        if ($start === null || ($firstResult !== null && $firstResult > $start)) {
            $start = $firstResult ?? $end;
        }

        if ($start > $end) {
            $start = $end;
        }

        $firstMonth = new DateTimeImmutable($start->format('Y-m-01') . ' 00:00:00', $utc);
        $lastMonth = new DateTimeImmutable($end->format('Y-m-01') . ' 00:00:00', $utc);

        $months = [];

        for ($month = $firstMonth; $month <= $lastMonth; $month = $month->modify('+1 month')) {
            $months[] = $month;
        }

        while (count($months) < self::MIN_MONTHS) {
            array_unshift($months, $months[0]->modify('-1 month'));
        }

        if (count($months) > self::MAX_MONTHS) {
            $months = array_slice($months, -self::MAX_MONTHS);
        }

        /** @var array<string, list<InsightsRow>> $byMonth */
        $byMonth = [];
        $points = [];

        foreach ($rows as $row) {
            if ($row->ratio === null) {
                continue;
            }

            $byMonth[substr($row->solveDate, 0, 7)][] = $row;

            if ($row->result->firstAttempt && $row->result->unboxed === false) {
                $points[] = [$row->solveMoment, $row->ratio];
            }
        }

        $midMonths = array_map(static fn (DateTimeImmutable $month): int => $month->modify('+14 days')->getTimestamp(), $months);
        $usuals = InsightsMath::rollingMedian($points, $midMonths, InsightsRowsPreparer::FORM_HALF_WINDOW);

        $result = [];
        $first = null;
        $last = null;
        $sampleSize = 0;

        foreach ($months as $index => $month) {
            $key = $month->format('Y-m');
            $monthRows = $byMonth[$key] ?? [];
            $count = count($monthRows);
            $median = null;
            $q25 = null;
            $q75 = null;
            $best = null;

            if ($count >= InsightsFormMonth::MIN_RESULTS) {
                $ratios = array_map(static fn (InsightsRow $row): float => (float) $row->ratio, $monthRows);
                sort($ratios);
                $median = InsightsMath::medianOfSorted($ratios);
                $q25 = InsightsMath::quantileOfSorted($ratios, 0.25);
                $q75 = InsightsMath::quantileOfSorted($ratios, 0.75);

                foreach ($monthRows as $row) {
                    if ($best === null || $row->ratio < $best->ratio) {
                        $best = $row;
                    }
                }

                $first ??= $median;
                $last = $median;
                $sampleSize += $count;
            }

            $result[] = new InsightsFormMonth(
                month: $key,
                count: $count,
                medianRatio: $median,
                q25Ratio: $q25,
                q75Ratio: $q75,
                usual: $usuals[$index],
                bestTimeId: $best?->result->timeId,
                bestPuzzleId: $best?->result->puzzleId,
                bestRatio: $best?->ratio,
            );
        }

        $monthsWithMedian = count(array_filter($result, static fn (InsightsFormMonth $month): bool => $month->medianRatio !== null));

        return new InsightsFormSection(
            months: $result,
            firstMedianRatio: $first,
            lastMedianRatio: $last,
            change: $monthsWithMedian >= 2 && $first !== null && $last !== null ? $last - $first : null,
            sampleSize: $sampleSize,
            notSureYet: $monthsWithMedian < 2,
        );
    }
}
