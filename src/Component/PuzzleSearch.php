<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Component;

use SpeedPuzzling\Web\Query\GetPuzzleDifficulty;
use SpeedPuzzling\Web\Query\GetRanking;
use SpeedPuzzling\Web\Query\GetSellSwapListItems;
use SpeedPuzzling\Web\Query\GetTags;
use SpeedPuzzling\Web\Query\GetUserPuzzleStatuses;
use SpeedPuzzling\Web\Query\SearchPuzzle;
use SpeedPuzzling\Web\Results\PiecesFilter;
use SpeedPuzzling\Web\Results\PlayerRanking;
use SpeedPuzzling\Web\Results\PuzzleDifficultyResult;
use SpeedPuzzling\Web\Results\PuzzleOverview;
use SpeedPuzzling\Web\Results\PuzzleTag;
use SpeedPuzzling\Web\Results\UserPuzzleStatuses;
use SpeedPuzzling\Web\Services\PuzzleFilterOptions;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use SpeedPuzzling\Web\Value\DifficultyTier;
use SpeedPuzzling\Web\Value\PuzzleSearchCriteria;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveArg;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\Attribute\PreReRender;
use Symfony\UX\LiveComponent\DefaultActionTrait;
use Symfony\UX\LiveComponent\Metadata\UrlMapping;

/**
 * The component always renders the first page; the "load more" button appends
 * further pages client-side via the puzzle_search_items endpoint (constant
 * per-click cost). Any filter or sort change re-renders page one, which
 * correctly discards the appended items.
 */
#[AsLiveComponent]
final class PuzzleSearch
{
    use DefaultActionTrait;

    #[LiveProp(writable: true, url: new UrlMapping(as: 'brand'))]
    public null|string $brandId = null;

    #[LiveProp(writable: true, url: true)]
    public null|string $search = null;

    #[LiveProp(writable: true, url: true)]
    public null|string $pieces = null;

    #[LiveProp(writable: true, url: new UrlMapping(as: 'tag'))]
    public null|string $tagId = null;

    /**
     * Declared list<string> on purpose: both URL query params and checkbox values
     * arrive as strings, and the framework's url-prop hydration type-checks array
     * elements. Values are normalized to ints inside PuzzleSearchCriteria.
     *
     * @var list<string>
     */
    #[LiveProp(writable: true, url: true)]
    public array $difficultyTiers = [];

    #[LiveProp(writable: true, url: true)]
    public string $sortBy = 'most-solved';

    /** @var list<PuzzleOverview> */
    public array $puzzles = [];

    public int $totalCount = 0;

    private PuzzleSearchCriteria $criteria;

    private UserPuzzleStatuses $puzzleStatuses;

    /** @var array<string, PlayerRanking> */
    private array $userRanking = [];

