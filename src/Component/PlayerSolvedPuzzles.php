<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Component;

use DateTimeImmutable;
use SpeedPuzzling\Web\Query\GetPlayerSolvedPuzzles;
use SpeedPuzzling\Web\Query\GetRanking;
use SpeedPuzzling\Web\Results\PlayerRanking;
use SpeedPuzzling\Web\Results\SolvedPuzzle;
use SpeedPuzzling\Web\Services\PuzzlesSorter;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
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

    #[LiveProp(writable: true)]
    public string $category = 'solo';

    #[LiveProp(writable: true)]
    public bool $onlyFirstTries = false;

    #[LiveProp(writable: true)]
    public string $sortBy = 'fastest';

    #[LiveProp(writable: true)]
    public null|string $manufacturer = null;

    #[LiveProp(writable: true)]
    public null|string $piecesCountRange = null;

    #[LiveProp(writable: true)]
    public null|string $searchQuery = null;

    #[LiveProp(writable: true)]
    public bool $onlyRelax = false;

    #[LiveProp(writable: true)]
    public null|string $dateFrom = null;

    #[LiveProp(writable: true)]
    public null|string $dateTo = null;

    #[LiveProp(writable: true)]
    public null|float $speedValue = null;

    #[LiveProp(writable: true)]
    public string $speedComparison = 'faster';

    #[LiveProp(writable: true)]
    public string $speedUnit = 'ppm';

    /** @var array<array<SolvedPuzzle>> */
    public array $teamSolvedPuzzles = [];

    /** @var array<array<SolvedPuzzle>> */
    public array $duoSolvedPuzzles = [];

    /** @var array<array<SolvedPuzzle>> */
    public array $soloSolvedPuzzles = [];

    /** @var array<PlayerRanking> */
    public array $ranking = [];

    /** @var array<SolvedPuzzle> */
    private array $allSoloPuzzles = [];

    /** @var array<SolvedPuzzle> */
    private array $allDuoPuzzles = [];

    /** @var array<SolvedPuzzle> */
    private array $allTeamPuzzles = [];

    public function __construct(
        readonly private PuzzlesSorter $puzzlesSorter,
        readonly private GetPlayerSolvedPuzzles $getPlayerSolvedPuzzles,
        readonly private GetRanking $getRanking,
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
    ) {
    }

    private function hasMembership(): bool
    {
        $player = $this->retrieveLoggedUserProfile->getProfile();

        return $player !== null && $player->activeMembership;
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
        if (in_array($sort, ['fastest', 'slowest', 'newest', 'oldest', 'fastest_ppm', 'slowest_ppm'], true)) {
            $this->sortBy = $sort;
        }
    }

    #[LiveAction]
    public function resetFilters(): void
    {
        $this->manufacturer = null;
        $this->piecesCountRange = null;
        $this->searchQuery = null;
        $this->onlyRelax = false;
        $this->onlyFirstTries = false;
        $this->dateFrom = null;
        $this->dateTo = null;
        $this->speedValue = null;
        $this->speedComparison = 'faster';
        $this->speedUnit = 'ppm';
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

        // Fetch all puzzles (unfiltered)
        $this->allSoloPuzzles = $this->getPlayerSolvedPuzzles->soloByPlayerId($this->playerId);
        $this->allDuoPuzzles = $this->getPlayerSolvedPuzzles->duoByPlayerId($this->playerId);
        $this->allTeamPuzzles = $this->getPlayerSolvedPuzzles->teamByPlayerId($this->playerId);

        // Apply filters
        $soloSolvedPuzzles = $this->applyFilters($this->allSoloPuzzles);
        $duoSolvedPuzzles = $this->applyFilters($this->allDuoPuzzles);
        $teamSolvedPuzzles = $this->applyFilters($this->allTeamPuzzles);

        // Group solo puzzles
        $soloSolvedPuzzlesGrouped = $this->puzzlesSorter->groupPuzzles($soloSolvedPuzzles, withReordering: false);

        // Only apply first tries filter if user has membership (members exclusive filter)
        if ($this->onlyFirstTries === true && $this->hasMembership()) {
            $soloSolvedPuzzlesGrouped = $this->puzzlesSorter->filterOutNonFirstTriesGrouped($soloSolvedPuzzlesGrouped);
        }

        // Apply sorting
        $soloSolvedPuzzlesGrouped = $this->applySortingGrouped($soloSolvedPuzzlesGrouped);
        $duoSolvedPuzzles = $this->applySorting($duoSolvedPuzzles);
        $teamSolvedPuzzles = $this->applySorting($teamSolvedPuzzles);

        $this->soloSolvedPuzzles = $soloSolvedPuzzlesGrouped;
        $this->duoSolvedPuzzles = $this->puzzlesSorter->groupPuzzles($duoSolvedPuzzles, withReordering: false);
        $this->teamSolvedPuzzles = $this->puzzlesSorter->groupPuzzles($teamSolvedPuzzles, withReordering: false);
    }

    /**
     * @param array<SolvedPuzzle> $puzzles
     * @return array<SolvedPuzzle>
     */
    private function applyFilters(array $puzzles): array
    {
        $isMember = $this->hasMembership();

        return array_filter($puzzles, function (SolvedPuzzle $puzzle) use ($isMember): bool {
            // FREE FILTERS - available to everyone

            // Manufacturer filter
            if ($this->manufacturer !== null && $this->manufacturer !== '' && $puzzle->manufacturerName !== $this->manufacturer) {
                return false;
            }

            // Pieces count range filter
            if ($this->piecesCountRange !== null && $this->piecesCountRange !== '' && $this->matchesPiecesRange($puzzle->piecesCount) === false) {
                return false;
            }

            // Search query filter (name, code, alternative name)
            if ($this->searchQuery !== null && $this->searchQuery !== '' && $this->matchesSearch($puzzle) === false) {
                return false;
            }

            // MEMBERS EXCLUSIVE FILTERS - only apply if user has membership
            if ($isMember) {
                // Relax filter (time === null means relax puzzle)
                if ($this->onlyRelax === true && $puzzle->time !== null) {
                    return false;
                }

                // Date range filter
                if ($this->matchesDateRange($puzzle->finishedAt) === false) {
                    return false;
                }

                // Speed filter (time in minutes or PPM)
                if ($this->matchesSpeedFilter($puzzle->time, $puzzle->piecesCount) === false) {
                    return false;
                }
            }

            return true;
        });
    }

    private function matchesPiecesRange(int $piecesCount): bool
    {
        if ($this->piecesCountRange === null) {
            return true;
        }

        return match ($this->piecesCountRange) {
            '0-499' => $piecesCount < 500,
            '500' => $piecesCount === 500,
            '501-999' => $piecesCount > 500 && $piecesCount < 1000,
            '1000' => $piecesCount === 1000,
            '1001-1999' => $piecesCount > 1000 && $piecesCount < 2000,
            '2000' => $piecesCount === 2000,
            '2001+' => $piecesCount > 2000,
            default => true,
        };
    }

    private function matchesSearch(SolvedPuzzle $puzzle): bool
    {
        if ($this->searchQuery === null || $this->searchQuery === '') {
            return true;
        }

        $normalizedQuery = $this->normalizeString($this->searchQuery);
        $searchFields = [
            $puzzle->puzzleName,
            $puzzle->puzzleAlternativeName,
            $puzzle->puzzleIdentificationNumber,
        ];

        foreach ($searchFields as $field) {
            if ($field !== null && str_contains($this->normalizeString($field), $normalizedQuery)) {
                return true;
            }
        }

        return false;
    }

    private function normalizeString(string $string): string
    {
        $normalized = transliterator_transliterate('NFD; [:Nonspacing Mark:] Remove; NFC;', $string);

        return mb_strtolower($normalized !== false ? $normalized : $string);
    }

    private function matchesDateRange(DateTimeImmutable $finishedAt): bool
    {
        if ($this->dateFrom !== null && $this->dateFrom !== '') {
            $from = DateTimeImmutable::createFromFormat('d.m.Y', $this->dateFrom);
            if ($from !== false && $finishedAt < $from->setTime(0, 0, 0)) {
                return false;
            }
        }

        if ($this->dateTo !== null && $this->dateTo !== '') {
            $to = DateTimeImmutable::createFromFormat('d.m.Y', $this->dateTo);
            if ($to !== false && $finishedAt > $to->setTime(23, 59, 59)) {
                return false;
            }
        }

        return true;
    }

    private function matchesSpeedFilter(null|int $time, int $piecesCount): bool
    {
        if ($this->speedValue === null) {
            return true;
        }

        // If filtering by speed and puzzle has no time, exclude it
        if ($time === null) {
            return false;
        }

        if ($this->speedUnit === 'min') {
            // Filter by time in minutes
            $timeMinutes = $time / 60;

            if ($this->speedComparison === 'faster') {
                return $timeMinutes < $this->speedValue;
            }

            return $timeMinutes > $this->speedValue;
        }

        // Filter by PPM (pieces per minute)
        // PPM = piecesCount / (time in minutes) = piecesCount * 60 / time
        $puzzlePpm = ($piecesCount * 60) / $time;

        if ($this->speedComparison === 'faster') {
            return $puzzlePpm > $this->speedValue;
        }

        return $puzzlePpm < $this->speedValue;
    }

    /**
     * @param array<SolvedPuzzle> $puzzles
     * @return array<SolvedPuzzle>
     */
    private function applySorting(array $puzzles): array
    {
        return match ($this->sortBy) {
            'fastest' => $this->puzzlesSorter->sortByFastest($puzzles),
            'slowest' => $this->puzzlesSorter->sortBySlowest($puzzles),
            'newest' => $this->puzzlesSorter->sortByNewest($puzzles),
            'oldest' => $this->puzzlesSorter->sortByOldest($puzzles),
            'fastest_ppm' => $this->puzzlesSorter->sortByFastestPpm($puzzles),
            'slowest_ppm' => $this->puzzlesSorter->sortBySlowestPpm($puzzles),
            default => $puzzles,
        };
    }

    /**
     * @param array<array<SolvedPuzzle>> $groupedPuzzles
     * @return array<array<SolvedPuzzle>>
     */
    private function applySortingGrouped(array $groupedPuzzles): array
    {
        return match ($this->sortBy) {
            'fastest' => $this->puzzlesSorter->sortGroupedByFastest($groupedPuzzles, $this->onlyFirstTries),
            'slowest' => $this->puzzlesSorter->sortGoupedBySlowest($groupedPuzzles, $this->onlyFirstTries),
            'newest' => $this->puzzlesSorter->sortGroupedByNewest($groupedPuzzles, $this->onlyFirstTries),
            'oldest' => $this->puzzlesSorter->sortGroupedByOldest($groupedPuzzles, $this->onlyFirstTries),
            'fastest_ppm' => $this->puzzlesSorter->sortGroupedByFastestPpm($groupedPuzzles, $this->onlyFirstTries),
            'slowest_ppm' => $this->puzzlesSorter->sortGroupedBySlowestPpm($groupedPuzzles, $this->onlyFirstTries),
            default => $groupedPuzzles,
        };
    }

    /**
     * @return array<string, int>
     */
    public function getAvailableManufacturers(): array
    {
        $manufacturers = [];
        $seenPuzzles = [];

        $allPuzzles = array_merge($this->allSoloPuzzles, $this->allDuoPuzzles, $this->allTeamPuzzles);

        // Count unique puzzles per manufacturer (not individual solving times)
        foreach ($allPuzzles as $puzzle) {
            $puzzleKey = $puzzle->puzzleId;
            if (!isset($seenPuzzles[$puzzleKey])) {
                $seenPuzzles[$puzzleKey] = true;
                $name = $puzzle->manufacturerName;
                $manufacturers[$name] = ($manufacturers[$name] ?? 0) + 1;
            }
        }

        // Sort by count descending
        arsort($manufacturers);

        return $manufacturers;
    }

    /**
     * @return array<array{value: string, label: string}>
     */
    public function getAvailablePiecesRanges(): array
    {
        $ranges = [
            ['value' => '0-499', 'label' => '< 500', 'min' => 0, 'max' => 499],
            ['value' => '500', 'label' => '500', 'min' => 500, 'max' => 500],
            ['value' => '501-999', 'label' => '501-999', 'min' => 501, 'max' => 999],
            ['value' => '1000', 'label' => '1000', 'min' => 1000, 'max' => 1000],
            ['value' => '1001-1999', 'label' => '1001-1999', 'min' => 1001, 'max' => 1999],
            ['value' => '2000', 'label' => '2000', 'min' => 2000, 'max' => 2000],
            ['value' => '2001+', 'label' => '2001+', 'min' => 2001, 'max' => PHP_INT_MAX],
        ];

        $allPuzzles = array_merge($this->allSoloPuzzles, $this->allDuoPuzzles, $this->allTeamPuzzles);

        // Filter to only show ranges that have puzzles
        return array_values(array_filter($ranges, function (array $range) use ($allPuzzles): bool {
            foreach ($allPuzzles as $puzzle) {
                if ($puzzle->piecesCount >= $range['min'] && $puzzle->piecesCount <= $range['max']) {
                    return true;
                }
            }
            return false;
        }));
    }

    public function getActiveFiltersCount(): int
    {
        $count = 0;
        $isMember = $this->hasMembership();

        // FREE FILTERS - count for everyone
        if ($this->manufacturer !== null && $this->manufacturer !== '') {
            $count++;
        }

        if ($this->piecesCountRange !== null && $this->piecesCountRange !== '') {
            $count++;
        }

        if ($this->searchQuery !== null && $this->searchQuery !== '') {
            $count++;
        }

        // MEMBERS EXCLUSIVE FILTERS - only count if user has membership
        if ($isMember) {
            if ($this->onlyFirstTries !== false) {
                $count++;
            }

            if ($this->onlyRelax !== false) {
                $count++;
            }

            if ($this->dateFrom !== null && $this->dateFrom !== '') {
                $count++;
            }

            if ($this->dateTo !== null && $this->dateTo !== '') {
                $count++;
            }

            if ($this->speedValue !== null) {
                $count++;
            }
        }

        return $count;
    }
}
