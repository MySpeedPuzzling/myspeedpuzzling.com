<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Component\Players;

use SpeedPuzzling\Web\Value\CommunityScope;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

/**
 * The directory of puzzlers (docs/features/players-page/README.md, stream S5): filters, sorts, cards, Show more.
 * Used by "Browse all puzzlers" and the country pages.
 */
#[AsTwigComponent]
final class Directory
{
    public CommunityScope $scope;

    public function __construct()
    {
        $this->scope = CommunityScope::world();
    }
}
