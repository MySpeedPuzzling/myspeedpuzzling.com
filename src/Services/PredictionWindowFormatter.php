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
        return $this->format($window, self::minutesOnly($window));
    }

    /**
     * Two windows shown together - the 50 % window and the 80 % range of a personal estimate
     * (docs/features/insights/estimates.md): one style for both, minutes only when both qualify. Apart, "1h – 1h 9min"
     * next to "57–74 min" read like two different units.
     *
     * @return array{0: string, 1: string}
     */
    public function ranges(PredictionWindow $window, PredictionWindow $range80): array
    {
        $minutesOnly = self::minutesOnly($window) && self::minutesOnly($range80);

        return [$this->format($window, $minutesOnly), $this->format($range80, $minutesOnly)];
    }

    private static function minutesOnly(PredictionWindow $window): bool
    {
        return $window->lowSeconds % 60 === 0
            && $window->highSeconds % 60 === 0
            && $window->lowSeconds < 3600
            && $window->highSeconds < self::MINUTES_ONLY_BELOW_SECONDS;
    }

    private function format(PredictionWindow $window, bool $minutesOnly): string
    {
        if ($minutesOnly) {
            return sprintf('%d–%d min', intdiv($window->lowSeconds, 60), intdiv($window->highSeconds, 60));
        }

        return $this->timeFormatter->compactTime($window->lowSeconds) . ' – ' . $this->timeFormatter->compactTime($window->highSeconds);
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
