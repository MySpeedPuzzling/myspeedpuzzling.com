<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Component\Players;

use SpeedPuzzling\Web\Value\CommunityScope;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

/**
 * This week (docs/features/players-page/README.md, stream S3): On a roll - people with the most solves in the last
 * 7 days, with chips for their moments.
 */
#[AsTwigComponent]
final class ThisWeek
{
    public CommunityScope $scope;

    public function __construct()
    {
        $this->scope = CommunityScope::world();
    }
}