    /** @var array<string, array<PuzzleTag>> */
    private array $tags = [];

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
        private readonly PuzzleFilterOptions $puzzleFilterOptions,
        private readonly GetSellSwapListItems $getSellSwapListItems,
        private readonly GetPuzzleDifficulty $getPuzzleDifficulty,
        private readonly CacheInterface $cache,
    ) {
        $this->puzzleStatuses = UserPuzzleStatuses::empty();
        $this->criteria = PuzzleSearchCriteria::fromUserInput(null, null, null, null, [], 'most-solved', false);
    }

    #[LiveAction]
    public function changeSortBy(#[LiveArg] string $sort): void
    {
        if (in_array($sort, PuzzleSearchCriteria::VALID_SORTS, true)) {
            $this->sortBy = $sort;
        }
    }

    /**
     * PreReRender ONLY, deliberately: the component is rendered with
     * loading="defer", so the initial page request only mounts the props and
     * shows the placeholder skeleton - PostMount hooks would run all the
     * queries during that shell render for nothing. The real render always
     * arrives as a live request, which triggers PreReRender.
     */
    #[PreReRender]
    public function loadData(): void
    {
        $this->normalizeState();
        $fromCache = $this->loadPuzzles();
        $this->loadUserData();

        if ($fromCache === false) {
            $this->loadPuzzleMetadata();
        }
    }

    private function normalizeState(): void
    {
        $profile = $this->retrieveLoggedUserProfile->getProfile();

        $this->criteria = PuzzleSearchCriteria::fromUserInput(
            brandId: $this->brandId,
            search: $this->search,
            pieces: $this->pieces,
            tagId: $this->tagId,
            difficultyTiers: $this->difficultyTiers,
            sortBy: $this->sortBy,
            isMember: $profile?->activeMembership === true,
        );

        // Reflect the normalized values back into the props so the rendered
        // controls and the synced URL always match what was actually queried.
        $this->brandId = $this->criteria->brandId;
        $this->search = $this->criteria->search;
        $this->pieces = $this->criteria->pieces;
        $this->tagId = $this->criteria->tagId;
        $this->difficultyTiers = array_map(strval(...), $this->criteria->difficultyTiers);
        $this->sortBy = $this->criteria->sortBy;
    }

    /**
     * @return bool whether puzzles AND their metadata came from the cache
     */
    private function loadPuzzles(): bool
    {
        if ($this->criteria->isDefault()) {
            $cached = $this->getInitialPuzzlesFromCache();
            $this->puzzles = $cached['puzzles'];
            $this->totalCount = $cached['count'];
            $this->tags = $cached['tags'];
            $this->offerCounts = $cached['offerCounts'];
            $this->difficultyData = $cached['difficultyData'];

            return true;
        }

        $piecesFilter = PiecesFilter::fromUserInput($this->criteria->pieces);

        $this->totalCount = $this->searchPuzzle->countByUserInput(
            $this->criteria->brandId,
            $this->criteria->search,
            $piecesFilter,
            $this->criteria->tagId,
            $this->criteria->difficultyTiers,
        );

        $this->puzzles = $this->searchPuzzle->byUserInput(
            $this->criteria->brandId,
            $this->criteria->search,
            $piecesFilter,
            $this->criteria->tagId,
            $this->criteria->sortBy,
            offset: 0,
            limit: PuzzleSearchCriteria::PAGE_SIZE,
            difficultyTiers: $this->criteria->difficultyTiers,
        );

        return false;
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

    private function loadPuzzleMetadata(): void
    {
        $puzzleIds = array_map(
            static fn (PuzzleOverview $puzzle): string => $puzzle->puzzleId,
            $this->puzzles,
        );

        $this->tags = $this->getTags->allGroupedPerPuzzle($puzzleIds);
        $this->offerCounts = $this->getSellSwapListItems->countByPuzzleIds($puzzleIds);
        $this->difficultyData = $this->getPuzzleDifficulty->forPuzzleList($puzzleIds);
    }

    public function getRemainingCount(): int
    {
        return max(0, $this->totalCount - PuzzleSearchCriteria::PAGE_SIZE);
    }

    public function hasMore(): bool
    {
        return $this->totalCount > PuzzleSearchCriteria::PAGE_SIZE;
    }

    /**
     * @return array<string, mixed>
     */
    public function getLoadMoreUrlParameters(): array
    {
        return $this->criteria->toQueryParameters() + ['offset' => PuzzleSearchCriteria::PAGE_SIZE];
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

    public function getSelectedBrandLabel(): null|string
    {
        if ($this->brandId === null) {
            return null;
        }

        return $this->puzzleFilterOptions->manufacturerLabel($this->brandId);
    }

    public function getSelectedTagLabel(): null|string
    {
        if ($this->tagId === null) {
            return null;
        }

        return $this->puzzleFilterOptions->tagLabel($this->tagId);
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

    /**
     * @return list<array{value: string, label: string, premium: bool}>
     */
    public function getSortOptions(): array
    {
        return array_map(
            static fn (string $sort): array => [
                'value' => $sort,
                'label' => 'sorting.' . str_replace('-', '_', $sort),
                'premium' => in_array($sort, PuzzleSearchCriteria::PREMIUM_SORTS, true),
            ],
            PuzzleSearchCriteria::VALID_SORTS,
        );
    }

    /**
     * @return list<array{value: int, label: string, icon: string}>
     */
    public function getDifficultyTierOptions(): array
    {
        return array_map(
            static fn (DifficultyTier $tier): array => [
                'value' => $tier->value,
                'label' => 'puzzle_intelligence.difficulty.tiers.' . strtolower($tier->name),
                'icon' => match ($tier) {
                    DifficultyTier::VeryEasy => 'diff-very-easy',
                    DifficultyTier::Easy => 'diff-easy',
                    DifficultyTier::Average => 'diff-average',
                    DifficultyTier::Challenging => 'diff-challenging',
                    DifficultyTier::Hard => 'diff-hard',
                    DifficultyTier::VeryHard => 'diff-very-hard',
                },
            ],
            DifficultyTier::cases(),
        );
    }

    /**
     * @return array{
     *     puzzles: list<PuzzleOverview>,
     *     count: int,
     *     tags: array<string, array<PuzzleTag>>,
     *     offerCounts: array<string, int>,
     *     difficultyData: array<string, PuzzleDifficultyResult>,
     * }
     */
    private function getInitialPuzzlesFromCache(): array
    {
        return $this->cache->get('initial_puzzles_v2', function (ItemInterface $item): array {
            $item->expiresAfter(3600);
            $pieces = PiecesFilter::fromUserInput(null);

            $puzzles = $this->searchPuzzle->byUserInput(null, null, $pieces, null, 'most-solved', 0);
            $puzzleIds = array_map(static fn (PuzzleOverview $puzzle): string => $puzzle->puzzleId, $puzzles);

            return [
                'puzzles' => $puzzles,
                'count' => $this->searchPuzzle->countByUserInput(null, null, $pieces, null),
                'tags' => $this->getTags->allGroupedPerPuzzle($puzzleIds),
                'offerCounts' => $this->getSellSwapListItems->countByPuzzleIds($puzzleIds),
                'difficultyData' => $this->getPuzzleDifficulty->forPuzzleList($puzzleIds),
            ];
        });
    }
}
