<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services;

use SpeedPuzzling\Web\Value\PredictionWindow;

/**
 * The text of a prediction window and of a margin outside it, in the site's compact duration format
 * (PuzzlingTimeFormatter::compactTime(): "45min", "1h 10min", "40s"). A window starting under an hour and ending under
 * 90 min on a minute grid reads in minutes, also past the hour: "57–64 min", "50–70 min" (Jan's wording,
 * docs/features/insights/README.md §3 "Prediction windows"); otherwise in hours: "50min – 2h 20min",
 * "1h 50min – 2h 20min"; the 10-second grid: "30s – 1min".
 */
readonly final class PredictionWindowFormatter
{
    /** Minutes only ("50–70 min") while the high end is under 90 min - beyond that "50min – 2h 20min" reads better next to "~1h 20min" */
    public const int MINUTES_ONLY_BELOW_SECONDS = 5400;

    public function __construct(
        private PuzzlingTimeFormatter $timeFormatter,
    ) {
    }

    public function range(PredictionWindow $window): string
    {
        $low = $window->lowSeconds;
        $high = $window->highSeconds;

        if ($low % 60 === 0 && $high % 60 === 0 && $low < 3600 && $high < self::MINUTES_ONLY_BELOW_SECONDS) {
            return sprintf('%d–%d min', intdiv($low, 60), intdiv($high, 60));
        }

        return $this->timeFormatter->compactTime($low) . ' – ' . $this->timeFormatter->compactTime($high);
    }

    /**
     * A margin (either sign) as a positive duration: seconds under a minute ("20s"), else whole minutes ("3 min",
     * "1h 5min") - a margin is a rough distance, never more precise than the window it is measured from.
     */
    public function margin(int $seconds): string
    {
        $seconds = abs($seconds);

        if ($seconds < 60) {
            return $this->timeFormatter->compactTime($seconds);
        }

        $minutes = (int) round($seconds / 60);

        if ($minutes < 60) {
            return sprintf('%d min', $minutes);
        }

        return $this->timeFormatter->compactTime($minutes * 60);
    }
}
