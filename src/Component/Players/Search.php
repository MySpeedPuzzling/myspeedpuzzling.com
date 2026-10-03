<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Component\Players;

use SpeedPuzzling\Web\Query\GetSolvedTotals;
use SpeedPuzzling\Web\Query\SearchPlayers;
use SpeedPuzzling\Web\Results\PlayerIdentification;
use SpeedPuzzling\Web\Value\SearchQuery;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;
use Symfony\UX\LiveComponent\Metadata\UrlMapping;

/**
 * Instant search on the Players page (docs/features/players-page/README.md, stream S1): results by name or #code
 * while typing, each a person row that opens the player card. The query lives in the URL as `?search=`, so a
 * shared or reloaded link - and the form without JavaScript - render the same results on the server.
 *
 * SearchPlayers decides who is found: blocked players never, a private player only by their exact code and then
 * masked (unless the viewer is on their allow list).
 */
#[AsLiveComponent]
final class Search
{
    use DefaultActionTrait;

    public const int LIMIT = 10;

    public const int MINIMUM_LENGTH = 2;

    // Nobody's name or code is longer - anything beyond is cut before it reaches the LIKE patterns
    private const int MAXIMUM_LENGTH = 100;

    #[LiveProp(writable: true, url: new UrlMapping(as: 'search'))]
    public string $query = '';

    /** The page's scope (`?scope=`), kept by the form when it is submitted without JavaScript */
    #[LiveProp]
    public string $scope = '';

    /** @var null|list<PlayerIdentification> */
    private null|array $found = null;

    public function __construct(
        readonly private SearchPlayers $searchPlayers,
        readonly private GetSolvedTotals $getSolvedTotals,
    ) {
    }

    /**
     * The query as it is searched: letters, digits and spaces only (a leading "#" of a code goes too).
     */
    public function getSearchString(): string
    {
        return trim(mb_substr((new SearchQuery($this->query))->value, 0, self::MAXIMUM_LENGTH));
    }

    public function isEmpty(): bool
    {
        return trim($this->query) === '';
    }

    public function isTooShort(): bool
    {
        return $this->isEmpty() === false && mb_strlen($this->getSearchString()) < self::MINIMUM_LENGTH;
    }

    /**
     * The best matches, at most LIMIT.
     *
     * @return list<PlayerIdentification>
     */
    public function getPlayers(): array
    {
        return array_slice($this->found(), 0, self::LIMIT);
    }

    /**
     * More puzzlers match than are shown - the visitor is told to type more.
     */
    public function hasMore(): bool
    {
        return count($this->found()) > self::LIMIT;
    }

    /**
     * Puzzles solved by the shown players the viewer may see; a masked private player gets no number.
     *
     * @return array<string, int>
     */
    public function getSolvedTotals(): array
    {
        $visibleIds = [];

        foreach ($this->getPlayers() as $player) {
            if ($player->isPrivate === false) {
                $visibleIds[] = $player->playerId;
            }
        }

        return $this->getSolvedTotals->byPlayerIds($visibleIds);
    }

    /**
     * @return list<PlayerIdentification>
     */
    private function found(): array
    {
        if ($this->found !== null) {
            return $this->found;
        }

        if ($this->isEmpty() || $this->isTooShort()) {
            return $this->found = [];
        }

        // One more than shown, to know whether there are more
        return $this->found = $this->searchPlayers->fulltext($this->getSearchString(), limit: self::LIMIT + 1);
    }
}
