<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Twig;

use SpeedPuzzling\Web\Value\PiecesRange;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * `pieces_presets()` - the piece-count quick-pick chips (PiecesRange::PRESETS),
 * so every piece-count filter renders the same set.
 */
final class PiecesRangeTwigExtension extends AbstractExtension
{
    /**
     * @return array<TwigFunction>
     */
    public function getFunctions(): array
    {
        return [
            new TwigFunction('pieces_presets', PiecesRange::presets(...)),
        ];
    }
}
