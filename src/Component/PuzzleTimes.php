<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Component;

use SpeedPuzzling\Web\Query\GetPuzzleSolvers;
use SpeedPuzzling\Web\Results\PlayersPerCountry;
use SpeedPuzzling\Web\Results\PuzzleSolver;
use SpeedPuzzling\Web\Results\PuzzleSolversGroup;
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
    public bool $onlyUnboxed = false;

    #[LiveProp(writable: true)]
    public bool $onlyFavoritePlayers = false;

    #[LiveProp(writable: true)]
    public null|string $country = null;

    public null|int $myRank = null;
    public null|int $averageTime = null;
    public null|int $myTime = null;
    public int $soloTimesCount = 0;
    public int $duoTimesCount = 0;
    public int $groupTimesCount = 0;
    public int $soloRelaxCount = 0;
    public int $duoRelaxCount = 0;
    public int $groupRelaxCount = 0;

    /** @var array<string, array<PuzzleSolver|PuzzleSolversGroup>> */
    public array $times = [];

    /** @var array<string, int> */
    public array $availableCountries = [];

    public function __construct(
        readonly private GetPuzzleSolvers $getPuzzleSolvers,
        readonly private PuzzlesSorter $puzzlesSorter,
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
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

        $loggedProfile = $this->retrieveLoggedUserProfile->getProfile();
        $loggedPlayerId = $loggedProfile?->playerId;

        if (in_array($this->category, ['solo', 'duo', 'group'], true) === false) {
            $this->category = 'solo';
        }

        if ($this->category !== 'solo') {
            $this->onlyFirstTries = false;
            $this->onlyUnboxed = false;
        }

        $soloPuzzleSolvers = $this->getPuzzleSolvers->soloByPuzzleId($this->puzzleId);

        if ($this->onlyFirstTries === true) {
            $soloPuzzleSolvers = $this->puzzlesSorter->sortByFirstTry($soloPuzzleSolvers);
            $soloPuzzleSolversGrouped = $this->puzzlesSorter->groupPlayers($soloPuzzleSolvers);
            $soloPuzzleSolversGrouped = $this->puzzlesSorter->filterOutNonFirstTriesGrouped($soloPuzzleSolversGrouped);
        } else {
            $soloPuzzleSolvers = $this->puzzlesSorter->sortByFastest($soloPuzzleSolvers);
            $soloPuzzleSolversGrouped = $this->puzzlesSorter->groupPlayers($soloPuzzleSolvers);
        }

        if ($this->onlyUnboxed === true) {
            $soloPuzzleSolversGrouped = $this->puzzlesSorter->filterOutNonUnboxedGrouped($soloPuzzleSolversGrouped);
        }

        $duoPuzzleSolvers = $this->getPuzzleSolvers->duoByPuzzleId($this->puzzleId);
        $duoPuzzleSolvers = $this->puzzlesSorter->sortByFastest($duoPuzzleSolvers);
        $duoPuzzleSolversGrouped = $this->puzzlesSorter->groupPlayers($duoPuzzleSolvers);

        $teamPuzzleSolvers = $this->getPuzzleSolvers->teamByPuzzleId($this->puzzleId);
        $teamPuzzleSolvers = $this->puzzlesSorter->sortByFastest($teamPuzzleSolvers);
        $teamPuzzleSolversGrouped = $this->puzzlesSorter->groupPlayers($teamPuzzleSolvers);

        // Filter out private profiles (unless they belong to the logged user)
        $soloPuzzleSolversGrouped = $this->puzzlesSorter->filterOutPrivateProfiles($soloPuzzleSolversGrouped, $loggedPlayerId);
        $duoPuzzleSolversGrouped = $this->puzzlesSorter->filterOutPrivateProfiles($duoPuzzleSolversGrouped, $loggedPlayerId);
        $teamPuzzleSolversGrouped = $this->puzzlesSorter->filterOutPrivateProfiles($teamPuzzleSolversGrouped, $loggedPlayerId);

        if ($this->category === 'group') {
            $this->times = $teamPuzzleSolversGrouped;
        } elseif ($this->category === 'duo') {
            $this->times = $duoPuzzleSolversGrouped;
        } else {
            $this->times = $soloPuzzleSolversGrouped;
        }

        $this->availableCountries = [];

        foreach ($this->times as $grouped) {
            if ($grouped[0] instanceof PuzzleSolversGroup) {
                // This prevents to add 4 times for team of 4 US puzzlers
                $countedCountries = [];

                foreach ($grouped[0]->players as $player) {
                    $countryCode = $player->playerCountry;

                    if ($countryCode !== null) {
                        if (($countedCountries[$countryCode->name] ?? null) === null) {
                            $this->availableCountries[$countryCode->name] = ($this->availableCountries[$countryCode->name] ?? 0) + 1;
                            $countedCountries[$countryCode->name] = true;
                        }
                    }
                }
            }

            if ($grouped[0] instanceof PuzzleSolver) {
                $countryCode = $grouped[0]->playerCountry;
                if ($countryCode !== null) {
                    $this->availableCountries[$countryCode->name] = ($this->availableCountries[$countryCode->name] ?? 0) + 1;
                }
            }
        }

        $activeCountry = CountryCode::fromCode($this->country);

        if ($this->country !== null && $activeCountry === null) {
            $this->country = null;
        }

        if ($activeCountry !== null) {
            $soloPuzzleSolversGrouped = $this->puzzlesSorter->filterByCountry($soloPuzzleSolversGrouped, $activeCountry);
            $duoPuzzleSolversGrouped = $this->puzzlesSorter->filterByCountry($duoPuzzleSolversGrouped, $activeCountry);
            $teamPuzzleSolversGrouped = $this->puzzlesSorter->filterByCountry($teamPuzzleSolversGrouped, $activeCountry);

            if ($this->category === 'group') {
                $this->times = $teamPuzzleSolversGrouped;
            } elseif ($this->category === 'duo') {
                $this->times = $duoPuzzleSolversGrouped;
            } else {
                $this->times = $soloPuzzleSolversGrouped;
            }
        }

        if ($this->onlyFavoritePlayers === true && $loggedProfile !== null) {
            $favoritePlayers = $loggedProfile->favoritePlayers;

            // Include logged user in their own favorites filter
            if ($loggedPlayerId !== null) {
                $favoritePlayers[] = $loggedPlayerId;
            }

            $soloPuzzleSolversGrouped = $this->puzzlesSorter->filterByFavoritePlayers($soloPuzzleSolversGrouped, $favoritePlayers);
            $duoPuzzleSolversGrouped = $this->puzzlesSorter->filterByFavoritePlayers($duoPuzzleSolversGrouped, $favoritePlayers);
            $teamPuzzleSolversGrouped = $this->puzzlesSorter->filterByFavoritePlayers($teamPuzzleSolversGrouped, $favoritePlayers);

            if ($this->category === 'group') {
                $this->times = $teamPuzzleSolversGrouped;
            } elseif ($this->category === 'duo') {
                $this->times = $duoPuzzleSolversGrouped;
            } else {
                $this->times = $soloPuzzleSolversGrouped;
            }
        }

        $myRank = null;
        $myTime = null;
        $totalTime = 0;

        $i = 0;
        foreach ($this->times as $groupedSolver) {
            $i++;
            $result = $groupedSolver[0];

            $totalTime += $result->time;

            if ($result instanceof PuzzleSolver) {
                if ($myRank === null && $result->playerId === $loggedPlayerId) {
                    $myRank = $i;
                    $myTime = $result->time;
                }
            }

            if ($result instanceof PuzzleSolversGroup) {
                if ($myRank === null && $result->containsPlayer($loggedPlayerId) === true) {
                    $myRank = $i;
                    $myTime = $result->time;
                }
            }
        }

        $this->myRank = $myRank;
        $this->myTime = $myTime;
        $this->averageTime = (int) ($totalTime / max(1, count($this->times)));
        $this->soloTimesCount = count($soloPuzzleSolversGrouped);
        $this->duoTimesCount = count($duoPuzzleSolversGrouped);
        $this->groupTimesCount = count($teamPuzzleSolversGrouped);

        $relaxCounts = $this->getPuzzleSolvers->relaxCountsByPuzzleId($this->puzzleId);
        $this->soloRelaxCount = $relaxCounts['solo'];
        $this->duoRelaxCount = $relaxCounts['duo'];
        $this->groupRelaxCount = $relaxCounts['team'];
    }

    /**
     * @return array<PlayersPerCountry>
     */
    public function getCountries(): array
    {
        $availableCountries = [];

        foreach ($this->availableCountries as $countryCode => $count) {
            $availableCountries[] = new PlayersPerCountry(CountryCode::fromCode($countryCode), $count);
        }

        usort($availableCountries, function (PlayersPerCountry $a, PlayersPerCountry $b): int {
            return $b->playersCount <=> $a->playersCount;
        });

        return $availableCountries;
    }

    public function getActiveFiltersCount(): int
    {
        $count = 0;

        if ($this->onlyFirstTries !== false) {
            $count++;
        }

        if ($this->onlyUnboxed !== false) {
            $count++;
        }

        if ($this->onlyFavoritePlayers !== false) {
            $count++;
        }

        if ($this->country !== null) {
            $count++;
        }

        return $count;
    }
}
