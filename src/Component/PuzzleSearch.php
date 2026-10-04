<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Component;

use SpeedPuzzling\Web\Query\GetPlayerBestSoloTimes;
use SpeedPuzzling\Web\Query\GetPlayerCollections;
use SpeedPuzzling\Web\Query\GetPuzzleDifficulty;
use SpeedPuzzling\Web\Query\GetSellSwapListItems;
use SpeedPuzzling\Web\Query\GetTags;
use SpeedPuzzling\Web\Query\GetUserPuzzleStatuses;
use SpeedPuzzling\Web\Query\SearchPuzzle;
use SpeedPuzzling\Web\Results\CollectionOverview;
use SpeedPuzzling\Web\Results\PuzzleDifficultyResult;
use SpeedPuzzling\Web\Results\PuzzleOverview;
use SpeedPuzzling\Web\Results\PuzzleTag;
use SpeedPuzzling\Web\Results\UserPuzzleStatuses;
use SpeedPuzzling\Web\Services\PuzzleFilterOptions;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use SpeedPuzzling\Web\Value\DifficultyTier;
use SpeedPuzzling\Web\Value\PiecesRange;
use SpeedPuzzling\Web\Value\PuzzleSearchCriteria;
use SpeedPuzzling\Web\Value\PuzzleSearchList;
use SpeedPuzzling\Web\Value\PuzzleSearchListKind;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveArg;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\Attribute\PreReRender;
use Symfony\UX\TwigComponent\Attribute\PostMount;
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

    /**
     * The custom from-to inputs. `pieces` stays the single source of truth:
     * typing composes it (onPiecesBoundsUpdated), every render derives the
     * bounds back from it, so a chip click refills the inputs.
     */
    #[LiveProp(writable: true, onUpdated: 'onPiecesBoundsUpdated')]
    public null|int $piecesMin = null;

    #[LiveProp(writable: true, onUpdated: 'onPiecesBoundsUpdated')]
    public null|int $piecesMax = null;

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

    /**
     * The sort the visitor picked - null until they pick one: then the best match applies while a term is typed,
     * the most solved otherwise (PuzzleSearchCriteria). The URL carries a pick only.
     */
    #[LiveProp(writable: true, url: true)]
    public null|string $sortBy = null;

    /**
     * "Only puzzles from my ..." (see PuzzleSearchList) - signed-in players only,
     * member lists for members only; enforced by PuzzleSearchCriteria.
     */
    #[LiveProp(writable: true, url: true)]
    public null|string $list = null;

    /** @var list<PuzzleOverview> */
    public array $puzzles = [];

    public int $totalCount = 0;

    private PuzzleSearchCriteria $criteria;

    private UserPuzzleStatuses $puzzleStatuses;

    /**
     * The viewer's best solo time per listed puzzle, in seconds
     *
     * @var array<string, int>
     */
    private array $myTimes = [];

    /** @var array<string, array<PuzzleTag>> */
    private array $tags = [];

    /** @var array<string, int> */
    private array $offerCounts = [];

    /** @var array<string, PuzzleDifficultyResult> */
    private array $difficultyData = [];

    private bool $dataLoaded = false;

    /** @var null|list<CollectionOverview> */
    private null|array $ownCollections = null;

    public function __construct(
        private readonly SearchPuzzle $searchPuzzle,
        private readonly GetUserPuzzleStatuses $getUserPuzzleStatuses,
        private readonly GetPlayerBestSoloTimes $getPlayerBestSoloTimes,
        private readonly RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        private readonly GetTags $getTags,
        private readonly PuzzleFilterOptions $puzzleFilterOptions,
        private readonly GetSellSwapListItems $getSellSwapListItems,
        private readonly GetPuzzleDifficulty $getPuzzleDifficulty,
        private readonly GetPlayerCollections $getPlayerCollections,
        private readonly CacheInterface $cache,
    ) {
        $this->puzzleStatuses = UserPuzzleStatuses::empty();
        $this->criteria = PuzzleSearchCriteria::fromUserInput(null, null, null, null, [], null, false);
    }

    public function onPiecesBoundsUpdated(): void
    {
        $this->pieces = PiecesRange::fromBounds($this->piecesMin, $this->piecesMax)?->toParam();
    }

    #[LiveAction]
    public function changeSortBy(#[LiveArg] string $sort): void
    {
        if (in_array($sort, PuzzleSearchCriteria::VALID_SORTS, true)) {
            $this->sortBy = $sort;
        }
    }

    /**
     * The default (criteria-less) view is rendered synchronously on the page
     * request so crawlers get page one as real HTML - it is served from the
     * app cache, so the synchronous render stays cheap. Filtered/search deep
     * links render with loading="defer" (see puzzles.html.twig): PostMount
     * skips them (their queries would run during the shell render for
     * nothing) and the deferred live request triggers PreReRender instead.
     */
    #[PostMount]
    public function loadInitialData(): void
    {
        // A list makes the view non-default, so its ownership check can wait for the deferred render
        $this->normalizeState(checkListOwnership: false);

        if ($this->criteria->isDefault()) {
            $this->loadData();
        }
    }

    #[PreReRender]
    public function loadData(): void
    {
        if ($this->dataLoaded) {
            return;
        }

        $this->dataLoaded = true;
        $this->normalizeState();
        $fromCache = $this->loadPuzzles();
        $this->loadUserData();

        if ($fromCache === false) {
            $this->loadPuzzleMetadata();
        }
    }

    private function normalizeState(bool $checkListOwnership = true): void
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
            list: $checkListOwnership === false || $this->isOwnList($this->list) ? $this->list : null,
            isLoggedIn: $profile !== null,
        );

        // Reflect the normalized values back into the props so the rendered
        // controls and the synced URL always match what was actually queried.
        $this->brandId = $this->criteria->brandId;
        $this->search = $this->criteria->search;
        $this->pieces = $this->criteria->pieces;
        $piecesRange = $this->criteria->piecesRange();
        $this->piecesMin = $piecesRange->minPieces;
        $this->piecesMax = $piecesRange->maxPieces;
        $this->tagId = $this->criteria->tagId;
        $this->difficultyTiers = array_map(strval(...), $this->criteria->difficultyTiers);
        $this->sortBy = $this->criteria->chosenSort;
        $this->list = $this->criteria->list?->value();
    }

    /**
     * A collection that is not (or no longer) one of the viewer's would match
     * nothing while the select could not show it as selected - drop it instead.
     * Costs the collections query only when a collection is actually picked.
     */
    private function isOwnList(null|string $list): bool
    {
        $parsed = PuzzleSearchList::tryFrom($list);

        if ($parsed === null || $parsed->kind !== PuzzleSearchListKind::Collection) {
            return true;
        }

        foreach ($this->getOwnCollections() as $collection) {
            if ($collection->collectionId === $parsed->collectionId) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<CollectionOverview>
     */
    private function getOwnCollections(): array
    {
        if ($this->ownCollections === null) {
            $profile = $this->retrieveLoggedUserProfile->getProfile();

            $this->ownCollections = $profile !== null && $profile->activeMembership
                ? array_values($this->getPlayerCollections->byPlayerId($profile->playerId))
                : [];
        }

        return $this->ownCollections;
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

        $playerId = $this->retrieveLoggedUserProfile->getProfile()?->playerId;
        $piecesFilter = $this->criteria->piecesRange();

        $this->totalCount = $this->searchPuzzle->countByUserInput(
            $this->criteria->brandId,
            $this->criteria->search,
            $piecesFilter,
            $this->criteria->tagId,
            $this->criteria->difficultyTiers,
            $this->criteria->list,
            $playerId,
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
            list: $this->criteria->list,
            listPlayerId: $playerId,
        );

        return false;
    }

    private function loadUserData(): void
    {
        $playerProfile = $this->retrieveLoggedUserProfile->getProfile();

        $this->puzzleStatuses = $this->getUserPuzzleStatuses->byPlayerId($playerProfile?->playerId);

        // Not a rank on every puzzle the viewer ever solved - the cards show their best time only
        $this->myTimes = $playerProfile !== null
            ? $this->getPlayerBestSoloTimes->forPuzzles($playerProfile->playerId, array_map(
                static fn (PuzzleOverview $puzzle): string => $puzzle->puzzleId,
                $this->puzzles,
            ))
            : [];
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
     * @return array<string, int>
     */
    public function getMyTimes(): array
    {
        return $this->myTimes;
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
     * The order that applies - the picked one, or the default for the typed term
     */
    public function getActiveSort(): string
    {
        return $this->criteria->sortBy;
    }

    /**
     * "Best match" only while a term is typed
     *
     * @return list<array{value: string, label: string, premium: bool}>
     */
    public function getSortOptions(): array
    {
        $sorts = $this->criteria->hasSearchTerm()
            ? PuzzleSearchCriteria::VALID_SORTS
            : array_values(array_diff(PuzzleSearchCriteria::VALID_SORTS, [PuzzleSearchCriteria::BEST_MATCH]));

        return array_map(
            static fn (string $sort): array => [
                'value' => $sort,
                'label' => 'sorting.' . str_replace('-', '_', $sort),
                'premium' => in_array($sort, PuzzleSearchCriteria::PREMIUM_SORTS, true),
            ],
            $sorts,
        );
    }

    /**
     * The viewer's lists for the "My list" select - empty for guests (no select,
     * no query). Members additionally get their custom collections and the
     * lending / sell-swap lists; labels are translation keys unless `translate`
     * is false (collection names).
     *
     * @return list<array{group: null|string, options: list<array{value: string, label: string, translate: bool}>}>
     */
    public function getListOptions(): array
    {
        $profile = $this->retrieveLoggedUserProfile->getProfile();

        if ($profile === null) {
            return [];
        }

        $option = static fn (PuzzleSearchListKind $kind, string $label): array => [
            'value' => $kind->value,
            'label' => $label,
            'translate' => true,
        ];

        $groups = [[
            'group' => null,
            'options' => [
                $option(PuzzleSearchListKind::Library, 'collections.system_name'),
                $option(PuzzleSearchListKind::Wishlist, 'puzzle_search.list.wishlist'),
                $option(PuzzleSearchListKind::Unsolved, 'puzzle_search.list.unsolved'),
                $option(PuzzleSearchListKind::Solved, 'puzzle_search.list.solved'),
            ],
        ]];

        if ($profile->activeMembership === false) {
            return $groups;
        }

        $collections = array_map(
            static fn (CollectionOverview $collection): array => [
                'value' => PuzzleSearchList::collection((string) $collection->collectionId)->value(),
                'label' => $collection->name,
                'translate' => false,
            ],
            array_values(array_filter(
                $this->getOwnCollections(),
                static fn (CollectionOverview $collection): bool => $collection->collectionId !== null,
            )),
        );

        if ($collections !== []) {
            $groups[] = ['group' => 'puzzle_search.list.group_collections', 'options' => $collections];
        }

        $groups[] = [
            'group' => 'puzzle_search.list.group_members',
            'options' => [
                $option(PuzzleSearchListKind::Borrowed, 'puzzle_search.list.borrowed'),
                $option(PuzzleSearchListKind::Lent, 'puzzle_search.list.lent'),
                $option(PuzzleSearchListKind::SellSwap, 'puzzle_search.list.sell_swap'),
            ],
        ];

        return $groups;
    }

    /**
     * @return list<array{value: int, label: string, icon: string}>
     */
    public function getDifficultyTierOptions(): array
    {
        $options = array_map(
            static fn (DifficultyTier $tier): array => [
                'value' => $tier->value,
                'label' => $tier->translationKey(),
                'icon' => $tier->icon(),
            ],
            DifficultyTier::cases(),
        );

        $options[] = [
            'value' => PuzzleSearchCriteria::UNRATED_DIFFICULTY,
            'label' => 'puzzle_intelligence.difficulty.tiers.unknown',
            'icon' => 'diff-unknown',
        ];

        return $options;
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
        // v4: the puzzles carry every other name (PuzzleNames) - a new shape, so the old and the new release never read
        // each other's entry (Redis is shared across blue-green)
        return $this->cache->get('initial_puzzles_v4', function (ItemInterface $item): array {
            $item->expiresAfter(3600);
            $pieces = PiecesRange::any();

            $puzzles = $this->searchPuzzle->byUserInput(null, null, $pieces, null, PuzzleSearchCriteria::MOST_SOLVED, 0);
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
