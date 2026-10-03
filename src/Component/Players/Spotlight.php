<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Component\Players;

use SpeedPuzzling\Web\Query\GetCommunityScopeStats;
use SpeedPuzzling\Web\Results\CommunityScopeStatistics;
use SpeedPuzzling\Web\Value\CommunityScope;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

/**
 * The spotlight on the world or one country (docs/features/players-page/README.md, stream S2): the scope's numbers,
 * then Most active this month · Most followed · New faces.
 */
#[AsTwigComponent]
final class Spotlight
{
    public CommunityScope $scope;

    public function __construct(
        readonly private GetCommunityScopeStats $getCommunityScopeStats,
    ) {
        $this->scope = CommunityScope::world();
    }

    public function getStatistics(): CommunityScopeStatistics
    {
        return $this->getCommunityScopeStats->forScope($this->scope);
    }
}
