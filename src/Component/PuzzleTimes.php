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

    /**
     * Rows rendered in the table until the visitor asks for more. The page used to carry the
     * whole leaderboard (up to ~1,700 rows / 8 MB of HTML on popular puzzles); everyone stays
     * reachable through the "show more" / "show all" actions (buttons, not crawlable links).
     */
    public const int DEFAULT_LIMIT = 100;

    #[LiveProp]
    public null|string $puzzleId = null;

    #[LiveProp]
    public null|int $piecesCount = null;

    #[LiveProp]
    public string $category = 'solo';

    // Not writable: changed only by the actions below, and reset whenever the list changes
    #[LiveProp]
    public int $limit = self::DEFAULT_LIMIT;

    #[LiveProp(writable: true, onUpdated: 'onFilterUpdated')]
    public bool $onlyFirstTries = false;

    #[LiveProp(writable: true, onUpdated: 'onFilterUpdated')]
    public bool $onlyUnboxed = false;

    #[LiveProp(writable: true, onUpdated: 'onFilterUpdated')]
    public bool $onlyFavoritePlayers = false;

    // Pair / team tabs only: the results the viewer took part in
    #[LiveProp(writable: true, onUpdated: 'onFilterUpdated')]
    public bool $onlyMyTeams = false;

    #[LiveProp(writable: true, onUpdated: 'onFilterUpdated')]
    public null|string $country = null;

    public null|int $myRank = null;
    public null|int $averageTime = null;
    public null|int $medianTime = null;
    public null|int $myTime = null;
    public int $soloTimesCount = 0;
    public int $duoTimesCount = 0;
    public int $groupTimesCount = 0;
    public int $soloRelaxCount = 0;
    public int $duoRelaxCount = 0;
    public int $groupRelaxCount = 0;

    /**
     * The whole filtered leaderboard - the chart, the median/average and "your rank" read all of it
     *
     * @var array<string, array<PuzzleSolver|PuzzleSolversGroup>>
     */
    public array $times = [];

    /**
     * Rank of every row of $times; a row with the same time as the row above shares its rank
     *
     * @var array<string, int>
     */
    public array $ranks = [];

    /**
     * The rows the table renders: the first $limit rows of $times, keys preserved, plus the
     * viewer's own row when it lies beyond them ($ownRowBeyondLimit)
     *
     * @var array<string, array<PuzzleSolver|PuzzleSolversGroup>>
     */
    public array $visibleTimes = [];

    // The viewer's row is appended after the visible rows, behind a "⋯" row, so "Jump to me" always has a target
    public bool $ownRowBeyondLimit = false;

    /** @var array<PuzzleSolver|PuzzleSolversGroup> */
    public array $myAttempts = [];

    public null|PuzzleSolver|PuzzleSolversGroup $myLastAttempt = null;

    public null|PuzzleSolver|PuzzleSolversGroup $myFastestAttempt = null;

    public null|string $myRowKey = null;

    /** @var array<string, int> */
    public array $availableCountries = [];

    // showAll() runs before the rows are loaded (populate() is a PreReRender hook), so the total is resolved there
    private bool $showAllRequested = false;

    public function __construct(
        readonly private GetPuzzleSolvers $getPuzzleSolvers,
        readonly private PuzzlesSorter $puzzlesSorter,
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
    ) {
    }

    #[LiveAction]
    public function changeResultsCategory(#[LiveArg] string $category): void
    {
        if (in_array($category, ['solo', 'duo', 'group'], true) && $category !== $this->category) {
            $this->category = $category;
            $this->limit = self::DEFAULT_LIMIT;
        }
    }

    #[LiveAction]
    public function showMore(): void
    {
        $this->limit += self::DEFAULT_LIMIT;
    }

    #[LiveAction]
    public function showAll(): void
    {
        $this->showAllRequested = true;
    }

    /**
     * LiveProp onUpdated hook of every filter: a differently filtered list starts from its top again
     */
    public function onFilterUpdated(): void
    {
        $this->limit = self::DEFAULT_LIMIT;
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
        $rawSoloAttempts = $soloPuzzleSolvers;

        if ($this->onlyFirstTries === true) {
            $soloPuzzleSolvers = $this->puzzlesSorter->sortByFirstTry($soloPuzzleSolvers);
        } elseif ($this->onlyUnboxed === true) {
            // Unboxed attempt must lead each player group - otherwise a faster
            // non-unboxed attempt becomes the visible row and the unboxed time
            // stays hidden under the "show more" toggle
            $soloPuzzleSolvers = $this->puzzlesSorter->sortByUnboxed($soloPuzzleSolvers);
        } else {
            $soloPuzzleSolvers = $this->puzzlesSorter->sortByFastest($soloPuzzleSolvers);
        }

        $soloPuzzleSolversGrouped = $this->puzzlesSorter->groupPlayers($soloPuzzleSolvers);

        // Apply filters: when both are checked, use combined filter (AND logic)
        if ($this->onlyFirstTries === true && $this->onlyUnboxed === true) {
            $soloPuzzleSolversGrouped = $this->puzzlesSorter->filterByFirstAttemptAndUnboxedGrouped($soloPuzzleSolversGrouped);
        } elseif ($this->onlyFirstTries === true) {
            $soloPuzzleSolversGrouped = $this->puzzlesSorter->filterOutNonFirstTriesGrouped($soloPuzzleSolversGrouped);
        } elseif ($this->onlyUnboxed === true) {
            $soloPuzzleSolversGrouped = $this->puzzlesSorter->filterOutNonUnboxedGrouped($soloPuzzleSolversGrouped);
        }

        $duoPuzzleSolvers = $this->getPuzzleSolvers->duoByPuzzleId($this->puzzleId);
        $rawDuoAttempts = $duoPuzzleSolvers;
        $duoPuzzleSolvers = $this->puzzlesSorter->sortByFastest($duoPuzzleSolvers);
        $duoPuzzleSolversGrouped = $this->puzzlesSorter->groupPlayers($duoPuzzleSolvers);

        $teamPuzzleSolvers = $this->getPuzzleSolvers->teamByPuzzleId($this->puzzleId);
        $rawTeamAttempts = $teamPuzzleSolvers;
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

        if ($this->onlyMyTeams === true && $loggedPlayerId !== null && $this->category !== 'solo') {
            $this->times = array_filter(
                $this->times,
                static fn(array $grouped): bool => $grouped[0] instanceof PuzzleSolversGroup && $grouped[0]->containsPlayer($loggedPlayerId),
            );
        }

        $myRank = null;
        $myTime = null;
        $totalTime = 0;
        $allTimes = [];

        $i = 0;
        foreach ($this->times as $groupedSolver) {
            $i++;
            $result = $groupedSolver[0];

            $totalTime += $result->time;
            $allTimes[] = $result->time;

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
        $count = count($this->times);
        $this->averageTime = (int) ($totalTime / max(1, $count));

        if ($count > 0) {
            sort($allTimes);
            $mid = intdiv($count, 2);
            $this->medianTime = $count % 2 === 0
                ? (int) (($allTimes[$mid - 1] + $allTimes[$mid]) / 2)
                : $allTimes[$mid];
        } else {
            $this->medianTime = null;
        }
        $this->soloTimesCount = count($soloPuzzleSolversGrouped);
        $this->duoTimesCount = count($duoPuzzleSolversGrouped);
        $this->groupTimesCount = count($teamPuzzleSolversGrouped);

        $relaxCounts = $this->getPuzzleSolvers->relaxCountsByPuzzleId($this->puzzleId);
        $this->soloRelaxCount = $relaxCounts['solo'];
        $this->duoRelaxCount = $relaxCounts['duo'];
        $this->groupRelaxCount = $relaxCounts['team'];

        $this->myAttempts = [];
        $this->myLastAttempt = null;
        $this->myFastestAttempt = null;
        $this->myRowKey = null;

        if ($loggedPlayerId !== null) {
            $rawForCategory = match ($this->category) {
                'duo' => $rawDuoAttempts,
                'group' => $rawTeamAttempts,
                default => $rawSoloAttempts,
            };

            $myAttempts = [];
            foreach ($rawForCategory as $attempt) {
                if ($attempt instanceof PuzzleSolver && $attempt->playerId === $loggedPlayerId) {
                    $myAttempts[] = $attempt;
                } elseif ($attempt instanceof PuzzleSolversGroup && $attempt->containsPlayer($loggedPlayerId) === true) {
                    $myAttempts[] = $attempt;
                }
            }

            usort($myAttempts, static function (PuzzleSolver|PuzzleSolversGroup $a, PuzzleSolver|PuzzleSolversGroup $b): int {
                $aDate = $a->finishedAt ?? $a->trackedAt;
                $bDate = $b->finishedAt ?? $b->trackedAt;

                return $bDate <=> $aDate ?: $b->trackedAt <=> $a->trackedAt;
            });

            $this->myAttempts = $myAttempts;

            if ($myAttempts !== []) {
                $this->myLastAttempt = $myAttempts[0];

                $fastest = $myAttempts[0];
                foreach ($myAttempts as $attempt) {
                    if ($attempt->time !== null && ($fastest->time === null || $attempt->time < $fastest->time)) {
                        $fastest = $attempt;
                    }
                }

                $this->myFastestAttempt = $fastest;
            }

            foreach ($this->times as $rowKey => $grouped) {
                $first = $grouped[0];

                if ($first instanceof PuzzleSolver && $first->playerId === $loggedPlayerId) {
                    $this->myRowKey = $rowKey;
                    break;
                }

                if ($first instanceof PuzzleSolversGroup && $first->containsPlayer($loggedPlayerId) === true) {
                    $this->myRowKey = $rowKey;
                    break;
                }
            }
        }

        $this->sliceVisibleRows();
    }

    /**
     * Rows still hidden below the visible ones (the viewer's own row counts among them even when it is shown out of order)
     */
    public function getHiddenRowsCount(): int
    {
        return max(0, count($this->times) - $this->limit);
    }

    public function getShowMoreCount(): int
    {
        return min(self::DEFAULT_LIMIT, $this->getHiddenRowsCount());
    }

    /**
     * Slicing happens after every filter and sort, so filters keep working on the whole leaderboard
     */
    private function sliceVisibleRows(): void
    {
        if ($this->showAllRequested === true) {
            $this->limit = count($this->times);
        }

        $this->limit = max(1, $this->limit);
        $this->ranks = [];
        $position = 0;
        $rank = 0;
        $previousTime = null;

        foreach ($this->times as $rowKey => $grouped) {
            $position++;
            $time = $grouped[0]->time;

            if ($position === 1 || $time !== $previousTime) {
                $rank = $position;
            }

            $this->ranks[$rowKey] = $rank;
            $previousTime = $time;
        }

        $this->visibleTimes = array_slice($this->times, 0, $this->limit, preserve_keys: true);
        $this->ownRowBeyondLimit = false;

        if (
            $this->myRowKey !== null
            && isset($this->times[$this->myRowKey])
            && isset($this->visibleTimes[$this->myRowKey]) === false
        ) {
            $this->visibleTimes[$this->myRowKey] = $this->times[$this->myRowKey];
            $this->ownRowBeyondLimit = true;
        }
    }

    /**
     * @return list<array{attempt: PuzzleSolver|PuzzleSolversGroup, prevTime: null|int, isBest: bool}>
     */
    public function getMyAttemptsWithContext(): array
    {
        if ($this->myAttempts === [] || $this->myFastestAttempt === null) {
            return [];
        }

        $bestTime = $this->myFastestAttempt->time;
        $chronological = array_values(array_reverse($this->myAttempts));
        $rows = [];

        foreach ($chronological as $i => $attempt) {
            $rows[] = [
                'attempt' => $attempt,
                'prevTime' => $i > 0 ? $chronological[$i - 1]->time : null,
                'isBest' => $attempt->time === $bestTime,
            ];
        }

        return array_reverse($rows);
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
