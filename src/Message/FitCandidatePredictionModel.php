<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

/**
 * Fits the candidate prediction model (v4, never shown) on everything as it is now - CandidateModelRecalculator::fit().
 * Handled synchronously by `myspeedpuzzling:fit-candidate-prediction-model` (daily cron, its own process after the
 * shown model's fit: nothing of the candidate can roll back or hold up the shown one).
 */
readonly final class FitCandidatePredictionModel
{
}
