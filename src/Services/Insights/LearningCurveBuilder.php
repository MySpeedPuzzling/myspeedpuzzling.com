<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\Insights;

use SpeedPuzzling\Web\Results\Insights\InsightsLearnedPuzzle;
use SpeedPuzzling\Web\Results\Insights\InsightsLearningPoint;
use SpeedPuzzling\Web\Results\Insights\InsightsLearningSection;

/**
 * "How fast do I learn a puzzle?" - attempt N ÷ attempt 1, for attempts 1-4:
 * - you: the cumulative product of your player_improvement_ratio medians (the batch's numbers, all your puzzles);
 * - the community: the same of global_improvement_ratio for the selection's piece count (500 for several), through
 *   LearningFactors (≥ 30 transitions, else the 500-piece ones);
 * - you within the selection (puzzle filters): per puzzle t_N ÷ t_1 × your usual then ÷ your usual at N (your form
 *   taken out), the median per attempt from MIN_SELECTION_SAMPLES on (the prototype's renderLearn()).
 * Unboxed results are left out of the selection curve and the list.
 */
readonly final class LearningCurveBuilder
{
    public const int MIN_SELECTION_SAMPLES = 3;

    /**
     * @param list<InsightsRow> $rows every row passing the puzzle filters, all time, oldest first
     * @param array<int, array{ratio: float, sample_size: int}> $playerRatios from attempt => ratio (InsightsContext)
     */
    public function build(array $rows, array $playerRatios, LearningFactors $community, int $communityPieces): InsightsLearningSection
    {
        $you = [new InsightsLearningPoint(1, 1.0, 0)];
        $factor = 1.0;

        for ($attempt = 2; $attempt <= LearningFactors::MAX_ATTEMPT; $attempt++) {
            $transition = $playerRatios[$attempt - 1] ?? null;
            $factor = $factor !== null && $transition !== null ? $factor * $transition['ratio'] : null;
            $you[] = new InsightsLearningPoint($attempt, $factor, $transition['sample_size'] ?? 0);
        }

        $communityCurve = [];

        for ($attempt = 1; $attempt <= LearningFactors::MAX_ATTEMPT; $attempt++) {
            $communityCurve[] = new InsightsLearningPoint($attempt, $community->factor($communityPieces, $attempt), 0);
        }

        /** @var array<string, list<InsightsRow>> $byPuzzle */
        $byPuzzle = [];

        foreach ($rows as $row) {
            if ($row->result->unboxed === false) {
                $byPuzzle[$row->result->puzzleId][] = $row;
            }
        }

        /** @var array<int, list<float>> $perAttempt */
        $perAttempt = [];
        $learned = [];
        $repeated = 0;

        foreach ($byPuzzle as $puzzleId => $puzzleRows) {
            $firstRow = $puzzleRows[0];

            if (count($puzzleRows) < 2 || $firstRow->result->attempt !== 1) {
                continue;
            }

            $repeated++;
            $best = $firstRow->result->seconds;

            foreach (array_slice($puzzleRows, 1) as $row) {
                $best = min($best, $row->result->seconds);

                if ($firstRow->form !== null && $row->form !== null && $row->form > 0) {
                    $attempt = min($row->result->attempt, LearningFactors::MAX_ATTEMPT);
                    $perAttempt[$attempt][] = $row->result->seconds / $firstRow->result->seconds * ($firstRow->form / $row->form);
                }
            }

            $gain = 1 - $best / $firstRow->result->seconds;

            if ($gain > 0) {
                $learned[] = new InsightsLearnedPuzzle((string) $puzzleId, count($puzzleRows), $firstRow->result->seconds, $best, $gain);
            }
        }

        $inSelection = [new InsightsLearningPoint(1, 1.0, $repeated)];

        for ($attempt = 2; $attempt <= LearningFactors::MAX_ATTEMPT; $attempt++) {
            $samples = $perAttempt[$attempt] ?? [];
            $inSelection[] = new InsightsLearningPoint(
                $attempt,
                count($samples) >= self::MIN_SELECTION_SAMPLES ? InsightsMath::median($samples) : null,
                count($samples),
            );
        }

        usort($learned, static fn (InsightsLearnedPuzzle $a, InsightsLearnedPuzzle $b): int => [$b->gain, $a->puzzleId] <=> [$a->gain, $b->puzzleId]);

        return new InsightsLearningSection(
            you: $you,
            community: $communityCurve,
            communityPiecesCount: $communityPieces,
            inSelection: $inSelection,
            learnedMost: array_slice($learned, 0, InsightsLearningSection::LIST_LIMIT),
            repeatedPuzzleCount: $repeated,
            notSureYet: $you[1]->factor === null && $inSelection[1]->factor === null,
        );
    }
}
