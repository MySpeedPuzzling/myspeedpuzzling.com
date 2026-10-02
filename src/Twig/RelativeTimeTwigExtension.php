<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Twig;

use SpeedPuzzling\Web\Services\RelativeTimeFormatter;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;

final class RelativeTimeTwigExtension extends AbstractExtension
{
    public function __construct(
        readonly private RelativeTimeFormatter $relativeTimeFormatter,
    ) {
    }

    /**
     * @return array<TwigFilter>
     */
    public function getFilters(): array
    {
        return [
            new TwigFilter('ago', [$this->relativeTimeFormatter, 'formatDiff']),
        ];
    }

    /**
     * @return array<TwigFunction>
     */
    public function getFunctions(): array
    {
        return [
            new TwigFunction('relative_time_messages', [$this->relativeTimeFormatter, 'browserMessages']),
        ];
    }
}
