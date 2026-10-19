<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * Which predictor made a row of solving_time_prediction (docs/features/puzzle-intelligence/prediction-history.md,
 * "Side by side"): today's statistical model; the image estimate alone and the blend come with Phase 5 of the
 * Insights plan.
 */
enum PredictorKind: string
{
    case Statistical = 'statistical';
    case Image = 'image';
    case Blend = 'blend';
}
