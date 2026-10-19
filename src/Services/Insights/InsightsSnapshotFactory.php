<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\Insights;

use SpeedPuzzling\Web\Results\Insights\InsightsReport;
use SpeedPuzzling\Web\Results\Insights\InsightsSkillSegment;
use SpeedPuzzling\Web\Results\InsightsContext;

/**
 * The monthly snapshot's JSON from a built report (members' view of the last 12 months, no filters): small on
 * purpose - the numbers a "what changed since last month" line compares. Bump VERSION when a key changes meaning.
 */
readonly final class InsightsSnapshotFactory
{
    public const int VERSION = 1;

    public const int MAX_SEGMENTS = 5;

    /**
     * @return array{
     *     version: int,
     *     period: string,
     *     results: int,
     *     speed: null|float,
     *     trend: null|float,
     *     form: null|float,
     *     consistency: null|float,
     *     strengths: list<array{group: string, key: string, label: null|string, effect: null|float, low: null|float, high: null|float, n: int}>,
     *     weak_spots: list<array{group: string, key: string, label: null|string, effect: null|float, low: null|float, high: null|float, n: int}>,
     *     rating_500: null|float,
     *     profile: array<string, null|float>,
     * }
     */
    public function fromReport(InsightsReport $report, InsightsContext $context): array
    {
        // Your usual in the latest month with results (a run on the 1st finds the new month empty)
        $usual = null;

        foreach ($report->form->months as $month) {
            $usual = $month->usual ?? $usual;
        }

        $profile = [];

        foreach ($context->profileAxes as $axis) {
            $profile[$axis->axis->value] = $axis->percentile;
        }

        return [
            'version' => self::VERSION,
            'period' => InsightsSnapshotCriteria::PERIOD->value,
            'results' => $report->summary->resultCount,
            'speed' => self::round($report->summary->speedRatio),
            'trend' => self::round($report->summary->trendChange),
            'form' => self::round($usual),
            'consistency' => self::round($report->consistency->spread),
            'strengths' => self::segments($report->skillMap->strengths),
            'weak_spots' => self::segments($report->skillMap->weakSpots),
            'rating_500' => self::round($context->rating500),
            'profile' => $profile,
        ];
    }

    /**
     * @param list<InsightsSkillSegment> $segments
     * @return list<array{group: string, key: string, label: null|string, effect: null|float, low: null|float, high: null|float, n: int}>
     */
    private static function segments(array $segments): array
    {
        return array_map(static fn (InsightsSkillSegment $segment): array => [
            'group' => $segment->group->value,
            'key' => $segment->key,
            'label' => $segment->label,
            'effect' => self::round($segment->stats->effect),
            'low' => self::round($segment->stats->low),
            'high' => self::round($segment->stats->high),
            'n' => $segment->stats->sampleSize,
        ], array_slice($segments, 0, self::MAX_SEGMENTS));
    }

    private static function round(null|float $value): null|float
    {
        return $value !== null ? round($value, 4) : null;
    }
}
