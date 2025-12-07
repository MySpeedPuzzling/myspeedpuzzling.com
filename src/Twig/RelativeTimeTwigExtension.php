<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Twig;

use SpeedPuzzling\Web\Services\RelativeTimeFormatter;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

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
}
