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
use SpeedPuzzling\Web\Value\PlacingFilter;
use SpeedPuzzling\Web\Value\PlacingSummary;
use SpeedPuzzling\Web\Value\PuzzleSearchCriteria;
use SpeedPuzzling\Web\Value\PuzzleSearchKeys;
use SpeedPuzzling\Web\Value\Puzzler;
use SpeedPuzzling\Web\Value\SearchText;
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

    // The player's rank on each puzzle's leaderboard - solo results only, free like the rank chips (feature request #292)
    public const array PLACING_SORTS = ['best_rank', 'worst_rank', 'best_share', 'worst_share'];

    // Members only, like the difficulty filter
    private const array DIFFICULTY_SORTS = ['easiest', 'hardest'];

    // Members only (Insights), and only while the player shows predictions: the latest attempt against its prediction
    public const array PREDICTION_SORTS = ['ahead_of_prediction', 'behind_prediction'];

    /**
     * Rows rendered at first and added by every "Show more". Filters and sorts still run over the whole history in PHP;
     * only the rows on screen are rendered - the heaviest profile (2,159 puzzles) rendered 6 MB of HTML in ~1 s
     * (docs/features/navigation PROF-05b settles on 50 for the coming Results page too)
     */
    public const int PAGE_SIZE = 50;

    #[LiveProp]
    public null|string $playerId = null;

    /**
     * The rows shown of the tab's list. Not writable: changed only by showMore(), and back to the start whenever the
     * list changes ($pagedList)
     */
    #[LiveProp]
    public int $limit = self::PAGE_SIZE;

    /**
     * The list the shown rows belong to - the tab, every filter and the sort (keepTheShownRowsOfTheSameList()). Another
     * list starts from its top again, the same list keeps its rows, whatever a request carries.
     *
     * Never reset the limit in a filter's onUpdated hook: after every render Live reads each <select data-model> back and
     * re-sends whatever differs from the prop - the brand select says "" for "All brands" while the prop is null - so such
     * a hook runs on every request and would undo each "Show more" (PuzzleTimes::$pagedList, the same bug Comparison had)
     */
    #[LiveProp]
    public string $pagedList = '';

    #[LiveProp(writable: true)]
    public string $category = 'solo';

    #[LiveProp(writable: true)]
    public bool $onlyFirstTries = false;

    #[LiveProp(writable: true)]
    public bool $onlyUnboxed = false;

    #[LiveProp(writable: true)]
    public string $sortBy = 'fastest';

    // The profile's player turned time predictions off (docs/features/opt-out.md): no prediction sorts on their profile
    #[LiveProp]
    public bool $predictionsOptedOut = false;

    // A PlacingFilter value - solo results only
    #[LiveProp(writable: true)]
    public null|string $placing = null;

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

    /**
     * The player's rank on the puzzle of every shown solo row, by puzzle id (GetRanking::forPuzzles())
     *
     * @var array<string, PlayerRanking>
     */
    public array $ranking = [];

    // The viewer is a member: the tiers are loaded and shown on the thumbnails, the difficulty filter applies
    public bool $withDifficulty = false;

    /**
     * Every rank of the player, by puzzle id - loaded only for a placing sort or the placing filter on the solo tab,
     * null otherwise (the shown rows' ranks are then all $ranking has)
     *
     * @var null|array<string, PlayerRanking>
     */
    private null|array $allRanks = null;

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

    // Buttons, not links: the profile's further rows are no pages of their own
    #[LiveAction]
    public function showMore(): void
    {
        $this->limit += self::PAGE_SIZE;
    }

    #[LiveAction]
    public function changeSortBy(#[LiveArg] string $sort): void
    {
        if (
            in_array($sort, self::SORTS, true)
            || in_array($sort, self::PLACING_SORTS, true)
            || (in_array($sort, self::DIFFICULTY_SORTS, true) && $this->hasMembership())
            || (in_array($sort, self::PREDICTION_SORTS, true) && $this->canSortByPrediction())
        ) {
            $this->sortBy = $sort;
        }
    }

    public function canSortByPrediction(): bool
    {
        return $this->predictionsOptedOut === false && $this->hasMembership();
    }

    #[LiveAction]
    public function resetFilters(): void
    {
        $this->manufacturer = null;
        $this->team = null;
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
        $this->placing = null;
    }

    #[PostMount]
    #[PreReRender]
    public function populate(): void
    {
        $playerId = $this->playerId;
        assert($playerId !== null);
        $this->allRanks = null;

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

        // Members-only filters of every tab (solo, pairs, teams) - their switches are disabled for everybody else
        if ($this->hasMembership() === false) {
            $this->onlyFirstTries = false;
            $this->onlyUnboxed = false;
        }

        $this->difficulty = DifficultyFilter::normalize($this->difficulty);
        $placing = PlacingFilter::tryFrom($this->placing ?? '');
        $this->placing = $placing?->value;

        // Fetch all puzzles (unfiltered)
        $this->allSoloPuzzles = $this->getPlayerSolvedPuzzles->soloByPlayerId($playerId);
        $this->allDuoPuzzles = $this->getPlayerSolvedPuzzles->duoByPlayerId($playerId);
        $this->allTeamPuzzles = $this->getPlayerSolvedPuzzles->teamByPlayerId($playerId);

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

        // The same for a prediction sort - and every sort nobody offers, sortBy being writable
        $knownSort = in_array($this->sortBy, [...self::SORTS, ...self::PLACING_SORTS, ...self::DIFFICULTY_SORTS], true)
            || (in_array($this->sortBy, self::PREDICTION_SORTS, true) && $this->canSortByPrediction());

        if ($knownSort === false) {
            $this->sortBy = 'fastest';
        }

        // Apply filters - the pair/team filter narrows only the pair and team lists, solo results belong to none
        $soloSolvedPuzzles = $this->applyFilters($this->allSoloPuzzles, withTeamFilter: false);
        $duoSolvedPuzzles = $this->applyFilters($this->allDuoPuzzles, withTeamFilter: true);
        $teamSolvedPuzzles = $this->applyFilters($this->allTeamPuzzles, withTeamFilter: true);

        // Group solo puzzles; first tries / unboxed keep the puzzles with such an attempt, and it leads the row
        $soloSolvedPuzzlesGrouped = $this->puzzlesSorter->groupPuzzles($soloSolvedPuzzles, withReordering: false);
        $soloSolvedPuzzlesGrouped = $this->puzzlesSorter->filterGroupedByAttempt($soloSolvedPuzzlesGrouped, $this->onlyFirstTries, $this->onlyUnboxed);

        // Every rank only when the placings decide the list: the placing filter (it narrows the solo list, whose count is on
        // its tab whichever tab is open) or a placing sort while the solo tab is shown - pairs and teams have no ranks.
        // One statement, kept for two minutes: each chip and "Show more" re-renders the whole list
        if ($placing !== null || ($this->category === 'solo' && in_array($this->sortBy, self::PLACING_SORTS, true))) {
            $this->allRanks = $this->getRanking->allForPlayerCached($playerId, self::resultsFingerprint($this->allSoloPuzzles), firstTries: $this->onlyFirstTries);
        }

        if ($placing !== null && $this->allRanks !== null) {
            $allRanks = $this->allRanks;
            $soloSolvedPuzzlesGrouped = array_filter(
                $soloSolvedPuzzlesGrouped,
                static fn (array $group): bool => $placing->matches($allRanks[$group[array_key_first($group)]->puzzleId] ?? null),
            );
        }

        // Apply sorting
        $soloSolvedPuzzlesGrouped = $this->applySortingGrouped($soloSolvedPuzzlesGrouped);
        $duoSolvedPuzzles = $this->applySorting($duoSolvedPuzzles);
        $teamSolvedPuzzles = $this->applySorting($teamSolvedPuzzles);

        $this->soloSolvedPuzzles = $soloSolvedPuzzlesGrouped;
        // One row per puzzle and pair/team: the row opens the result detail, which shows exactly those people. A pair's
        // first try is everybody's (docs/features/first-try-integrity.md), so its own flag is all the filter reads
        $this->duoSolvedPuzzles = $this->puzzlesSorter->groupPuzzlesByTeam($duoSolvedPuzzles, $this->onlyFirstTries, $this->onlyUnboxed);
        $this->teamSolvedPuzzles = $this->puzzlesSorter->groupPuzzlesByTeam($teamSolvedPuzzles, $this->onlyFirstTries, $this->onlyUnboxed);
        $this->duoPuzzlesCount = self::distinctPuzzlesCount($this->duoSolvedPuzzles);
        $this->teamPuzzlesCount = self::distinctPuzzlesCount($this->teamSolvedPuzzles);

        $this->keepTheShownRowsOfTheSameList();

        // Ranks only for the rows on screen - a solo row shows its rank, pair/team rows none (_player_solvings)
        $shownPuzzleIds = [];

        if ($this->category === 'solo') {
            foreach ($this->getShownRows() as $group) {
                foreach ($group as $solvedPuzzle) {
                    $shownPuzzleIds[] = $solvedPuzzle->puzzleId;
                    break;
                }
            }
        }

        // Under "first tries only" the rows show first tries, so they rank on the first tries board
        $this->ranking = $this->getRanking->forPuzzles($playerId, $shownPuzzleIds, firstTries: $this->onlyFirstTries);
    }

    /**
     * Runs after the action of the request (populate() is the PreReRender hook), so a tab switched by
     * changeResultsCategory() or a sort picked by changeSortBy() counts as another list as well. Every value is taken
     * the way it filters - "" and null alike, members-only filters only for members - so a select Live re-sends with
     * the same meaning never counts as another list.
     */
    private function keepTheShownRowsOfTheSameList(): void
    {
        $isMember = $this->hasMembership();
        $difficulty = $this->difficulty;
        sort($difficulty);

        $listKey = implode('|', [
            $this->category,
            $this->sortBy,
            $this->manufacturer ?? '',
            $this->team ?? '',
            $this->piecesCountRange ?? '',
            SearchText::fold($this->searchQuery ?? ''),
            $this->onlyFirstTries ? 'first-tries' : '',
            $this->onlyUnboxed ? 'unboxed' : '',
            $isMember && $this->onlyRelax ? 'relax' : '',
            $isMember ? ($this->dateFrom ?? '') : '',
            $isMember ? ($this->dateTo ?? '') : '',
            $isMember && $this->speedValue !== null ? $this->speedComparison . $this->speedValue . $this->speedUnit : '',
            $isMember ? implode(',', $difficulty) : '',
            $this->placing ?? '',
        ]);

        if ($this->pagedList !== '' && $this->pagedList !== $listKey) {
            $this->limit = self::PAGE_SIZE;
        }

        $this->pagedList = $listKey;
    }

    /**
     * The rows of the shown tab, after the filters and the sort
     *
     * @return array<array<SolvedPuzzle>>
     */
    private function tabRows(): array
    {
        return match ($this->category) {
            'duo' => $this->duoSolvedPuzzles,
            'group' => $this->teamSolvedPuzzles,
            default => $this->soloSolvedPuzzles,
        };
    }

    /**
     * "Median placing: top 23 % across 412 puzzles" over the whole filtered list - while every rank is loaded
     */
    public function getPlacingSummary(): null|PlacingSummary
    {
        if ($this->allRanks === null || $this->category !== 'solo') {
            return null;
        }

        $rankings = [];

        foreach ($this->soloSolvedPuzzles as $group) {
            $first = reset($group);
            $ranking = $first !== false ? ($this->allRanks[$first->puzzleId] ?? null) : null;

            if ($ranking !== null) {
                $rankings[] = $ranking;
            }
        }

        return PlacingSummary::of($rankings);
    }

    public function isPredictionSort(): bool
    {
        return in_array($this->sortBy, self::PREDICTION_SORTS, true);
    }

    /**
     * How the puzzle's latest attempt went against its prediction, in whole percent: -12 = 12 % faster than predicted;
     * null when it was not predicted
     *
     * @param array<SolvedPuzzle> $group
     */
    public function getLatestAttemptPredictionPercent(array $group): null|int
    {
        $ratio = PuzzlesSorter::predictionRatio(PuzzlesSorter::latestAttempt($group));

        return $ratio === null ? null : (int) round(($ratio - 1) * 100);
    }

    /**
     * Changes whenever one of the player's own solo results is added, removed or changed in a way that moves a rank
     *
     * @param array<SolvedPuzzle> $soloPuzzles
     */
    private static function resultsFingerprint(array $soloPuzzles): string
    {
        $lines = array_map(
            static fn (SolvedPuzzle $puzzle): string => $puzzle->timeId . ':' . $puzzle->puzzleId . ':' . ($puzzle->time ?? '') . ':' . ($puzzle->suspicious ? 1 : 0) . ':' . ($puzzle->firstAttempt ? 1 : 0),
            $soloPuzzles,
        );
        sort($lines);

        return sha1(implode("\n", $lines));
    }

    /**
     * The rows rendered: the first $limit rows of the shown tab
     *
     * @return array<array<SolvedPuzzle>>
     */
    public function getShownRows(): array
    {
        return array_slice($this->tabRows(), 0, $this->limit, preserve_keys: true);
    }

    public function getRowsCount(): int
    {
        return count($this->tabRows());
    }

    public function getShowMoreCount(): int
    {
        return min(self::PAGE_SIZE, max(0, $this->getRowsCount() - $this->limit));
    }

    /**
     * @param array<string, non-empty-array<SolvedPuzzle>> $groupedPuzzles
     */
    private static function distinctPuzzlesCount(array $groupedPuzzles): int
    {
        return count(array_unique(array_map(static fn(array $group): string => $group[array_key_first($group)]->puzzleId, $groupedPuzzles)));
    }

    /**
     * @param array<SolvedPuzzle> $puzzles
     * @return array<SolvedPuzzle>
     */
    private function applyFilters(array $puzzles, bool $withTeamFilter): array
    {
        $isMember = $this->hasMembership();
        $piecesRange = PiecesRange::parse($this->piecesCountRange);
        $search = SearchText::fold($this->searchQuery ?? '');
        // A puzzle comes back once per result - its names are folded once
        $searchableTexts = [];

        return array_filter($puzzles, function (SolvedPuzzle $puzzle) use ($isMember, $piecesRange, $search, $withTeamFilter, &$searchableTexts): bool {
            // FREE FILTERS - available to everyone

            // Manufacturer filter
            if ($this->manufacturer !== null && $this->manufacturer !== '' && $puzzle->manufacturerName !== $this->manufacturer) {
                return false;
            }

            // Pair/team filter
            if ($withTeamFilter && $this->team !== null && $this->team !== '' && $puzzle->teamId !== $this->team) {
                return false;
            }

            // Pieces count range filter
            if ($piecesRange !== null && $piecesRange->contains($puzzle->piecesCount) === false) {
                return false;
            }

            // Search: every name of the puzzle and its brand code, folded like what was typed
            if ($search !== '' && str_contains($searchableTexts[$puzzle->puzzleId] ??= self::searchableText($puzzle), $search) === false) {
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

    /**
     * One line per name (main title first) and the brand code as printed, each folded (SearchText) - a typed text
     * never matches across two of them.
     */
    private static function searchableText(SolvedPuzzle $puzzle): string
    {
        return PuzzleSearchKeys::names($puzzle->puzzleName, $puzzle->puzzleAlternativeNames)
            . SearchText::fold($puzzle->puzzleIdentificationNumber ?? '') . "\n";
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
            // Pairs and teams have no ranks nor predictions: their placing and prediction sorts are the fastest first
            default => $this->puzzlesSorter->sortByFastest($puzzles),
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
            'best_rank' => $this->puzzlesSorter->sortGroupedByPlacing($groupedPuzzles, $this->allRanks ?? [], byShare: false, worstFirst: false, onlyFirstTries: $this->onlyFirstTries, onlyUnboxed: $this->onlyUnboxed),
            'worst_rank' => $this->puzzlesSorter->sortGroupedByPlacing($groupedPuzzles, $this->allRanks ?? [], byShare: false, worstFirst: true, onlyFirstTries: $this->onlyFirstTries, onlyUnboxed: $this->onlyUnboxed),
            'best_share' => $this->puzzlesSorter->sortGroupedByPlacing($groupedPuzzles, $this->allRanks ?? [], byShare: true, worstFirst: false, onlyFirstTries: $this->onlyFirstTries, onlyUnboxed: $this->onlyUnboxed),
            'worst_share' => $this->puzzlesSorter->sortGroupedByPlacing($groupedPuzzles, $this->allRanks ?? [], byShare: true, worstFirst: true, onlyFirstTries: $this->onlyFirstTries, onlyUnboxed: $this->onlyUnboxed),
            'ahead_of_prediction' => $this->puzzlesSorter->sortGroupedByPrediction($groupedPuzzles, aheadFirst: true, onlyFirstTries: $this->onlyFirstTries, onlyUnboxed: $this->onlyUnboxed),
            'behind_prediction' => $this->puzzlesSorter->sortGroupedByPrediction($groupedPuzzles, aheadFirst: false, onlyFirstTries: $this->onlyFirstTries, onlyUnboxed: $this->onlyUnboxed),
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

        if ($this->placing !== null) {
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
