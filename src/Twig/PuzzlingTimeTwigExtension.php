<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Twig;

use SpeedPuzzling\Web\Services\PuzzlingTimeFormatter;
use SpeedPuzzling\Web\Value\SolvingTime;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;

final class PuzzlingTimeTwigExtension extends AbstractExtension
{
    public function __construct(
        readonly private PuzzlingTimeFormatter $timeFormatter,
    ) {
    }

    /**
     * @return array<TwigFilter>
     */
    public function getFilters(): array
    {
        return [
            new TwigFilter('puzzlingTime', [$this->timeFormatter, 'formatTime']),
            new TwigFilter('daysElapsed', [$this->timeFormatter, 'daysElapsed']),
            new TwigFilter('hoursElapsed', [$this->timeFormatter, 'hoursElapsed']),
            new TwigFilter('minutesElapsed', [$this->timeFormatter, 'minutesElapsed']),
            new TwigFilter('secondsElapsed', [$this->timeFormatter, 'secondsElapsed']),
        ];
    }

    /**
     * @return array<TwigFunction>
     */
    public function getFunctions(): array
    {
        return [
            new TwigFunction('ppm', [$this, 'calculatePpm']),
        ];
    }

    public function calculatePpm(int $timeInSeconds, int $pieces, int $puzzlersCount = 1): float
    {
        return (new SolvingTime($timeInSeconds))->calculatePpm($pieces, $puzzlersCount);
    }
}
