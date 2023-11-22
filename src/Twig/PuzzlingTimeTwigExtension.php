<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Twig;

use SpeedPuzzling\Web\Services\PuzzlingTimeFormatter;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

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
            new TwigFilter('hoursElapsed', [$this->timeFormatter, 'hoursElapsed']),
            new TwigFilter('minutesElapsed', [$this->timeFormatter, 'minutesElapsed']),
            new TwigFilter('secondsElapsed', [$this->timeFormatter, 'secondsElapsed']),
        ];
    }
}
