<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Component;

use SpeedPuzzling\Web\Query\GetPuzzleSolvers;
use SpeedPuzzling\Web\Results\PuzzleSolver;
use SpeedPuzzling\Web\Results\PuzzleSolversGroup;
use SpeedPuzzling\Web\Services\PuzzlesSorter;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveArg;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\Attribute\PreReRender;
use Symfony\UX\LiveComponent\DefaultActionTrait;
use Symfony\UX\TwigComponent\Attribute\PostMount;

#[AsLiveComponent]
final class PuzzleTimes
{
    use DefaultActionTrait;

    #[LiveProp]
    public null|string $puzzleId = null;

    #[LiveProp]
    public null|int $piecesCount = null;

    #[LiveProp]
    public string $category = 'solo';

    #[LiveProp(writable: true)]
    public bool $onlyFirstTries = false;

    #[LiveProp(writable: true)]
    public null|string $country = null;

    public null|int $myRank = null;

    public null|int $averageTime = null;

    public null|int $myTime = null;

    /** @var null|array<string, array<PuzzleSolver|PuzzleSolversGroup>> */
    private null|array $times = null;

    public function __construct(
        readonly private GetPuzzleSolvers $getPuzzleSolvers,
        readonly private PuzzlesSorter $puzzlesSorter,
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
        assert($this->puzzleId !== null);

        if (in_array($this->category, ['solo', 'duo', 'group'], true) === false) {
            $this->category = 'solo';
        }

        if ($this->category !== 'solo') {
            $this->onlyFirstTries = false;
        }

        if ($this->category === 'group') {
            $puzzleSolvers = $this->getPuzzleSolvers->teamByPuzzleId($this->puzzleId);
            $puzzleSolvers = $this->puzzlesSorter->sortByFastest($puzzleSolvers);
            $puzzleSolversGrouped = $this->puzzlesSorter->groupPlayers($puzzleSolvers);
        } elseif ($this->category === 'duo') {
            $puzzleSolvers = $this->getPuzzleSolvers->duoByPuzzleId($this->puzzleId);
            $puzzleSolvers = $this->puzzlesSorter->sortByFastest($puzzleSolvers);
            $puzzleSolversGrouped = $this->puzzlesSorter->groupPlayers($puzzleSolvers);
        } else {
            $puzzleSolvers = $this->getPuzzleSolvers->soloByPuzzleId($this->puzzleId);

            if ($this->onlyFirstTries === true) {
                $puzzleSolvers = $this->puzzlesSorter->sortByFirstTry($puzzleSolvers);
                $puzzleSolversGrouped = $this->puzzlesSorter->groupPlayers($puzzleSolvers);
                $puzzleSolversGrouped = $this->puzzlesSorter->filterOutNonFirstTriesGrouped($puzzleSolversGrouped);
            } else {
                $puzzleSolvers = $this->puzzlesSorter->sortByFastest($puzzleSolvers);
                $puzzleSolversGrouped = $this->puzzlesSorter->groupPlayers($puzzleSolvers);
            }
        }

        $this->times = $puzzleSolversGrouped;
        $this->myRank = 1; // TODO
        $this->myTime = 600; // TODO
        $this->averageTime = 1000; // TODO
    }

    /**
     * @return array<string, array<PuzzleSolver|PuzzleSolversGroup>>
     */
    public function getTimes(): array
    {
        return $this->times ?? [];
    }
}
