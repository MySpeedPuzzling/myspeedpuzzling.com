<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\Insights;

use DateTimeImmutable;
use SpeedPuzzling\Web\Results\Insights\InsightsConsistencySection;
use SpeedPuzzling\Web\Results\InsightsProfileAxisScore;
use SpeedPuzzling\Web\Value\InsightsProfileAxis;

/**
 * "How consistent am I?" - q75 ÷ q25 of your residuals (the profile card's consistency, on the selection), the last
 * RECENT_DAYS against before, and the card's percentile against all puzzlers.
 */
readonly final class ConsistencyBuilder
{
    public const int RECENT_DAYS = 182;

    /**
     * @param list<InsightsRow> $rows the selection
     * @param list<InsightsProfileAxisScore> $profileAxes
     */
    public function build(array $rows, array $profileAxes, DateTimeImmutable $now): InsightsConsistencySection
    {
        $all = [];
        $recent = [];
        $earlier = [];
        $recentFrom = $now->getTimestamp() - self::RECENT_DAYS * InsightsMath::DAY;

        foreach ($rows as $row) {
            if ($row->residual === null) {
                continue;
            }

            $all[] = $row->residual;

            if ($row->solveMoment >= $recentFrom) {
                $recent[] = $row->residual;
            } else {
                $earlier[] = $row->residual;
            }
        }

        $spread = self::spread($all);
        $percentile = null;

        foreach ($profileAxes as $axis) {
            if ($axis->axis === InsightsProfileAxis::Consistency) {
                $percentile = $axis->percentile;
            }
        }

        return new InsightsConsistencySection(
            spread: $spread,
            plusMinus: $spread !== null ? ($spread - 1) / 2 : null,
            sampleSize: count($all),
            recentSpread: self::spread($recent),
            recentSampleSize: count($recent),
            earlierSpread: self::spread($earlier),
            earlierSampleSize: count($earlier),
            percentile: $percentile,
            notSureYet: $spread === null,
        );
    }

    /**
     * @param list<float> $residuals
     */
    public static function spread(array $residuals): null|float
    {
        if (count($residuals) < InsightsConsistencySection::MIN_RESULTS) {
            return null;
        }

        sort($residuals);
        $low = InsightsMath::quantileOfSorted($residuals, 0.25);

        return $low > 0 ? InsightsMath::quantileOfSorted($residuals, 0.75) / $low : null;
    }
}
