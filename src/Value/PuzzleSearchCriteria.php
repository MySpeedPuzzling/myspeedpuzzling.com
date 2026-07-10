<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Results\PiecesFilter;
use Symfony\Component\HttpFoundation\Request;

/**
 * Normalized /puzzle search state, shared by the PuzzleSearch live component
 * and the load-more items endpoint so the two can never drift apart.
 *
 * Input may come from URLs or client-side props, so it can carry values the UI
 * never produces: empty strings from cleared selects, mangled UUIDs from
 * truncated links, unknown sorts, or premium filters from non-members.
 * Everything is normalized here so querying stays graceful.
 */
final readonly class PuzzleSearchCriteria
{
    public const int PAGE_SIZE = 20;

    /** @var list<string> */
    public const array VALID_SORTS = ['most-solved', 'least-solved', 'a-z', 'z-a', 'easiest', 'hardest'];

    /** @var list<string> */
    public const array PREMIUM_SORTS = ['easiest', 'hardest'];

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
        string $sortBy,
        bool $isMember,
    ): self {
        if (in_array($sortBy, self::VALID_SORTS, true) === false) {
            $sortBy = 'most-solved';
        }

        // Difficulty filtering and difficulty sorting are members-only; the UI hides
        // the controls, this enforces it against crafted URLs and live actions.
        if ($isMember === false) {
            $difficultyTiers = [];

            if (in_array($sortBy, self::PREMIUM_SORTS, true)) {
                $sortBy = 'most-solved';
            }
        }

        return new self(
            brandId: self::normalizeUuid($brandId),
            search: $search === '' ? null : $search,
            pieces: $pieces !== null ? PiecesFilter::tryFrom($pieces)?->value : null,
            tagId: self::normalizeUuid($tagId),
            difficultyTiers: self::normalizeDifficultyTiers($difficultyTiers),
            sortBy: $sortBy,
        );
    }

    public static function fromRequest(Request $request, bool $isMember): self
    {
        $query = $request->query->all();

        return self::fromUserInput(
            brandId: is_string($query['brand'] ?? null) ? $query['brand'] : null,
            search: is_string($query['search'] ?? null) ? $query['search'] : null,
            pieces: is_string($query['pieces'] ?? null) ? $query['pieces'] : null,
            tagId: is_string($query['tag'] ?? null) ? $query['tag'] : null,
            difficultyTiers: is_array($query['difficultyTiers'] ?? null) ? $query['difficultyTiers'] : [],
            sortBy: is_string($query['sortBy'] ?? null) ? $query['sortBy'] : 'most-solved',
            isMember: $isMember,
        );
    }

    public function isDefault(): bool
    {
        return $this->brandId === null
            && $this->search === null
            && $this->pieces === null
            && $this->tagId === null
            && $this->difficultyTiers === []
            && $this->sortBy === 'most-solved';
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

        if ($this->sortBy !== 'most-solved') {
            $parameters['sortBy'] = $this->sortBy;
        }

        return $parameters;
    }

    private static function normalizeUuid(null|string $value): null|string
    {
        if ($value === null || Uuid::isValid($value) === false) {
            return null;
        }

        return $value;
    }

    /**
     * Values may come from the client, so anything non-numeric (including
     * tampered payloads with nested structures) is dropped, not crashed on.
     *
     * @param array<mixed> $difficultyTiers
     *
     * @return list<int>
     */
    private static function normalizeDifficultyTiers(array $difficultyTiers): array
    {
        $tiers = [];

        foreach ($difficultyTiers as $tier) {
            if (is_numeric($tier)) {
                $tiers[] = (int) $tier;
            }
        }

        return $tiers;
    }
}
