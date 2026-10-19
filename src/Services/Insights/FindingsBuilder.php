<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\Insights;

use SpeedPuzzling\Web\Results\Insights\InsightsCloseCallsSection;
use SpeedPuzzling\Web\Results\Insights\InsightsExpectedSection;
use SpeedPuzzling\Web\Results\Insights\InsightsFinding;
use SpeedPuzzling\Web\Results\Insights\InsightsSkillMapSection;
use SpeedPuzzling\Web\Results\Insights\InsightsSummary;
use SpeedPuzzling\Web\Value\InsightsFindingCode;
use SpeedPuzzling\Web\Value\InsightsSection;

/**
 * The takeaways at the top (the prototype's renderTop() findings), with real puzzles where one says it best. Free
 * players get every finding but the members-only ones (shown as a lock with the count). Rating moves and shelf picks
 * are added by their own builders (stream 4c).
 */
readonly final class FindingsBuilder
{
    public const int MAX_FINDINGS = 8;

    /**
     * @param list<InsightsRow> $rows the selection
     * @return list<InsightsFinding>
     */
    public function build(
        array $rows,
        InsightsSummary $summary,
        InsightsSkillMapSection $skillMap,
        InsightsCloseCallsSection $closeCalls,
        InsightsExpectedSection $expected,
    ): array {
        $findings = [];

        if ($summary->speedRatio !== null) {
            $findings[] = new InsightsFinding(InsightsFindingCode::Speed, InsightsSection::Histogram, false, value: 1 - $summary->speedRatio, count: $summary->ratioCount);
        }

        if ($summary->trendChange !== null) {
            $findings[] = new InsightsFinding(InsightsFindingCode::FormTrend, InsightsSection::Form, false, value: $summary->trendChange);
        }

        $strength = $skillMap->strengths[0] ?? null;

        if ($strength !== null) {
            $findings[] = new InsightsFinding(InsightsFindingCode::Strength, InsightsSection::SkillMap, false, value: $strength->stats->effect, count: $strength->stats->sampleSize, segment: $strength);
        }

        $weakSpot = $skillMap->weakSpots[0] ?? null;

        if ($weakSpot !== null) {
            $findings[] = new InsightsFinding(InsightsFindingCode::WeakSpot, InsightsSection::SkillMap, false, value: $weakSpot->stats->effect, count: $weakSpot->stats->sampleSize, segment: $weakSpot);
        }

        $closest = $closeCalls->items[0] ?? null;

        if ($closest !== null && $closest->targetRank !== null) {
            $findings[] = new InsightsFinding(
                InsightsFindingCode::CloseCall,
                InsightsSection::CloseCalls,
                false,
                value: $closest->targetGapShare,
                count: $closeCalls->closeCount,
                puzzleId: $closest->puzzleId,
                rank: $closest->targetRank,
                seconds: $closest->targetGapSeconds,
            );
        }

        $best = null;
        $withResidual = 0;

        foreach ($rows as $row) {
            if ($row->residual === null) {
                continue;
            }

            $withResidual++;

            if ($best === null || $row->residual < $best->residual) {
                $best = $row;
            }
        }

        if ($best !== null && $withResidual >= SegmentEffect::MIN_VERDICT && $best->residual < 1) {
            $findings[] = new InsightsFinding(
                InsightsFindingCode::BestResult,
                InsightsSection::Outliers,
                false,
                value: 1 - (float) $best->residual,
                puzzleId: $best->result->puzzleId,
                timeId: $best->result->timeId,
                seconds: $best->result->seconds,
            );
        }

        if ($expected->notSureYet === false && $expected->betterCount > 0) {
            $biggest = $expected->better[0] ?? null;
            $findings[] = new InsightsFinding(
                InsightsFindingCode::Surprises,
                InsightsSection::ExpectedVsActual,
                true,
                count: $expected->betterCount,
                puzzleId: $biggest?->puzzleId,
                timeId: $biggest?->timeId,
            );
        }

        return array_slice($findings, 0, self::MAX_FINDINGS);
    }
}
