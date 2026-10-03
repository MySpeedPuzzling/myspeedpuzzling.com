<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Component\Players;

use SpeedPuzzling\Web\Query\GetCommunityScopeStats;
use SpeedPuzzling\Web\Results\CommunityScopeStatistics;
use SpeedPuzzling\Web\Value\CommunityScope;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

/**
 * Puzzlers around the world (docs/features/players-page/README.md, stream S4): country tiles sortable by Most active,
 * Most puzzlers and Rising. Every tile opens the page on that country (`?scope=`).
 */
#[AsTwigComponent]
final class Countries
{
    public CommunityScope $scope;

    public function __construct(
        readonly private GetCommunityScopeStats $getCommunityScopeStats,
    ) {
        $this->scope = CommunityScope::world();
    }

    /**
     * @return list<CommunityScopeStatistics>
     */
    public function getCountries(): array
    {
        return $this->getCommunityScopeStats->countries();
    }
}
