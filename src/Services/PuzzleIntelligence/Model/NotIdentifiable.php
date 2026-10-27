<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\PuzzleIntelligence\Model;

use RuntimeException;

/**
 * The size curve cannot be fitted from the window (too few pairs, a singular system) - SizeCurveFitter. The fit keeps
 * the curve it has (CandidateModelFitter::fitSizeCurve()).
 */
final class NotIdentifiable extends RuntimeException
{
}
