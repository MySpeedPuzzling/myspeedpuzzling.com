<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

/**
 * Refreshes every puzzle's candidate evidence (v4, never shown) with the stored candidate fit -
 * CandidateModelRecalculator::refreshEvidence(). Handled synchronously by
 * `myspeedpuzzling:refresh-candidate-prediction-evidence` (every 15 minutes, its own process after the insights batch).
 */
readonly final class RefreshCandidatePredictionEvidence
{
}
