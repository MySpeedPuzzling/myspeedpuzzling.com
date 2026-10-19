<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\Insights;

use SpeedPuzzling\Web\Results\Insights\InsightsDrillDownResult;
use SpeedPuzzling\Web\Results\Insights\InsightsSkillGroupResult;
use SpeedPuzzling\Web\Results\Insights\InsightsSkillMapSection;
use SpeedPuzzling\Web\Results\Insights\InsightsSkillSegment;
use SpeedPuzzling\Web\Value\InsightsImageTrait;
use SpeedPuzzling\Web\Value\InsightsPuzzlePersonality;
use SpeedPuzzling\Web\Value\InsightsSituation;
use SpeedPuzzling\Web\Value\InsightsSkillGroup;
use SpeedPuzzling\Web\Value\InsightsVerdict;
use SpeedPuzzling\Web\Value\PiecesRange;

/**
 * "Where are my strengths and weak spots?" - your residuals split by brand, piece count, difficulty, situation, puzzle
 * personality and image traits (SegmentEffect per split, segments from SegmentEffect::MIN_SHOWN results on). The
 * prototype's buildSkillGroups() on real results.
 */
readonly final class SkillMapBuilder
{
    /** The brands with the most results in the selection */
    public const int MAX_BRANDS = 9;

    /**
     * Image traits come from the picture backfill: shown as a preview group, never a strength / weak spot / finding
     * until every picture is analysed (the launch, README §1.4).
     */
    public const bool IMAGE_TRAITS_ARE_PREVIEW = true;

    /**
     * @param list<InsightsRow> $rows the selection
     */
    public function build(array $rows): InsightsSkillMapSection
    {
        $rated = array_values(array_filter($rows, static fn (InsightsRow $row): bool => $row->residual !== null));

        $groups = [];

        $byBrand = [];
        $brandNames = [];

        foreach ($rated as $row) {
            $brand = $row->result->manufacturerId;

            if ($brand !== null) {
                $byBrand[$brand][] = $row;
                $brandNames[$brand] = $row->result->manufacturerName;
            }
        }

        uasort($byBrand, static fn (array $a, array $b): int => count($b) <=> count($a));
        $groups[] = $this->group(InsightsSkillGroup::Brand, array_slice($byBrand, 0, self::MAX_BRANDS, true), $brandNames);

        $byPieces = [];

        foreach ($rated as $row) {
            $byPieces[PiecesRange::classOf($row->result->piecesCount)][] = $row;
        }

        if (count($byPieces) >= 2) {
            ksort($byPieces);
            $groups[] = $this->group(InsightsSkillGroup::Pieces, $byPieces);
        }

        $byTier = [];

        foreach ($rated as $row) {
            $byTier[$row->result->difficultyTier ?? InsightsCriteria::UNRATED_DIFFICULTY][] = $row;
        }

        ksort($byTier);
        $groups[] = $this->group(InsightsSkillGroup::Difficulty, $byTier);

        $bySituation = [];

        foreach (InsightsSituation::cases() as $situation) {
            $bySituation[$situation->value] = [];
        }

        foreach ($rated as $row) {
            foreach ($row->situations() as $situation) {
                $bySituation[$situation->value][] = $row;
            }
        }

        $groups[] = $this->group(InsightsSkillGroup::Situation, $bySituation);

        $byPersonality = [];

        foreach (InsightsPuzzlePersonality::cases() as $personality) {
            $byPersonality[$personality->value] = [];
        }

        foreach ($rated as $row) {
            foreach ($row->result->personalities() as $personality) {
                $byPersonality[$personality->value][] = $row;
            }
        }

        $groups[] = $this->group(InsightsSkillGroup::Personality, $byPersonality);

        $byTrait = [];

        foreach (InsightsImageTrait::cases() as $trait) {
            $byTrait[$trait->value] = [];
        }

        foreach ($rated as $row) {
            foreach ($row->result->imageTraits() as $trait) {
                $byTrait[$trait->value][] = $row;
            }
        }

        $groups[] = $this->group(InsightsSkillGroup::ImageTraits, $byTrait, preview: self::IMAGE_TRAITS_ARE_PREVIEW);

        $groups = array_values(array_filter($groups, static fn (InsightsSkillGroupResult $group): bool => $group->segments !== []));

        $strengths = [];
        $weakSpots = [];

        foreach ($groups as $group) {
            if ($group->preview) {
                continue;
            }

            foreach ($group->segments as $segment) {
                if ($segment->stats->verdict === InsightsVerdict::Strength) {
                    $strengths[] = $segment;
                } elseif ($segment->stats->verdict === InsightsVerdict::WeakSpot) {
                    $weakSpots[] = $segment;
                }
            }
        }

        usort($strengths, static fn (InsightsSkillSegment $a, InsightsSkillSegment $b): int => $a->stats->effect <=> $b->stats->effect);
        usort($weakSpots, static fn (InsightsSkillSegment $a, InsightsSkillSegment $b): int => $b->stats->effect <=> $a->stats->effect);

        return new InsightsSkillMapSection(
            groups: $groups,
            strengths: $strengths,
            weakSpots: $weakSpots,
            sampleSize: count($rated),
            notSureYet: count($rated) < SegmentEffect::MIN_VERDICT,
        );
    }

    /**
     * @param array<int|string, list<InsightsRow>> $split key => rows
     * @param array<int|string, null|string> $labels
     */
    private function group(InsightsSkillGroup $group, array $split, array $labels = [], bool $preview = false): InsightsSkillGroupResult
    {
        $segments = [];

        foreach ($split as $key => $rows) {
            if (count($rows) < SegmentEffect::MIN_SHOWN) {
                continue;
            }

            $stats = SegmentEffect::ofRows($rows);

            usort($rows, static fn (InsightsRow $a, InsightsRow $b): int => $a->residual <=> $b->residual);
            $drillRows = array_slice($rows, 0, InsightsSkillSegment::DRILL_DOWN_LIMIT);

            $segments[] = new InsightsSkillSegment(
                group: $group,
                key: (string) $key,
                label: $labels[$key] ?? null,
                stats: $stats,
                drillDownTimeIds: array_map(
                    static fn (InsightsRow $row): string => $row->result->timeId,
                    $drillRows,
                ),
                drillDown: array_map(
                    static fn (InsightsRow $row): InsightsDrillDownResult => new InsightsDrillDownResult(
                        timeId: $row->result->timeId,
                        puzzleId: $row->result->puzzleId,
                        seconds: $row->result->seconds,
                        solvedAt: $row->result->solvedAt,
                        piecesCount: $row->result->piecesCount,
                        manufacturerName: $row->result->manufacturerName,
                        residual: (float) $row->residual,
                    ),
                    $drillRows,
                ),
            );
        }

        usort($segments, static fn (InsightsSkillSegment $a, InsightsSkillSegment $b): int => $a->stats->effect <=> $b->stats->effect);

        return new InsightsSkillGroupResult($group, $segments, $preview);
    }
}
