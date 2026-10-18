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
 * `prediction_window(prediction)` → the shown window of a TimePredictionResult, `window|prediction_window_range` →
 * "57–64 min", `seconds|prediction_margin` → "3 min".
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
}
