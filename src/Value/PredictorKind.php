<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * Which predictor made a row of solving_time_prediction (docs/features/puzzle-intelligence/prediction-history.md,
 * "Side by side", "Image and blend predictions"): the statistical model (the shown one); for first tries also the
 * picture estimate alone (`image`) and the statistical model shrunk toward it (`blend`) - Insights 5d, never shown -
 * and the candidate model (`candidate`, model version CandidatePredictionCalculator::MODEL_VERSION), never shown.
 * Pair and team results (README "Pair and team predictions"): the group model (`group`) and the coordination rule it
 * is gated against (`group_baseline`), both GroupPredictionModel::MODEL_VERSION, never shown.
 */
enum PredictorKind: string
{
    case Statistical = 'statistical';
    case Image = 'image';
    case Blend = 'blend';
    // The candidate model (v4, CandidatePredictionCalculator) - first tries and repeats, its own model version, never shown
    case Candidate = 'candidate';
    // Pair/team results: members' as-of paces × coordination × difficulty × the pair/team's shrunk synergy - never shown
    case Group = 'group';
    // Pair/team results: the coordination rule alone (GroupExpectation's rule, as of the solve) - the group model's reference
    case GroupBaseline = 'group_baseline';

    /**
     * Rows of pair/team results (the others are solo results only)
     */
    public function isGroup(): bool
    {
        return $this === self::Group || $this === self::GroupBaseline;
    }
}
