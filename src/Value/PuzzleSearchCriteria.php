<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

use Ramsey\Uuid\Uuid;
use Symfony\Component\HttpFoundation\Request;

/**
 * Normalized /puzzle search state, shared by the PuzzleSearch live component
 * and the load-more items endpoint so the two can never drift apart.
 *
 * Input may come from URLs or client-side props, so it can carry values the UI
 * never produces: empty strings from cleared selects, mangled UUIDs from
 * truncated links, unknown sorts, premium filters from non-members, or a
 * "my list" filter from guests.
 * Everything is normalized here so querying stays graceful.
 *
 * Sorting: `chosenSort` is what the visitor picked (null = nothing), `sortBy`
 * the order that applies - without a pick the best match while a term is
 * typed, the most solved otherwise. "Best match" without a term means nothing
 * and counts as no pick.
 */
final readonly class PuzzleSearchCriteria
{
    public const int PAGE_SIZE = 20;

    public const string BEST_MATCH = 'best-match';

    public const string MOST_SOLVED = 'most-solved';

    /** @var list<string> */
    public const array VALID_SORTS = [self::BEST_MATCH, self::MOST_SOLVED, 'least-solved', 'a-z', 'z-a', 'easiest', 'hardest'];

    /** @var list<string> */
    public const array PREMIUM_SORTS = ['easiest', 'hardest'];

    /**
     * Difficulty "tier" for puzzles that are not rated yet (no tier computed) -
     * filterable next to the six DifficultyTier values.
     */
    public const int UNRATED_DIFFICULTY = 0;

    /**
     * @param list<int> $difficultyTiers
     */
    private function __construct(
        public null|string $brandId,
        public null|string $search,
        public null|string $pieces,
        public null|string $tagId,
        public array $difficultyTiers,
        public string $sortBy,
        public null|string $chosenSort,
        public null|PuzzleSearchList $list,
    ) {
    }

    /**
     * @param array<mixed> $difficultyTiers
     */
    public static function fromUserInput(
        null|string $brandId,
        null|string $search,
        null|string $pieces,
        null|string $tagId,
        array $difficultyTiers,
        null|string $sortBy,
        bool $isMember,
        null|string $list = null,
        bool $isLoggedIn = false,
    ): self {
        $hasSearchTerm = PuzzleSearchQuery::fromUserInput($search)->isEmpty() === false;
        $chosenSort = in_array($sortBy, self::VALID_SORTS, true) ? $sortBy : null;

        if ($chosenSort === self::BEST_MATCH && $hasSearchTerm === false) {
            $chosenSort = null;
        }

        // Difficulty filtering and difficulty sorting are members-only; the UI hides
        // the controls, this enforces it against crafted URLs and live actions.
        if ($isMember === false) {
            $difficultyTiers = [];

            if (in_array($chosenSort, self::PREMIUM_SORTS, true)) {
                $chosenSort = null;
            }
        }

        // The viewer's own lists need a viewer; member lists need a membership.
        $parsedList = PuzzleSearchList::tryFrom($list);

        if ($parsedList !== null && ($isLoggedIn === false || ($parsedList->isMembersOnly() && $isMember === false))) {
            $parsedList = null;
        }

        return new self(
            brandId: self::normalizeUuid($brandId),
            search: $search === '' ? null : $search,
            pieces: PiecesRange::parse($pieces)?->toParam(),
            tagId: self::normalizeUuid($tagId),
            difficultyTiers: self::normalizeDifficultyTiers($difficultyTiers),
            sortBy: $chosenSort ?? self::defaultSort($hasSearchTerm),
            chosenSort: $chosenSort,
            list: $parsedList,
        );
    }

    public static function fromRequest(Request $request, bool $isMember, bool $isLoggedIn = false): self
    {
        $query = $request->query->all();

        return self::fromUserInput(
            brandId: is_string($query['brand'] ?? null) ? $query['brand'] : null,
            search: is_string($query['search'] ?? null) ? $query['search'] : null,
            pieces: is_string($query['pieces'] ?? null) ? $query['pieces'] : null,
            tagId: is_string($query['tag'] ?? null) ? $query['tag'] : null,
            difficultyTiers: is_array($query['difficultyTiers'] ?? null) ? $query['difficultyTiers'] : [],
            sortBy: is_string($query['sortBy'] ?? null) ? $query['sortBy'] : null,
            isMember: $isMember,
            list: is_string($query['list'] ?? null) ? $query['list'] : null,
            isLoggedIn: $isLoggedIn,
        );
    }

    /**
     * The default view is served from a cache shared by every visitor, so
     * anything viewer-specific (the list) must make it non-default.
     */
    public function isDefault(): bool
    {
        return $this->brandId === null
            && $this->search === null
            && $this->pieces === null
            && $this->tagId === null
            && $this->difficultyTiers === []
            && $this->sortBy === self::MOST_SOLVED
            && $this->list === null;
    }

    /**
     * Whether a search term was typed - one that matches something, not only spaces or control characters
     */
    public function hasSearchTerm(): bool
    {
        return $this->search !== null && PuzzleSearchQuery::fromUserInput($this->search)->isEmpty() === false;
    }

    public function piecesRange(): PiecesRange
    {
        return PiecesRange::parse($this->pieces) ?? PiecesRange::any();
    }

    /**
     * Query parameters understood by both the puzzles page and the items endpoint.
     *
     * @return array<string, mixed>
     */
    public function toQueryParameters(): array
    {
        $parameters = [];

        if ($this->brandId !== null) {
            $parameters['brand'] = $this->brandId;
        }

        if ($this->search !== null) {
            $parameters['search'] = $this->search;
        }

        if ($this->pieces !== null) {
            $parameters['pieces'] = $this->pieces;
        }

        if ($this->tagId !== null) {
            $parameters['tag'] = $this->tagId;
        }

        if ($this->difficultyTiers !== []) {
            $parameters['difficultyTiers'] = $this->difficultyTiers;
        }

        if ($this->sortBy !== self::defaultSort($this->hasSearchTerm())) {
            $parameters['sortBy'] = $this->sortBy;
        }

        if ($this->list !== null) {
            $parameters['list'] = $this->list->value();
        }

        return $parameters;
    }

    private static function defaultSort(bool $hasSearchTerm): string
    {
        return $hasSearchTerm ? self::BEST_MATCH : self::MOST_SOLVED;
    }

    private static function normalizeUuid(null|string $value): null|string
    {
        if ($value === null || Uuid::isValid($value) === false) {
            return null;
        }

        return $value;
    }

    /**
     * Values may come from the client, so anything that is not a tier or the
     * "not rated yet" value (including tampered payloads with nested
     * structures) is dropped, not crashed on.
     *
     * @param array<mixed> $difficultyTiers
     *
     * @return list<int>
     */
    private static function normalizeDifficultyTiers(array $difficultyTiers): array
    {
        $tiers = [];

        foreach ($difficultyTiers as $tier) {
            if (is_numeric($tier) === false) {
                continue;
            }

            $tier = (int) $tier;

            if ($tier === self::UNRATED_DIFFICULTY || DifficultyTier::tryFrom($tier) !== null) {
                $tiers[] = $tier;
            }
        }

        return $tiers;
    }
}
