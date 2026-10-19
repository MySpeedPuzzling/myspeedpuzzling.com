<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\Insights;

use SpeedPuzzling\Web\Results\Insights\InsightsFilterOptions;
use SpeedPuzzling\Web\Results\Insights\InsightsNudge;
use SpeedPuzzling\Web\Results\Insights\InsightsReport;
use SpeedPuzzling\Web\Value\InsightsNudgeCode;

/**
 * "Make your insights sharper" (the prototype's renderData(), plan section 16) and the members' filter options - both
 * from all of the player's results, whatever the filters: what the player can do so the page knows more, with real
 * counts. Only items that apply are listed.
 */
readonly final class NudgesBuilder
{
    /**
     * @param list<InsightsRow> $rows every result of the player, oldest first
     * @return list<InsightsNudge>
     */
    public function nudges(array $rows, bool $hasTimezone, bool $predictionsAllowed): array
    {
        $nudges = [];
        $total = count($rows);

        foreach (InsightsReport::UNLOCK_THRESHOLDS as $threshold) {
            if ($total < $threshold) {
                $nudges[] = new InsightsNudge(InsightsNudgeCode::MoreResults, count: $threshold - $total, target: $threshold);

                break;
            }
        }

        $unmarked = 0;
        $unboxed = 0;
        $unrated = [];

        foreach ($rows as $row) {
            $result = $row->result;

            if ($result->attempt === 1 && $result->firstAttempt === false && $result->unboxed === false) {
                $unmarked++;
            }

            if ($result->unboxed) {
                $unboxed++;
            }

            if ($result->difficultyTier === null) {
                $unrated[$result->puzzleId] = true;
            }
        }

        if ($predictionsAllowed === false) {
            $nudges[] = new InsightsNudge(InsightsNudgeCode::PredictionsOff);
        }

        if ($unmarked > 0) {
            $nudges[] = new InsightsNudge(InsightsNudgeCode::MarkFirstTries, count: $unmarked);
        }

        if ($hasTimezone === false) {
            $nudges[] = new InsightsNudge(InsightsNudgeCode::SetTimezone);
        }

        if ($unboxed === 0 && $total >= InsightsReport::MIN_RESULTS) {
            $nudges[] = new InsightsNudge(InsightsNudgeCode::MarkUnboxed);
        }

        if ($unrated !== []) {
            $nudges[] = new InsightsNudge(InsightsNudgeCode::UnratedPuzzles, count: count($unrated));
        }

        return $nudges;
    }

    /**
     * @param list<InsightsRow> $rows every result of the player
     */
    public function filterOptions(array $rows): InsightsFilterOptions
    {
        $brandCounts = [];
        $brandNames = [];
        $years = [];

        foreach ($rows as $row) {
            $result = $row->result;
            $years[(int) substr($row->solveDate, 0, 4)] = true;

            if ($result->manufacturerId !== null) {
                $id = strtolower($result->manufacturerId);
                $brandCounts[$id] = ($brandCounts[$id] ?? 0) + 1;
                $brandNames[$id] = $result->manufacturerName ?? '';
            }
        }

        arsort($brandCounts);
        $brands = [];

        foreach (array_slice($brandCounts, 0, InsightsFilterOptions::MAX_BRANDS, true) as $id => $count) {
            $brands[(string) $id] = $brandNames[$id];
        }

        $years = array_keys($years);
        rsort($years);

        return new InsightsFilterOptions($brands, $years);
    }
}
