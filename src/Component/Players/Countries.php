<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Component\Players;

use SpeedPuzzling\Web\Query\GetCommunityScopeStats;
use SpeedPuzzling\Web\Results\CommunityScopeStatistics;
use SpeedPuzzling\Web\Services\Community\CountryRankings;
use SpeedPuzzling\Web\Value\CommunityScope;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

/**
 * Puzzlers around the world (docs/features/players-page/README.md, stream S4): country tiles sortable by Most active,
 * Most puzzlers and Rising, then every country in a compact list. Every tile opens the page on that country
 * (`?scope=`). All three orders are rendered and switched in the browser (players_tabs_controller.js), from the one
 * memoized countries() statement the Country Cup shares.
 */
#[AsTwigComponent]
final class Countries
{
    public CommunityScope $scope;

    public function __construct(
        readonly private GetCommunityScopeStats $getCommunityScopeStats,
        readonly private CountryRankings $countryRankings,
    ) {
        $this->scope = CommunityScope::world();
    }

    /**
     * Every country with a registered player, most registered first.
     *
     * @return list<CommunityScopeStatistics>
     */
    public function getCountries(): array
    {
        return $this->getCommunityScopeStats->countries();
    }

    /**
     * @return list<CommunityScopeStatistics>
     */
    public function getMostActive(): array
    {
        return $this->countryRankings->mostActive($this->getCountries());
    }

    /**
     * @return list<CommunityScopeStatistics>
     */
    public function getMostPuzzlers(): array
    {
        return $this->countryRankings->mostPuzzlers($this->getCountries());
    }

    /**
     * @return list<CommunityScopeStatistics>
     */
    public function getRising(): array
    {
        return $this->countryRankings->rising($this->getCountries());
    }
}
