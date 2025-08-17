<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Component;

use SpeedPuzzling\Web\Query\SearchPlayers;
use SpeedPuzzling\Web\Query\SearchPuzzle;
use SpeedPuzzling\Web\Results\PiecesFilter;
use SpeedPuzzling\Web\Results\PlayerIdentification;
use SpeedPuzzling\Web\Results\PuzzleOverview;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\ComponentToolsTrait;
use Symfony\UX\LiveComponent\DefaultActionTrait;

#[AsLiveComponent]
final class GlobalSearch
{
    use DefaultActionTrait;
    use ComponentToolsTrait;

    public function __construct(
        readonly private SearchPuzzle $searchPuzzle,
        readonly private SearchPlayers $searchPlayers,
    ) {
    }

    #[LiveProp(writable: true, onUpdated: 'onQueryUpdated')]
    public string $query = '';

    /**
     * @var null|list<PuzzleOverview>
     */
    private null|array $puzzle = null;

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

        $query = trim($this->query);

        if ($query === '') {
            return [];
        }

        $this->puzzle = $this->searchPuzzle->byUserInput(
            brandId: null,
            search: $query,
            pieces: PiecesFilter::Any,
            tag: null,
            limit: 15,
        );

        return $this->puzzle;
    }

    public function onQueryUpdated(string $previousValue): void
    {
        if (count($this->getPuzzle()) > 0) {
            $this->dispatchBrowserEvent('barcode-scan:close');
        }
    }
}
