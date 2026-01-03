<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Component;

use SpeedPuzzling\Web\Query\GetManufacturers;
use SpeedPuzzling\Web\Query\GetPuzzleDifficulty;
use SpeedPuzzling\Web\Query\GetRanking;
use SpeedPuzzling\Web\Query\GetSellSwapListItems;
use SpeedPuzzling\Web\Query\GetTags;
use SpeedPuzzling\Web\Query\GetUserPuzzleStatuses;
use SpeedPuzzling\Web\Query\SearchPuzzle;
use SpeedPuzzling\Web\Results\ManufacturerOverview;
use SpeedPuzzling\Web\Results\PiecesFilter;
use SpeedPuzzling\Web\Results\PlayerRanking;
use SpeedPuzzling\Web\Results\PuzzleDifficultyResult;
use SpeedPuzzling\Web\Results\PuzzleOverview;
use SpeedPuzzling\Web\Results\PuzzleTag;
use SpeedPuzzling\Web\Results\UserPuzzleStatuses;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveArg;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\Attribute\PreReRender;
use Symfony\UX\LiveComponent\DefaultActionTrait;
use Symfony\UX\LiveComponent\Metadata\UrlMapping;
use Symfony\UX\TwigComponent\Attribute\PostMount;

#[AsLiveComponent]
final class PuzzleSearch
{
    use DefaultActionTrait;

    private const int LIMIT = 20;

    /** @var list<string> */
    private const array VALID_SORTS = ['most-solved', 'least-solved', 'a-z', 'z-a', 'easiest', 'hardest'];

    #[LiveProp(writable: true, onUpdated: 'onFilterUpdated', url: new UrlMapping(as: 'brand'))]
    public null|string $brandId = null;

    #[LiveProp(writable: true, onUpdated: 'onFilterUpdated', url: new UrlMapping(as: 'search'))]
    public null|string $search = null;

    #[LiveProp(writable: true, onUpdated: 'onFilterUpdated', url: new UrlMapping(as: 'pieces'))]
    public null|string $pieces = null;

    #[LiveProp(writable: true, onUpdated: 'onFilterUpdated', url: new UrlMapping(as: 'tag'))]
    public null|string $tagId = null;

    /** @var list<int> */
    #[LiveProp(writable: true, onUpdated: 'onFilterUpdated', url: new UrlMapping(as: 'difficultyTiers'))]
    public array $difficultyTiers = [];

    #[LiveProp(writable: true, url: new UrlMapping(as: 'sortBy'))]
    public string $sortBy = 'most-solved';

    #[LiveProp]
    public int $displayLimit = 20;

    /** @var list<PuzzleOverview> */
    public array $puzzles = [];

    public int $totalCount = 0;

    private UserPuzzleStatuses $puzzleStatuses;

    /** @var array<string, PlayerRanking> */
    private array $userRanking = [];

    /** @var array<string, array<PuzzleTag>> */
    private array $tags = [];

    /** @var array<ManufacturerOverview> */
    private array $manufacturers = [];

    /** @var array<PuzzleTag> */
    private array $allTags = [];

    /** @var array<string, int> */
    private array $offerCounts = [];

    /** @var array<string, PuzzleDifficultyResult> */
    private array $difficultyData = [];

    public function __construct(
        private readonly SearchPuzzle $searchPuzzle,
        private readonly GetUserPuzzleStatuses $getUserPuzzleStatuses,
        private readonly GetRanking $getRanking,
        private readonly RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        private readonly GetTags $getTags,
        private readonly GetManufacturers $getManufacturers,
        private readonly GetSellSwapListItems $getSellSwapListItems,
        private readonly GetPuzzleDifficulty $getPuzzleDifficulty,
        private readonly RequestStack $requestStack,
        private readonly CacheInterface $cache,
    ) {
        $this->puzzleStatuses = UserPuzzleStatuses::empty();
    }

    /**
     * Live Component hydrates scalar url-mapped props from the query string on the initial
     * (non-AJAX) render, but not array props. Restore deep-link parity for the difficulty
     * filter by reading it from the request here. mount() runs only on the initial render
     * (live re-renders go through hydration instead), so interactively-set tiers are never
     * clobbered. Parsing mirrors the former SearchPuzzleFormData::fromRequest().
     */
    public function mount(): void
    {
        $request = $this->requestStack->getCurrentRequest();

        if ($request === null) {
            return;
        }

        $difficultyTiers = $request->query->all('difficultyTiers');

        $this->difficultyTiers = array_values(array_map(
            static fn (mixed $tier): int => (int) $tier,
            array_filter($difficultyTiers, static fn (mixed $tier): bool => is_numeric($tier)),
        ));
    }

