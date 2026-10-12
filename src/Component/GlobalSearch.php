<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Component;

use SpeedPuzzling\Web\Query\SearchPlayers;
use SpeedPuzzling\Web\Query\SearchPuzzle;
use SpeedPuzzling\Web\Results\PlayerIdentification;
use SpeedPuzzling\Web\Results\PuzzleOverview;
use SpeedPuzzling\Web\Value\PiecesRange;
use SpeedPuzzling\Web\Value\PuzzleSearchCriteria;
use SpeedPuzzling\Web\Value\PuzzleSearchQuery;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\ComponentToolsTrait;
use Symfony\UX\LiveComponent\DefaultActionTrait;

#[AsLiveComponent]
final class GlobalSearch
{
    use DefaultActionTrait;
    use ComponentToolsTrait;

    /**
     * The best matches shown; "Show all N results" leads to the catalogue for the rest
     */
    public const int PUZZLES_LIMIT = 15;

    public const string SURFACE_BAR = 'bar';
    public const string SURFACE_MENU = 'menu';
    public const int PAGES_LIMIT_BAR = 3;
    public const int PAGES_LIMIT_MENU = 8;

    public function __construct(
        readonly private SearchPuzzle $searchPuzzle,
        readonly private SearchPlayers $searchPlayers,
    ) {
    }

    #[LiveProp(writable: true, onUpdated: 'onQueryUpdated')]
    public string $query = '';

    /**
     * Where this instance lives (navigation MENU-03, R3): `bar` - the page's search, opened from the header (the top
     * bar's from NAV-03) - or `menu` - the Menu panel's field, whose results show inside the panel
     */
    #[LiveProp]
    public string $surface = self::SURFACE_BAR;

    /**
     * How many "Pages & settings" rows show before "Show N more" (decision 38: 3 in the top bar, 8 in the Menu panel)
     */
    #[LiveProp]
    public int $limit = self::PAGES_LIMIT_BAR;

    /**
     * @var null|list<PuzzleOverview>
     */
    private null|array $puzzle = null;

    private null|int $puzzleCount = null;

    public function isMenu(): bool
    {
        return $this->surface === self::SURFACE_MENU;
    }

    /**
     * @return list<PlayerIdentification>
     */
    public function getPlayers(): array
    {
        $query = trim($this->query);

        if ($query === '') {
            return [];
        }

        return $this->searchPlayers->fulltext($query, limit: 10);
    }

    /**
     * @return list<PuzzleOverview>
     */
    public function getPuzzle(): array
    {
        if ($this->puzzle !== null) {
            return $this->puzzle;
        }

        // Nothing that folds to a term (spaces, invisible characters) would list the whole catalogue
        if (PuzzleSearchQuery::fromUserInput($this->query)->isEmpty()) {
            return [];
        }

        $this->puzzle = $this->searchPuzzle->byUserInput(
            brandId: null,
            search: $this->query,
            pieces: PiecesRange::any(),
            tag: null,
            sortBy: PuzzleSearchCriteria::BEST_MATCH,
            limit: self::PUZZLES_LIMIT,
        );

        return $this->puzzle;
    }

    /**
     * Every puzzle the query matches - counted by a query only when the shown ones may not be all of them
     */
    public function getPuzzleCount(): int
    {
        $shown = count($this->getPuzzle());

        if ($shown < self::PUZZLES_LIMIT) {
            return $shown;
        }

        return $this->puzzleCount ??= $this->searchPuzzle->countByUserInput(
            brandId: null,
            search: $this->query,
            pieces: PiecesRange::any(),
            tag: null,
        );
    }

    public function onQueryUpdated(string $previousValue): void
    {
        if (count($this->getPuzzle()) > 0) {
            $this->dispatchBrowserEvent('barcode-scan:close');
        }
    }
}
