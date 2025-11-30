<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Component;

use SpeedPuzzling\Web\Query\GetPlayerSolvedPuzzles;
use SpeedPuzzling\Web\Query\GetRanking;
use SpeedPuzzling\Web\Results\PlayerRanking;
use SpeedPuzzling\Web\Results\SolvedPuzzle;
use SpeedPuzzling\Web\Services\PuzzlesSorter;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveArg;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\Attribute\PreReRender;
use Symfony\UX\LiveComponent\DefaultActionTrait;
use Symfony\UX\TwigComponent\Attribute\PostMount;

#[AsLiveComponent]
final class PlayerSolvedPuzzles
{
    use DefaultActionTrait;

    #[LiveProp]
    public null|string $playerId = null;

    #[LiveProp]
    public string $category = 'solo';

    #[LiveProp(writable: true)]
    public null|int $piecesCount = null;

    #[LiveProp(writable: true)]
    public null|int $brand = null;

    #[LiveProp(writable: true)]
    public null|int $search = null;

    #[LiveProp(writable: true)]
    public bool $onlyFirstTries = false;

    #[LiveProp]
    public string $sortBy = 'fastest';

    /** @var array<array<SolvedPuzzle>> */
    public array $teamSolvedPuzzles = [];

    /** @var array<array<SolvedPuzzle>> */
    public array $duoSolvedPuzzles = [];

    /** @var array<array<SolvedPuzzle>> */
    public array $soloSolvedPuzzles = [];

    /** @var array<PlayerRanking> */
    public array $ranking = [];

    public function __construct(
        readonly private PuzzlesSorter $puzzlesSorter,
        readonly private GetPlayerSolvedPuzzles $getPlayerSolvedPuzzles,
        readonly private GetRanking $getRanking,
    ) {
    }

    #[LiveAction]
    public function changeResultsCategory(#[LiveArg] string $category): void
    {
        if (in_array($category, ['solo', 'duo', 'group'], true)) {
            $this->category = $category;
        }
    }

    #[LiveAction]
    public function changeSortBy(#[LiveArg] string $sort): void
    {
        if (in_array($sort, ['fastest', 'slowest', 'newest', 'oldest'], true)) {
            $this->sortBy = $sort;
        }
    }

    #[PostMount]
    #[PreReRender]
    public function populate(): void
    {
        assert($this->playerId !== null);

        if (in_array($this->category, ['solo', 'duo', 'group'], true) === false) {
            $this->category = 'solo';
        }

        if ($this->category !== 'solo') {
            $this->onlyFirstTries = false;
        }

        $this->ranking = $this->getRanking->allForPlayer($this->playerId);

        $soloSolvedPuzzles = $this->getPlayerSolvedPuzzles->soloByPlayerId($this->playerId);
        $duoSolvedPuzzles = $this->getPlayerSolvedPuzzles->duoByPlayerId($this->playerId);
        $teamSolvedPuzzles = $this->getPlayerSolvedPuzzles->teamByPlayerId($this->playerId);

        $soloSolvedPuzzlesGrouped = $this->puzzlesSorter->groupPuzzles($soloSolvedPuzzles, withReordering: false);

        if ($this->onlyFirstTries === true) {
            $soloSolvedPuzzlesGrouped = $this->puzzlesSorter->filterOutNonFirstTriesGrouped($soloSolvedPuzzlesGrouped);
        }

        if ($this->sortBy === 'fastest') {
            $soloSolvedPuzzlesGrouped = $this->puzzlesSorter->sortGroupedByFastest($soloSolvedPuzzlesGrouped, $this->onlyFirstTries);
            $duoSolvedPuzzles = $this->puzzlesSorter->sortByFastest($duoSolvedPuzzles);
            $teamSolvedPuzzles = $this->puzzlesSorter->sortByFastest($teamSolvedPuzzles);
        } elseif ($this->sortBy === 'slowest') {
            $soloSolvedPuzzlesGrouped = $this->puzzlesSorter->sortGoupedBySlowest($soloSolvedPuzzlesGrouped, $this->onlyFirstTries);
            $duoSolvedPuzzles = $this->puzzlesSorter->sortBySlowest($duoSolvedPuzzles);
            $teamSolvedPuzzles = $this->puzzlesSorter->sortBySlowest($teamSolvedPuzzles);
        } elseif ($this->sortBy === 'newest') {
            $soloSolvedPuzzlesGrouped = $this->puzzlesSorter->sortGroupedByNewest($soloSolvedPuzzlesGrouped, $this->onlyFirstTries);
            $duoSolvedPuzzles = $this->puzzlesSorter->sortByNewest($duoSolvedPuzzles);
            $teamSolvedPuzzles = $this->puzzlesSorter->sortByNewest($teamSolvedPuzzles);
        } elseif ($this->sortBy === 'oldest') {
            $soloSolvedPuzzlesGrouped = $this->puzzlesSorter->sortGroupedByOldest($soloSolvedPuzzlesGrouped, $this->onlyFirstTries);
            $duoSolvedPuzzles = $this->puzzlesSorter->sortByOldest($duoSolvedPuzzles);
            $teamSolvedPuzzles = $this->puzzlesSorter->sortByOldest($teamSolvedPuzzles);
        }

        $this->soloSolvedPuzzles = $soloSolvedPuzzlesGrouped;
        $this->duoSolvedPuzzles = $this->puzzlesSorter->groupPuzzles($duoSolvedPuzzles, withReordering: false);
        $this->teamSolvedPuzzles = $this->puzzlesSorter->groupPuzzles($teamSolvedPuzzles, withReordering: false);
    }

    public function getActiveFiltersCount(): int
    {
        $count = 0;

        if ($this->onlyFirstTries !== false) {
            $count++;
        }

        return $count;
    }
}
