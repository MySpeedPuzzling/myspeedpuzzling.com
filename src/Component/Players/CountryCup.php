<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Component\Players;

use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

/**
 * The Country Cup (docs/features/players-page/README.md, stream S4): pieces placed this month per active puzzler
 * (CommunityScopeStatistics::piecesPerActivePuzzlerThisMonth) or in total, the viewer's country marked.
 */
#[AsTwigComponent]
final class CountryCup
{
}
