<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Component;

use SpeedPuzzling\Web\Query\GetPlayerSolvedPuzzles;
use SpeedPuzzling\Web\Query\GetPuzzleSolvers;
use SpeedPuzzling\Web\Query\GetRanking;
use SpeedPuzzling\Web\Results\PlayerRanking;
use SpeedPuzzling\Web\Results\PlayersPerCountry;
use SpeedPuzzling\Web\Results\PuzzleSolver;
use SpeedPuzzling\Web\Results\PuzzleSolversGroup;
use SpeedPuzzling\Web\Results\SolvedPuzzle;
use SpeedPuzzling\Web\Services\PuzzlesSorter;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use SpeedPuzzling\Web\Value\CountryCode;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveArg;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\Attribute\PreReRender;
use Symfony\UX\LiveComponent\DefaultActionTrait;
use Symfony\UX\TwigComponent\Attribute\PostMount;

#[AsLiveComponent]
final class PlayerTimes
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

    #[LiveProp(writable: true)]
    public null|string $sortBy = null;

    /** @var array<array<SolvedPuzzle>> */
    public array $teamSolvedPuzzles = [];

    /** @var array<array<SolvedPuzzle>> */
    public array $duoSolvedPuzzles = [];

    /** @var array<array<solvedPuzzle>> */
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

        if ($this->onlyFirstTries === true) {
            $soloSolvedPuzzles = $this->puzzlesSorter->sortByFirstTry($soloSolvedPuzzles);
            $soloSolvedPuzzlesGrouped = $this->puzzlesSorter->groupPuzzles($soloSolvedPuzzles);
            $soloSolvedPuzzlesGrouped = $this->puzzlesSorter->filterOutNonFirstTriesGrouped($soloSolvedPuzzlesGrouped);
        } else {
            $soloSolvedPuzzles = $this->puzzlesSorter->sortByFastest($soloSolvedPuzzles);
            $soloSolvedPuzzlesGrouped = $this->puzzlesSorter->groupPuzzles($soloSolvedPuzzles);
        }

        $this->soloSolvedPuzzles = $soloSolvedPuzzlesGrouped;
        $this->duoSolvedPuzzles = $this->puzzlesSorter->groupPuzzles($duoSolvedPuzzles);
        $this->teamSolvedPuzzles = $this->puzzlesSorter->groupPuzzles($teamSolvedPuzzles);
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
