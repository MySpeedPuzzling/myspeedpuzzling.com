<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

/**
 * Fits the prediction model on everything as it is now (BrandPiecesPrior's groups, tau, the thin-size rules, the
 * window calibration) and rewrites the drift and spread with it - PredictionModelRecalculator::fit(). Handled
 * synchronously by `myspeedpuzzling:fit-prediction-model` (daily cron).
 */
readonly final class FitPredictionModel
{
}
