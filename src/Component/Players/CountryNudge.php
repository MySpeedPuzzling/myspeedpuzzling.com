<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Component\Players;

use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

/**
 * "Add your country" (docs/features/players-page/README.md, stream S7): signed-in players without a country are
 * invisible to every country feature.
 */
#[AsTwigComponent]
final class CountryNudge
{
}
