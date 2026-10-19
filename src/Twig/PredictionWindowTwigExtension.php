<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Twig;

use SpeedPuzzling\Web\Results\TimePredictionResult;
use SpeedPuzzling\Web\Services\PredictionWindowFormatter;
use SpeedPuzzling\Web\Value\PredictionWindow;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * How prediction windows are shown (PredictionWindow = the rounding, PredictionWindowFormatter = the text):
 * `prediction_window(prediction)` → the shown 50 % "likely" window of a TimePredictionResult,
 * `prediction_range80(prediction)` → the shown 80 % range, null when the prediction has none (stored before model v3),
 * `window|prediction_window_range` → "57–64 min", `seconds|prediction_margin` → "3 min",
 * `prediction_window_ranges(window, range80)` → both texts in one style, for where they sit together.
 * The surfaces show the 50 % window only; the 80 % range belongs in the Insights detail sheet (Insights plan Phase 6).
 */
final class PredictionWindowTwigExtension extends AbstractExtension
{
    public function __construct(
        readonly private PredictionWindowFormatter $formatter,
    ) {
    }

    /**
     * @return array<TwigFunction>
     */
    public function getFunctions(): array
    {
        return [
            new TwigFunction('prediction_window', self::windowOf(...)),
            new TwigFunction('prediction_range80', self::range80Of(...)),
            new TwigFunction('prediction_window_ranges', $this->formatter->ranges(...)),
        ];
    }

    /**
     * @return array<TwigFilter>
     */
    public function getFilters(): array
    {
        return [
            new TwigFilter('prediction_window_range', $this->formatter->range(...)),
            new TwigFilter('prediction_margin', $this->formatter->margin(...)),
        ];
    }

    public static function windowOf(TimePredictionResult $prediction): PredictionWindow
    {
        return PredictionWindow::rounded(
            $prediction->rangeLowSeconds,
            $prediction->rangeHighSeconds,
            $prediction->predictedSeconds,
        );
    }

    public static function range80Of(TimePredictionResult $prediction): null|PredictionWindow
    {
        if ($prediction->range80LowSeconds === null || $prediction->range80HighSeconds === null) {
            return null;
        }

        return PredictionWindow::rounded(
            $prediction->range80LowSeconds,
            $prediction->range80HighSeconds,
            $prediction->predictedSeconds,
        );
    }
}
