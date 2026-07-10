<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Component;

use Ramsey\Uuid\Uuid;
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
use SpeedPuzzling\Web\Value\DifficultyTier;
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

    /** @var list<string> */
    private const array PREMIUM_SORTS = ['easiest', 'hardest'];

    #[LiveProp(writable: true, onUpdated: 'onFilterUpdated', url: new UrlMapping(as: 'brand'))]
    public null|string $brandId = null;

    #[LiveProp(writable: true, onUpdated: 'onFilterUpdated', url: true)]
    public null|string $search = null;

    #[LiveProp(writable: true, onUpdated: 'onFilterUpdated', url: true)]
    public null|string $pieces = null;

    #[LiveProp(writable: true, onUpdated: 'onFilterUpdated', url: new UrlMapping(as: 'tag'))]
    public null|string $tagId = null;

    /**
     * Declared list<string> on purpose: both URL query params and checkbox values
     * arrive as strings, and the framework's url-prop hydration type-checks array
     * elements. Values are normalized to ints at query time.
     *
     * @var list<string>
     */
    #[LiveProp(writable: true, onUpdated: 'onFilterUpdated', url: true)]
    public array $difficultyTiers = [];

    #[LiveProp(writable: true, url: true)]
    public string $sortBy = 'most-solved';

    #[LiveProp]
    public int $displayLimit = self::LIMIT;

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
        private readonly CacheInterface $cache,
    ) {
        $this->puzzleStatuses = UserPuzzleStatuses::empty();
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

    /**
     * Filter options only feed the Tom Select widgets, which live inside
     * data-live-ignore blocks that the client never morphs - loading them
     * on live re-renders would be wasted work, so this runs on mount only.
     */
    #[PostMount]
    public function loadFilterOptions(): void
    {
        $this->allTags = $this->getTags->all();
        $this->manufacturers = $this->getManufacturers->onlyApprovedOrAddedByPlayer();
    }

    #[PostMount]
    #[PreReRender]
    public function loadData(): void
    {
        $this->normalizeState();
        $this->loadPuzzles();
        $this->loadUserData();
        $this->loadPuzzleMetadata();
    }

    /**
     * Writable props arrive from the URL or the client, so they may carry values
     * the UI never produces: empty strings from cleared Tom Selects, mangled UUIDs
     * from truncated links, unknown sorts, or premium filters from non-members.
     * Normalizing here (before querying) keeps every render graceful.
     */
    private function normalizeState(): void
    {
        $this->brandId = $this->normalizeUuid($this->brandId);
        $this->tagId = $this->normalizeUuid($this->tagId);

        if ($this->pieces === '') {
            $this->pieces = null;
        }

        if (in_array($this->sortBy, self::VALID_SORTS, true) === false) {
            $this->sortBy = 'most-solved';
        }

        // Difficulty filtering and difficulty sorting are members-only; the template
        // hides the controls, this enforces it against crafted URLs and live actions.
        if ($this->retrieveLoggedUserProfile->getProfile()?->activeMembership !== true) {
            $this->difficultyTiers = [];

            if (in_array($this->sortBy, self::PREMIUM_SORTS, true)) {
                $this->sortBy = 'most-solved';
            }
        }
    }

    private function normalizeUuid(null|string $value): null|string
    {
        if ($value === null || Uuid::isValid($value) === false) {
            return null;
        }

        return $value;
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

        // Growing past the result set would only make every re-render more expensive.
        $this->displayLimit = min($this->displayLimit, max(self::LIMIT, $this->totalCount));

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

    /**
     * @return list<array{value: string, label: string, premium: bool}>
     */
    public function getSortOptions(): array
    {
        return array_map(
            static fn (string $sort): array => [
                'value' => $sort,
                'label' => 'sorting.' . str_replace('-', '_', $sort),
                'premium' => in_array($sort, self::PREMIUM_SORTS, true),
            ],
            self::VALID_SORTS,
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
     * Values may come from the client, so anything non-numeric (including
     * tampered payloads with nested structures) is dropped, not crashed on.
     *
     * @return list<int>
     */
    private function normalizedDifficultyTiers(): array
    {
        $tiers = [];

        foreach ($this->difficultyTiers as $tier) {
            if (is_numeric($tier)) {
                $tiers[] = (int) $tier;
            }
        }

        return $tiers;
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
