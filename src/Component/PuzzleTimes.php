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

    /**
     * Rows shown above and below the viewer's own row when it lies beyond the top rows - so they always see
     * where they stand and who is right around them (docs/features/puzzle-leaderboard-chart.md)
     */
    public const int NEIGHBOURS = 2;

    /**
     * The position line names the gap to the nearest of these ranks above the viewer ("… from the top 500")
     */
    private const array RANK_MILESTONES = [1, 3, 10, 25, 50, 100, 250, 500, 1000, 2500, 5000];

    // Below this many rows a percentage says little ("faster than 50 %" of three people)
    private const int PERCENTILE_MIN_ROWS = 10;

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

    // Same rank as the viewer's row shows: a time equal to the row above shares its rank
    public null|int $myRank = null;

    // Share of the other rows that are slower than the viewer, rounded down - null when too few rows to say
    public null|int $myPercentile = null;

    // The nearest milestone rank above the viewer (RANK_MILESTONES) and how far behind its time they are
    public null|int $myTargetRank = null;
    public null|int $myTargetGap = null;

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
     * The rows the table renders, in leaderboard order, keys preserved: the first $limit rows of $times plus the
     * viewer's own row with NEIGHBOURS rows on either side
     *
     * @var array<string, array<PuzzleSolver|PuzzleSolversGroup>>
     */
    public array $visibleTimes = [];

    /**
     * How many rows are left out right above a visible row - the table shows a "⋯" row there
     *
     * @var array<string, int>
     */
    public array $gapsBefore = [];

    // The viewer's row lies beyond the top rows and is shown with its neighbours, so "Jump to me" always has a target
    public bool $ownRowBeyondLimit = false;

    /** @var array<PuzzleSolver|PuzzleSolversGroup> */
    public array $myAttempts = [];

    public null|PuzzleSolver|PuzzleSolversGroup $myLastAttempt = null;

    public null|PuzzleSolver|PuzzleSolversGroup $myFastestAttempt = null;

    public null|string $myRowKey = null;

    // Fastest time of the filtered leaderboard - every other row shows its gap to it
    public null|int $leaderTime = null;

    /**
     * Gap of a row to the closest faster time - only where that time is not the fastest one (the leader gap says it)
     *
     * @var array<string, int>
     */
    public array $gapsToFaster = [];

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

        $totalTime = 0;
        $allTimes = [];

        foreach ($this->times as $groupedSolver) {
            $totalTime += $groupedSolver[0]->time;
            $allTimes[] = $groupedSolver[0]->time;
        }

        $count = count($this->times);
        $this->averageTime = (int) ($totalTime / max(1, $count));

        $this->leaderTime = null;
        $this->gapsToFaster = [];

        if ($count > 0) {
            sort($allTimes);
            // A group read model types its time as nullable, the leaderboard query only returns timed rows
            $timed = array_values(array_filter($allTimes, static fn(null|int $time): bool => $time !== null));

            if ($timed !== []) {
                $this->leaderTime = $timed[0];
                $this->gapsToFaster = $this->gapsToClosestFaster($timed);
            }
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
     * @param list<int> $sortedTimes
     * @return array<string, int>
     */
    private function gapsToClosestFaster(array $sortedTimes): array
    {
        $leaderTime = $sortedTimes[0];

        // Each distinct time -> the closest faster one; tied rows share it, nobody is faster in between
        $closestFaster = [];
        $previous = null;
        foreach ($sortedTimes as $time) {
            if ($time !== $previous) {
                $closestFaster[$time] = $previous;
                $previous = $time;
            }
        }

        $gaps = [];
        foreach ($this->times as $rowKey => $grouped) {
            $time = $grouped[0]->time;
            $faster = $time !== null ? ($closestFaster[$time] ?? null) : null;

            // For rank 2 the closest faster time is the fastest one - the leader gap already says it
            if ($time !== null && $faster !== null && $faster !== $leaderTime) {
                $gaps[$rowKey] = $time - $faster;
            }
        }

        return $gaps;
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

        $rowKeys = array_keys($this->times);
        $myPosition = $this->myRowKey !== null ? array_search($this->myRowKey, $rowKeys, true) : false;

        /** @var array<int, true> $visiblePositions */
        $visiblePositions = [];

        for ($position = 0; $position < min($this->limit, count($rowKeys)); $position++) {
            $visiblePositions[$position] = true;
        }

        if (is_int($myPosition)) {
            $lastNeighbour = min(count($rowKeys) - 1, $myPosition + self::NEIGHBOURS);

            for ($position = max(0, $myPosition - self::NEIGHBOURS); $position <= $lastNeighbour; $position++) {
                $visiblePositions[$position] = true;
            }
        }

        ksort($visiblePositions);

        $this->visibleTimes = [];
        $this->gapsBefore = [];
        $previousPosition = -1;

        foreach (array_keys($visiblePositions) as $position) {
            $rowKey = $rowKeys[$position];

            if ($position > $previousPosition + 1) {
                $this->gapsBefore[$rowKey] = $position - $previousPosition - 1;
            }

            $this->visibleTimes[$rowKey] = $this->times[$rowKey];
            $previousPosition = $position;
        }

        $this->ownRowBeyondLimit = is_int($myPosition) && $myPosition >= $this->limit;

        $this->describeViewerPosition();
    }

    /**
     * The viewer's position line: "Rank 612 of 1718 · faster than 64 % of puzzlers · 00:04:12 from the top 500"
     */
    private function describeViewerPosition(): void
    {
        $this->myRank = null;
        $this->myTime = null;
        $this->myPercentile = null;
        $this->myTargetRank = null;
        $this->myTargetGap = null;

        if ($this->myRowKey === null || isset($this->ranks[$this->myRowKey]) === false) {
            return;
        }

        $this->myRank = $this->ranks[$this->myRowKey];
        $this->myTime = $myTime = $this->times[$this->myRowKey][0]->time;

        if ($this->myRank === 1 || $myTime === null) {
            return;
        }

        $times = [];

        foreach ($this->times as $grouped) {
            $times[] = $grouped[0]->time;
        }

        $total = count($times);

        if ($total >= self::PERCENTILE_MIN_ROWS) {
            $slower = count(array_filter($times, static fn (null|int $time): bool => $time !== null && $time > $myTime));
            $percentile = intdiv(100 * $slower, $total - 1);
            $this->myPercentile = $percentile > 0 ? $percentile : null;
        }

        foreach (array_reverse(self::RANK_MILESTONES) as $milestone) {
            $milestoneTime = $times[$milestone - 1] ?? null;

            if ($milestone < $this->myRank && $milestoneTime !== null) {
                $this->myTargetRank = $milestone;
                $this->myTargetGap = $myTime - $milestoneTime;

                return;
            }
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
