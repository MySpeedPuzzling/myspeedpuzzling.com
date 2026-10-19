<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * Which predictor made a row of solving_time_prediction (docs/features/puzzle-intelligence/prediction-history.md,
 * "Side by side", "Image and blend predictions"): the statistical model (the shown one); for first tries also the
 * picture estimate alone (`image`) and the statistical model shrunk toward it (`blend`) - Insights 5d, never shown.
 */
enum PredictorKind: string
{
    case Statistical = 'statistical';
    case Image = 'image';
    case Blend = 'blend';
}
