<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\Insights;

use DateTimeImmutable;
use SpeedPuzzling\Web\Results\Insights\InsightsSummary;

/**
 * The tiles: speed against the median solver (selection), form against a year ago (median ratio of the last 6
 * months ÷ 12-18 months ago, every filter but the period - the prototype's renderTop()) and consistency.
 */
readonly final class SummaryBuilder
{
    public const int MIN_RESULTS = 5;

    public const int RECENT_DAYS = 182;

    public const int OLDER_FROM_DAYS = 365;

    public const int OLDER_TO_DAYS = 547;

    /**
     * @param list<InsightsRow> $rows the selection
     * @param list<InsightsRow> $withoutPeriod the selection without its period
     */
    public function build(array $rows, array $withoutPeriod, DateTimeImmutable $now): InsightsSummary
    {
        $ratios = [];
        $residuals = [];

        foreach ($rows as $row) {
            if ($row->ratio !== null) {
                $ratios[] = $row->ratio;
            }

            if ($row->residual !== null) {
                $residuals[] = $row->residual;
            }
        }

        $recent = [];
        $older = [];
        $nowMoment = $now->getTimestamp();

        foreach ($withoutPeriod as $row) {
            if ($row->ratio === null) {
                continue;
            }

            $ageDays = ($nowMoment - $row->solveMoment) / InsightsMath::DAY;

            if ($ageDays <= self::RECENT_DAYS) {
                $recent[] = $row->ratio;
            } elseif ($ageDays > self::OLDER_FROM_DAYS && $ageDays <= self::OLDER_TO_DAYS) {
                $older[] = $row->ratio;
            }
        }

        $trend = count($recent) >= self::MIN_RESULTS && count($older) >= self::MIN_RESULTS
            ? InsightsMath::median($recent) / InsightsMath::median($older) - 1
            : null;

        return new InsightsSummary(
            resultCount: count($rows),
            ratioCount: count($ratios),
            speedRatio: count($ratios) >= self::MIN_RESULTS ? InsightsMath::median($ratios) : null,
            trendChange: $trend,
            trendRecentCount: count($recent),
            trendOlderCount: count($older),
            consistencySpread: ConsistencyBuilder::spread($residuals),
            consistencySampleSize: count($residuals),
        );
    }
}
