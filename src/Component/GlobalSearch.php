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
use Symfony\UX\LiveComponent\DefaultActionTrait;

#[AsLiveComponent]
final class GlobalSearch
{
    use DefaultActionTrait;

    public function __construct(
        readonly private SearchPuzzle $searchPuzzle,
        readonly private SearchPlayers $searchPlayers,
    ) {
    }

    #[LiveProp(writable: true)]
    public string $query = '';

    /**
     * @return list<PlayerIdentification>
     */
    public function getPlayers(): array
    {
        if ($this->query === '') {
            return [];
        }

        return $this->searchPlayers->fulltext($this->query, limit: 10);
    }

    /**
     * @return list<PuzzleOverview>
     */
    public function getPuzzle(): array
    {
        if ($this->query === '') {
            return [];
        }

        return $this->searchPuzzle->byUserInput(
            brandId: null,
            search: $this->query,
            pieces: PiecesFilter::Any,
            tag: null,
            limit: 15,
        );
    }
}
