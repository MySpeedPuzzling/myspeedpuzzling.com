<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Component\Players;

use SpeedPuzzling\Web\Query\GetCommunityScopeStats;
use SpeedPuzzling\Web\Results\CommunityScopeStatistics;
use SpeedPuzzling\Web\Query\GetPlayersDirectory;
use SpeedPuzzling\Web\Results\PlayersDirectoryPage;
use SpeedPuzzling\Web\Value\CommunityScope;
use SpeedPuzzling\Web\Value\PlayersDirectoryCriteria;
use SpeedPuzzling\Web\Value\PlayersDirectorySort;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

/**
 * The directory of puzzlers (docs/features/players-page/README.md, stream S5): filters, sorts, cards, Show more.
 * Used by "Browse all puzzlers" (any scope, "Where" in the filter bar) and the country pages (`scopeFixed`: the
 * country is in the path). A plain GET form: every list is a URL, "Show more" asks for a longer page.
 */
#[AsTwigComponent]
final class Directory
{
    public PlayersDirectoryCriteria $criteria;

    // On a country page the country is in the path - no "Where", and every link stays on that page
    public bool $scopeFixed = false;

    public function __construct(
        readonly private GetPlayersDirectory $getPlayersDirectory,
        readonly private GetCommunityScopeStats $getCommunityScopeStats,
        readonly private UrlGeneratorInterface $urlGenerator,
    ) {
        $this->criteria = new PlayersDirectoryCriteria(CommunityScope::world());
    }

    public function getPage(): PlayersDirectoryPage
    {
        return $this->getPlayersDirectory->search($this->criteria);
    }

    /**
     * The "Where" choices: every country with registered puzzlers, most puzzlers first (the country typeahead's order,
     * World above them in the template), plus the current one if it has none. Not needed (and not read) on a country
     * page.
     *
     * @return list<CommunityScopeStatistics>
     */
    public function getCountries(): array
    {
        $countries = $this->getCommunityScopeStats->countries();
        $current = $this->criteria->scope->country;

        if ($current !== null) {
            foreach ($countries as $statistics) {
                if ($statistics->country() === $current) {
                    return $countries;
                }
            }

            $countries[] = CommunityScopeStatistics::empty($this->criteria->scope);
        }

        return $countries;
    }

    /**
     * @return list<PlayersDirectorySort>
     */
    public function getSorts(): array
    {
        return PlayersDirectorySort::cases();
    }

    /**
     * Where the filter form submits: this page without any parameter - the form brings them all.
     */
    public function getFormAction(): string
    {
        if ($this->scopeFixed && $this->criteria->scope->country !== null) {
            return $this->urlGenerator->generate('players_per_country', [
                'countryCode' => $this->criteria->scope->country->name,
            ]);
        }

        return $this->urlGenerator->generate('players_directory');
    }

    /**
     * The same list one page longer, landing on its first new card; null when the list ends or the page is at its
     * maximum.
     */
    public function showMoreUrl(PlayersDirectoryPage $page): null|string
    {
        $next = $this->criteria->nextLimit();

        if ($next === null || $page->hasMore() === false) {
            return null;
        }

        return $this->url(array_merge($this->criteria->queryParameters(), ['limit' => $next])) . '#players-directory-card-' . ($this->criteria->limit + 1);
    }

    /**
     * The same scope and order without any filter.
     */
    public function getClearFiltersUrl(): string
    {
        $parameters = $this->criteria->sort->isDefault() ? [] : ['sort' => $this->criteria->sort->value];

        return $this->url($parameters) . '#players-directory';
    }

    /**
     * @param array<string, string|int> $parameters
     */
    private function url(array $parameters): string
    {
        if ($this->scopeFixed && $this->criteria->scope->country !== null) {
            return $this->urlGenerator->generate('players_per_country', [
                'countryCode' => $this->criteria->scope->country->name,
            ] + $parameters);
        }

        $scope = $this->criteria->scope->queryValue();

        return $this->urlGenerator->generate('players_directory', ($scope === null ? [] : ['scope' => $scope]) + $parameters);
    }
}
