<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Component;

use DateTimeImmutable;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Query\GetPlayerSolvedPuzzles;
use SpeedPuzzling\Web\Query\GetRanking;
use SpeedPuzzling\Web\Results\PlayerRanking;
use SpeedPuzzling\Web\Results\PuzzleDifficultyRating;
use SpeedPuzzling\Web\Results\SolvedPuzzle;
use SpeedPuzzling\Web\Value\DifficultyFilter;
use SpeedPuzzling\Web\Value\DifficultyTier;
use SpeedPuzzling\Web\Value\PiecesRange;
use SpeedPuzzling\Web\Value\PuzzleSearchCriteria;
use SpeedPuzzling\Web\Value\Puzzler;
use SpeedPuzzling\Web\Services\PuzzlesSorter;
use SpeedPuzzling\Web\Services\ResolveDifficultyTiers;
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

    private const array SORTS = ['fastest', 'slowest', 'newest', 'oldest', 'fastest_ppm', 'slowest_ppm'];

    // Members only, like the difficulty filter
    private const array DIFFICULTY_SORTS = ['easiest', 'hardest'];

    #[LiveProp]
    public null|string $playerId = null;

    #[LiveProp(writable: true)]
    public string $category = 'solo';

    #[LiveProp(writable: true)]
    public bool $onlyFirstTries = false;

    #[LiveProp(writable: true)]
    public bool $onlyUnboxed = false;

    #[LiveProp(writable: true)]
    public string $sortBy = 'fastest';

    #[LiveProp(writable: true)]
    public null|string $manufacturer = null;

    // A PiecesRange param (chip or custom from-to), the single source of truth for the two bounds below
    #[LiveProp(writable: true)]
    public null|string $piecesCountRange = null;

    #[LiveProp(writable: true, onUpdated: 'onPiecesBoundsUpdated')]
    public null|int $piecesMin = null;

    #[LiveProp(writable: true, onUpdated: 'onPiecesBoundsUpdated')]
    public null|int $piecesMax = null;

    // Id of one pair/team: only what the player solved with exactly these people
    #[LiveProp(writable: true)]
    public null|string $team = null;

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

    /**
     * Difficulty tiers (members) as the checkboxes send them; "0" = not rated yet, like on the puzzle search
     *
     * @var list<string>
     */
    #[LiveProp(writable: true)]
    public array $difficulty = [];

    /** @var array<array<SolvedPuzzle>> */
    public array $teamSolvedPuzzles = [];

    /** @var array<array<SolvedPuzzle>> */
    public array $duoSolvedPuzzles = [];

    /** @var array<array<SolvedPuzzle>> */
    public array $soloSolvedPuzzles = [];

    // Distinct puzzles of the pair/team tabs - their rows are per puzzle AND pair/team, the tab counts puzzles
    public int $duoPuzzlesCount = 0;

    public int $teamPuzzlesCount = 0;

    /** @var array<PlayerRanking> */
    public array $ranking = [];

    // The viewer is a member: the tiers are loaded and shown on the thumbnails, the difficulty filter applies
    public bool $withDifficulty = false;

    /**
     * Tier of every rated puzzle in the player's results (members only); a puzzle missing here is not rated yet
     *
     * @var array<string, DifficultyTier>
     */
    public array $difficultyTiers = [];

    /**
     * Difficulty score of every rated puzzle in the player's results (members only), for the difficulty sorts
     *
     * @var array<string, float>
     */
    private array $difficultyScores = [];

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
        readonly private ResolveDifficultyTiers $resolveDifficultyTiers,
    ) {
    }

    private function hasMembership(): bool
    {
        $player = $this->retrieveLoggedUserProfile->getProfile();

        return $player !== null && $player->activeMembership;
    }

    public function onPiecesBoundsUpdated(): void
    {
        $this->piecesCountRange = PiecesRange::fromBounds($this->piecesMin, $this->piecesMax)?->toParam();
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
        if (in_array($sort, self::SORTS, true) || (in_array($sort, self::DIFFICULTY_SORTS, true) && $this->hasMembership())) {
            $this->sortBy = $sort;
        }
    }

    #[LiveAction]
    public function resetFilters(): void
    {
        $this->manufacturer = null;
        $this->piecesCountRange = null;
        $this->piecesMin = null;
        $this->piecesMax = null;
        $this->searchQuery = null;
        $this->onlyRelax = false;
        $this->onlyFirstTries = false;
        $this->onlyUnboxed = false;
        $this->dateFrom = null;
        $this->dateTo = null;
        $this->speedValue = null;
        $this->speedComparison = 'faster';
        $this->speedUnit = 'ppm';
        $this->difficulty = [];
    }

    #[PostMount]
    #[PreReRender]
    public function populate(): void
    {
        assert($this->playerId !== null);

        if (in_array($this->category, ['solo', 'duo', 'group'], true) === false) {
            $this->category = 'solo';
        }

        // Comes from the URL (?team=) as well as from the filter select
        if ($this->team !== null && Uuid::isValid($this->team) === false) {
            $this->team = null;
        }

        $piecesRange = PiecesRange::parse($this->piecesCountRange);
        $this->piecesCountRange = $piecesRange?->toParam();
        $this->piecesMin = $piecesRange?->minPieces;
        $this->piecesMax = $piecesRange?->maxPieces;

        if ($this->category !== 'solo') {
            $this->onlyFirstTries = false;
            $this->onlyUnboxed = false;
        }

        $this->difficulty = DifficultyFilter::normalize($this->difficulty);

        $this->ranking = $this->getRanking->allForPlayer($this->playerId);

        // Fetch all puzzles (unfiltered)
        $this->allSoloPuzzles = $this->getPlayerSolvedPuzzles->soloByPlayerId($this->playerId);
        $this->allDuoPuzzles = $this->getPlayerSolvedPuzzles->duoByPlayerId($this->playerId);
        $this->allTeamPuzzles = $this->getPlayerSolvedPuzzles->teamByPlayerId($this->playerId);

        // Difficulty is members-only: for everyone else it is not even queried. The whole history, not just the
        // shown rows - the filter needs every tier
        $difficultyRatings = $this->resolveDifficultyTiers->ratingsForViewer(
            $this->retrieveLoggedUserProfile->getProfile(),
            array_map(
                static fn(SolvedPuzzle $puzzle): string => $puzzle->puzzleId,
                [...$this->allSoloPuzzles, ...$this->allDuoPuzzles, ...$this->allTeamPuzzles],
            ),
        );
        $this->withDifficulty = $difficultyRatings !== null;
        $this->difficultyTiers = array_map(static fn(PuzzleDifficultyRating $rating): DifficultyTier => $rating->tier, $difficultyRatings ?? []);
        $this->difficultyScores = array_map(static fn(PuzzleDifficultyRating $rating): float => $rating->score, $difficultyRatings ?? []);

        // sortBy is writable: a difficulty sort without membership (sent, or left over from a lapsed one) falls back
        if ($this->withDifficulty === false && in_array($this->sortBy, self::DIFFICULTY_SORTS, true)) {
            $this->sortBy = 'fastest';
        }

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

        // Only apply unboxed filter if user has membership (members exclusive filter)
        if ($this->onlyUnboxed === true && $this->hasMembership()) {
            $soloSolvedPuzzlesGrouped = $this->puzzlesSorter->filterOutNonUnboxedGrouped($soloSolvedPuzzlesGrouped);
        }

        // Apply sorting
        $soloSolvedPuzzlesGrouped = $this->applySortingGrouped($soloSolvedPuzzlesGrouped);
        $duoSolvedPuzzles = $this->applySorting($duoSolvedPuzzles);
        $teamSolvedPuzzles = $this->applySorting($teamSolvedPuzzles);

        $this->soloSolvedPuzzles = $soloSolvedPuzzlesGrouped;
        // One row per puzzle and pair/team: the row opens the result detail, which shows exactly those people
        $this->duoSolvedPuzzles = $this->puzzlesSorter->groupPuzzlesByTeam($duoSolvedPuzzles);
        $this->teamSolvedPuzzles = $this->puzzlesSorter->groupPuzzlesByTeam($teamSolvedPuzzles);
        $this->duoPuzzlesCount = count(array_unique(array_map(static fn(SolvedPuzzle $puzzle): string => $puzzle->puzzleId, $duoSolvedPuzzles)));
        $this->teamPuzzlesCount = count(array_unique(array_map(static fn(SolvedPuzzle $puzzle): string => $puzzle->puzzleId, $teamSolvedPuzzles)));
    }

    /**
     * @param array<SolvedPuzzle> $puzzles
     * @return array<SolvedPuzzle>
     */
    private function applyFilters(array $puzzles): array
    {
        $isMember = $this->hasMembership();
        $piecesRange = PiecesRange::parse($this->piecesCountRange);

        return array_filter($puzzles, function (SolvedPuzzle $puzzle) use ($isMember, $piecesRange): bool {
            // FREE FILTERS - available to everyone

            // Manufacturer filter
            if ($this->manufacturer !== null && $this->manufacturer !== '' && $puzzle->manufacturerName !== $this->manufacturer) {
                return false;
            }

            // Pair/team filter - a solo result belongs to none
            if ($this->team !== null && $this->team !== '' && $puzzle->teamId !== $this->team) {
                return false;
            }

            // Pieces count range filter
            if ($piecesRange !== null && $piecesRange->contains($puzzle->piecesCount) === false) {
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
                if ($this->matchesDateRange($puzzle->finishedAt ?? $puzzle->trackedAt) === false) {
                    return false;
                }

                // Speed filter (time in minutes or PPM)
                if ($this->matchesSpeedFilter($puzzle->time, $puzzle->piecesCount) === false) {
                    return false;
                }

                // Difficulty filter
                if ($this->difficulty !== [] && in_array((string) $this->difficultyOf($puzzle), $this->difficulty, true) === false) {
                    return false;
                }
            }

            return true;
        });
    }

    /**
     * The tier value, PuzzleSearchCriteria::UNRATED_DIFFICULTY for a puzzle without one yet
     */
    private function difficultyOf(SolvedPuzzle $puzzle): int
    {
        return $this->difficultyTiers[$puzzle->puzzleId]->value ?? PuzzleSearchCriteria::UNRATED_DIFFICULTY;
    }

    private function matchesSearch(SolvedPuzzle $puzzle): bool
    {
        if ($this->searchQuery === null || $this->searchQuery === '') {
            return true;
        }

        $normalizedQuery = $this->normalizeString($this->searchQuery);
        $searchFields = [
            $puzzle->puzzleName,
            $puzzle->puzzleAlternativeNames->legacyAlternativeName(),
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
            'easiest' => $this->puzzlesSorter->sortByDifficulty($puzzles, $this->difficultyScores, hardestFirst: false),
            'hardest' => $this->puzzlesSorter->sortByDifficulty($puzzles, $this->difficultyScores, hardestFirst: true),
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
            'fastest' => $this->puzzlesSorter->sortGroupedByFastest($groupedPuzzles, $this->onlyFirstTries, $this->onlyUnboxed),
            'slowest' => $this->puzzlesSorter->sortGoupedBySlowest($groupedPuzzles, $this->onlyFirstTries, $this->onlyUnboxed),
            'newest' => $this->puzzlesSorter->sortGroupedByNewest($groupedPuzzles, $this->onlyFirstTries, $this->onlyUnboxed),
            'oldest' => $this->puzzlesSorter->sortGroupedByOldest($groupedPuzzles, $this->onlyFirstTries, $this->onlyUnboxed),
            'fastest_ppm' => $this->puzzlesSorter->sortGroupedByFastestPpm($groupedPuzzles, $this->onlyFirstTries, $this->onlyUnboxed),
            'slowest_ppm' => $this->puzzlesSorter->sortGroupedBySlowestPpm($groupedPuzzles, $this->onlyFirstTries, $this->onlyUnboxed),
            'easiest' => $this->puzzlesSorter->sortGroupedByDifficulty($groupedPuzzles, $this->difficultyScores, false, $this->onlyFirstTries, $this->onlyUnboxed),
            'hardest' => $this->puzzlesSorter->sortGroupedByDifficulty($groupedPuzzles, $this->difficultyScores, true, $this->onlyFirstTries, $this->onlyUnboxed),
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
     * The pairs and teams the player has results with, most results first. The template names an
     * unnamed one by its other members, each the way this viewer may see them.
     *
     * @return list<array{teamId: string, name: null|string, isPair: bool, players: array<Puzzler>, count: int}>
     */
    public function getAvailableTeams(): array
    {
        $teams = [];

        foreach (array_merge($this->allDuoPuzzles, $this->allTeamPuzzles) as $puzzle) {
            if ($puzzle->teamId === null) {
                continue;
            }

            $teams[$puzzle->teamId] ??= [
                'teamId' => $puzzle->teamId,
                'name' => $puzzle->teamName,
                'isPair' => count($puzzle->players ?? []) === 2,
                'players' => array_filter(
                    $puzzle->players ?? [],
                    fn(Puzzler $puzzler): bool => $puzzler->playerId !== $this->playerId,
                ),
                'count' => 0,
            ];
            $teams[$puzzle->teamId]['count']++;
        }

        usort($teams, static fn(array $a, array $b): int => $b['count'] <=> $a['count']);

        return array_slice($teams, 0, 30);
    }

    /**
     * Piece-count chips the player has at least one result for - plus the
     * active one, so a selection never hides its own chip.
     *
     * @return list<PiecesRange>
     */
    public function getAvailablePiecePresets(): array
    {
        $allPuzzles = array_merge($this->allSoloPuzzles, $this->allDuoPuzzles, $this->allTeamPuzzles);

        return array_values(array_filter(PiecesRange::presets(), function (PiecesRange $preset) use ($allPuzzles): bool {
            if ($preset->toParam() === $this->piecesCountRange) {
                return true;
            }

            foreach ($allPuzzles as $puzzle) {
                if ($preset->contains($puzzle->piecesCount)) {
                    return true;
                }
            }

            return false;
        }));
    }

    /**
     * Difficulty chips. Members get only the tiers the player has a result in (+ "not rated yet") - plus the
     * selected ones, so a selection never hides its own chip, like the piece-count chips. Everyone else sees
     * all of them, locked.
     *
     * @return list<array{value: string, tier: null|DifficultyTier}>
     */
    public function getDifficultyOptions(): array
    {
        $present = null;

        if ($this->withDifficulty) {
            $present = array_flip($this->difficulty);

            foreach ([...$this->allSoloPuzzles, ...$this->allDuoPuzzles, ...$this->allTeamPuzzles] as $puzzle) {
                $present[(string) $this->difficultyOf($puzzle)] = true;
            }
        }

        return array_values(array_filter(
            DifficultyFilter::options(),
            static fn (array $option): bool => $present === null || isset($present[$option['value']]),
        ));
    }

    public function getActiveFiltersCount(): int
    {
        $count = 0;
        $isMember = $this->hasMembership();

        // FREE FILTERS - count for everyone
        if ($this->manufacturer !== null && $this->manufacturer !== '') {
            $count++;
        }

        if ($this->piecesCountRange !== null) {
            $count++;
        }

        if ($this->team !== null && $this->team !== '') {
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

            if ($this->onlyUnboxed !== false) {
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

            if ($this->difficulty !== []) {
                $count++;
            }
        }

        return $count;
    }
}