    #[LiveAction]
    public function changeSortBy(#[LiveArg] string $sort): void
    {
        if (in_array($sort, self::VALID_SORTS, true)) {
            $this->sortBy = $sort;
            $this->displayLimit = self::LIMIT;
        }
    }

    #[LiveAction]
    public function loadMore(): void
    {
        $this->displayLimit += self::LIMIT;
    }

    public function onFilterUpdated(): void
    {
        $this->displayLimit = self::LIMIT;
    }

    #[PostMount]
    #[PreReRender]
    public function loadData(): void
    {
        $this->loadPuzzles();
        $this->loadUserData();
        $this->loadFilterOptions();
        $this->loadPuzzleMetadata();
    }

    private function loadPuzzles(): void
    {
        $piecesFilter = PiecesFilter::fromUserInput($this->pieces);
        $difficultyTiers = $this->normalizedDifficultyTiers();

        if ($this->isDefaultSearch()) {
            $cached = $this->getInitialPuzzlesFromCache();
            $this->puzzles = $cached['puzzles'];
            $this->totalCount = $cached['count'];

            return;
        }

        $this->totalCount = $this->searchPuzzle->countByUserInput(
            $this->brandId,
            $this->search,
            $piecesFilter,
            $this->tagId,
            $difficultyTiers,
        );

        $this->puzzles = $this->searchPuzzle->byUserInput(
            $this->brandId,
            $this->search,
            $piecesFilter,
            $this->tagId,
            $this->sortBy,
            offset: 0,
            limit: $this->displayLimit,
            difficultyTiers: $difficultyTiers,
        );
    }

    private function loadUserData(): void
    {
        $playerProfile = $this->retrieveLoggedUserProfile->getProfile();

        $this->puzzleStatuses = $this->getUserPuzzleStatuses->byPlayerId($playerProfile?->playerId);

        if ($playerProfile !== null) {
            $this->userRanking = $this->getRanking->allForPlayer($playerProfile->playerId);
        } else {
            $this->userRanking = [];
        }
    }

    private function loadFilterOptions(): void
    {
        $this->tags = $this->getTags->allGroupedPerPuzzle();
        $this->allTags = $this->getTags->all();
        $this->manufacturers = $this->getManufacturers->onlyApprovedOrAddedByPlayer();
    }

    private function loadPuzzleMetadata(): void
    {
        $puzzleIds = array_map(
            static fn (PuzzleOverview $puzzle): string => $puzzle->puzzleId,
            $this->puzzles,
        );

        $this->offerCounts = $this->getSellSwapListItems->countByPuzzleIds($puzzleIds);
        $this->difficultyData = $this->getPuzzleDifficulty->forPuzzleList($puzzleIds);
    }

    /**
     * @return array<PuzzleOverview>
     */
    public function getPuzzles(): array
    {
        return $this->puzzles;
    }

    public function getTotalCount(): int
    {
        return $this->totalCount;
    }

    public function getRemainingCount(): int
    {
        return max(0, $this->totalCount - $this->displayLimit);
    }

    public function hasMore(): bool
    {
        return $this->displayLimit < $this->totalCount;
    }

    public function getPuzzleStatuses(): UserPuzzleStatuses
    {
        return $this->puzzleStatuses;
    }

    /**
     * @return array<string, PlayerRanking>
     */
    public function getUserRanking(): array
    {
        return $this->userRanking;
    }

    /**
     * @return array<string, array<PuzzleTag>>
     */
    public function getTags(): array
    {
        return $this->tags;
    }

    /**
     * @return array<ManufacturerOverview>
     */
    public function getManufacturers(): array
    {
        return $this->manufacturers;
    }

    /**
     * @return array<PuzzleTag>
     */
    public function getAllTags(): array
    {
        return $this->allTags;
    }

    /**
     * @return array<string, int>
     */
    public function getOfferCounts(): array
    {
        return $this->offerCounts;
    }

    /**
     * @return array<string, PuzzleDifficultyResult>
     */
    public function getDifficultyData(): array
    {
        return $this->difficultyData;
    }

    public function isUsingFilters(): bool
    {
        return $this->brandId !== null
            || ($this->search !== null && $this->search !== '')
            || $this->pieces !== null
            || $this->tagId !== null
            || $this->difficultyTiers !== [];
    }

    /**
     * @return list<int>
     */
    private function normalizedDifficultyTiers(): array
    {
        return array_map(
            static fn (int|string $tier): int => (int) $tier,
            $this->difficultyTiers,
        );
    }

    private function isDefaultSearch(): bool
    {
        return $this->brandId === null
            && ($this->search === null || $this->search === '')
            && $this->pieces === null
            && $this->tagId === null
            && $this->difficultyTiers === []
            && $this->sortBy === 'most-solved'
            && $this->displayLimit === self::LIMIT;
    }

    /**
     * @return array{puzzles: list<PuzzleOverview>, count: int}
     */
    private function getInitialPuzzlesFromCache(): array
    {
        return $this->cache->get('initial_puzzles_v1', function (ItemInterface $item): array {
            $item->expiresAfter(3600);
            $pieces = PiecesFilter::fromUserInput(null);

            return [
                'puzzles' => $this->searchPuzzle->byUserInput(null, null, $pieces, null, 'most-solved', 0),
                'count' => $this->searchPuzzle->countByUserInput(null, null, $pieces, null),
            ];
        });
    }
}
