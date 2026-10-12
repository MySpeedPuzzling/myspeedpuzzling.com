<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Twig;

use SpeedPuzzling\Web\Value\EditionName;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * `edition_name(editionName, seriesName, seriesShortcut)` - the edition's own name without the series name or shortcut
 * it starts with, null when nothing else is left (EditionName). For labels that put the series in front.
 */
final class EditionNameTwigExtension extends AbstractExtension
{
    /**
     * @return array<TwigFunction>
     */
    public function getFunctions(): array
    {
        return [
            new TwigFunction('edition_name', EditionName::withoutSeries(...)),
        ];
    }
}
