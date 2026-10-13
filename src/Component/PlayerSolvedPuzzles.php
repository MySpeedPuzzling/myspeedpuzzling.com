<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Component;

use DateTimeImmutable;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Results\PlayerRanking;
use SpeedPuzzling\Web\Results\PlayerResultsPage;
use SpeedPuzzling\Web\Results\SolvedPuzzle;
use SpeedPuzzling\Web\Services\PlayerResults\PlayerResultsBuilder;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use SpeedPuzzling\Web\Value\DifficultyFilter;
use SpeedPuzzling\Web\Value\DifficultyTier;
use SpeedPuzzling\Web\Value\PiecesRange;
use SpeedPuzzling\Web\Value\PlayerResultsCriteria;
use SpeedPuzzling\Web\Value\Puzzler;
use SpeedPuzzling\Web\Value\SearchText;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveArg;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\Attribute\PreReRender;
use Symfony\UX\LiveComponent\DefaultActionTrait;
use Symfony\UX\TwigComponent\Attribute\PostMount;

/**
 * The profile's results list (Live). Since the Results page (docs/features/navigation PROF-05a) it is a thin wrapper
 * over PlayerResultsBuilder in its legacy mode - the profile's own semantics, unchanged - kept for one release after the
 * profile stops rendering it (PROF-03), so tabs still open re-render (implementation plan decision 97). docs/TODO.md
 * removes it with its template and _player_solvings.html.twig.
 */
#[AsLiveComponent]
final class PlayerSolvedPuzzles
{
    use DefaultActionTrait;

    private const array SORTS = ['fastest', 'slowest', 'newest', 'oldest', 'fastest_ppm', 'slowest_ppm'];

    // Members only, like the difficulty filter
    private const array DIFFICULTY_SORTS = ['easiest', 'hardest'];

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

    /** @var array<array-key, non-empty-list<SolvedPuzzle>> */
    public array $teamSolvedPuzzles = [];

    /** @var array<array-key, non-empty-list<SolvedPuzzle>> */
    public array $duoSolvedPuzzles = [];

    /** @var array<array-key, non-empty-list<SolvedPuzzle>> */
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
     * Tier of every rated puzzle in the player's results (members only); a puzzle missing here is not rated yet
     *
     * @var array<string, DifficultyTier>
     */
    public array $difficultyTiers = [];

    private null|PlayerResultsPage $page = null;

    public function __construct(
        readonly private PlayerResultsBuilder $playerResultsBuilder,
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
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
        if (in_array($sort, self::SORTS, true) || (in_array($sort, self::DIFFICULTY_SORTS, true) && $this->hasMembership())) {
            $this->sortBy = $sort;
        }
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
    }

    /**
     * The props normalized as the profile always did, then the rows from PlayerResultsBuilder in its legacy mode - the
     * leading attempt of a row follows the sort, default sort Fastest, the search folded in PHP
     */
    #[PostMount]
    #[PreReRender]
    public function populate(): void
    {
        $playerId = $this->playerId;
        assert($playerId !== null);

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

        $isMember = $this->hasMembership();

        // Members-only filters of every tab (solo, pairs, teams) - their switches are disabled for everybody else
        if ($isMember === false) {
            $this->onlyFirstTries = false;
            $this->onlyUnboxed = false;
        }

        $this->difficulty = DifficultyFilter::normalize($this->difficulty);

        // sortBy is writable: an unknown sort, or a difficulty sort without membership (sent, or left over from a
        // lapsed one), falls back
        if (
            in_array($this->sortBy, [...self::SORTS, ...self::DIFFICULTY_SORTS], true) === false
            || ($isMember === false && in_array($this->sortBy, self::DIFFICULTY_SORTS, true))
        ) {
            $this->sortBy = 'fastest';
        }

        $criteria = PlayerResultsCriteria::fromUserInput(
            isMember: $isMember,
            category: $this->category,
            sort: $this->sortBy,
            query: $this->searchQuery,
            brand: $this->manufacturer,
            pieces: $this->piecesCountRange,
            team: $this->team,
            onlyFirstTries: $this->onlyFirstTries,
            onlyUnboxed: $this->onlyUnboxed,
            onlyRelax: $this->onlyRelax,
            from: self::isoDate($this->dateFrom),
            to: self::isoDate($this->dateTo),
            speed: $this->speedValue !== null ? (string) $this->speedValue : null,
            speedComparison: $this->speedComparison,
            speedUnit: $this->speedUnit,
            difficultyTiers: $this->difficulty,
        );

        // The list key first: another list starts from its top again, so the ranks below are for the right rows
        $this->keepTheShownRowsOfTheSameList();

        $this->page = $this->playerResultsBuilder->build($playerId, $criteria, legacy: true, shownRows: $this->limit);

        $this->soloSolvedPuzzles = $this->page->soloRows;
        $this->duoSolvedPuzzles = $this->page->duoRows;
        $this->teamSolvedPuzzles = $this->page->teamRows;
        $this->duoPuzzlesCount = $this->page->countOf('duo');
        $this->teamPuzzlesCount = $this->page->countOf('group');
        $this->ranking = $this->page->ranking;
        $this->withDifficulty = $this->page->withDifficulty;
        $this->difficultyTiers = $this->page->difficultyTiers;
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
        ]);

        if ($this->pagedList !== '' && $this->pagedList !== $listKey) {
            $this->limit = self::PAGE_SIZE;
        }

        $this->pagedList = $listKey;
    }

    /**
     * The date fields' d.m.Y as PlayerResultsCriteria reads dates (Y-m-d); anything else is no date
     */
    private static function isoDate(null|string $date): null|string
    {
        if ($date === null || $date === '') {
            return null;
        }

        $parsed = DateTimeImmutable::createFromFormat('!d.m.Y', $date);

        return $parsed !== false ? $parsed->format('Y-m-d') : null;
    }

    private function results(): PlayerResultsPage
    {
        if ($this->page === null) {
            $this->populate();
        }

        assert($this->page !== null);

        return $this->page;
    }

    /**
     * The rows rendered: the first $limit rows of the shown tab
     *
     * @return array<array-key, non-empty-list<SolvedPuzzle>>
     */
    public function getShownRows(): array
    {
        return array_slice($this->results()->rowsOf($this->category), 0, $this->limit, preserve_keys: true);
    }

    public function getRowsCount(): int
    {
        return count($this->results()->rowsOf($this->category));
    }

    public function getShowMoreCount(): int
    {
        return min(self::PAGE_SIZE, max(0, $this->getRowsCount() - $this->limit));
    }

    /**
     * @return array<string, int>
     */
    public function getAvailableManufacturers(): array
    {
        return $this->results()->availableBrands;
    }

    /**
     * The pairs and teams the player has results with, most results first. The template names an
     * unnamed one by its other members, each the way this viewer may see them.
     *
     * @return list<array{teamId: string, name: null|string, isPair: bool, players: array<Puzzler>, count: int}>
     */
    public function getAvailableTeams(): array
    {
        return $this->results()->availableTeams;
    }

    /**
     * Piece-count chips the player has at least one result for - plus the
     * active one, so a selection never hides its own chip.
     *
     * @return list<PiecesRange>
     */
    public function getAvailablePiecePresets(): array
    {
        return $this->results()->availablePiecePresets;
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
        return $this->results()->difficultyOptions;
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
