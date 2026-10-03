<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * What the directory of puzzlers shows (docs/features/players-page/README.md, "Browse all"): the scope, four filters,
 * the order and how many cards. All of it lives in the URL - `?scope=cz&active=1&events=1&swaps=1&instagram=1&
 * sort=recent&limit=48` - so a list can be shared and the browser's back button works. Anything unknown falls back to
 * the default instead of an error, like CommunityScope.
 */
readonly final class PlayersDirectoryCriteria
{
    public const int PAGE_SIZE = 24;

    // "Show more" stops here: past a few hundred cards the filters are the better way in, and the page stays light
    public const int MAX_LIMIT = 480;

    public function __construct(
        public CommunityScope $scope,
        public PlayersDirectorySort $sort = PlayersDirectorySort::Active,
        // solved something this calendar month
        public bool $activeThisMonth = false,
        // a participant of a public event
        public bool $competesInEvents = false,
        // has puzzles on their sell/swap list
        public bool $swapsPuzzles = false,
        // links their Instagram
        public bool $onInstagram = false,
        public int $limit = self::PAGE_SIZE,
    ) {
    }

    /**
     * @param array<mixed> $query the request's query parameters; the scope comes from the caller, because a country
     *                            page carries it in the path
     */
    public static function fromQuery(array $query, CommunityScope $scope): self
    {
        return new self(
            scope: $scope,
            sort: PlayersDirectorySort::fromQuery($query['sort'] ?? null),
            activeThisMonth: self::flag($query['active'] ?? null),
            competesInEvents: self::flag($query['events'] ?? null),
            swapsPuzzles: self::flag($query['swaps'] ?? null),
            onInstagram: self::flag($query['instagram'] ?? null),
            limit: self::limit($query['limit'] ?? null),
        );
    }

    public function hasFilters(): bool
    {
        return $this->activeThisMonth || $this->competesInEvents || $this->swapsPuzzles || $this->onInstagram;
    }

    /**
     * The limit "Show more" asks for, or null once the page shows the most it ever will.
     */
    public function nextLimit(): null|int
    {
        $next = $this->limit + self::PAGE_SIZE;

        return $next > self::MAX_LIMIT ? null : $next;
    }

    /**
     * The query parameters of this list, defaults left out. The scope is not among them: the directory carries it as
     * `?scope=`, a country page in its path.
     *
     * @return array<string, string|int>
     */
    public function queryParameters(): array
    {
        $parameters = [];

        if ($this->activeThisMonth) {
            $parameters['active'] = 1;
        }

        if ($this->competesInEvents) {
            $parameters['events'] = 1;
        }

        if ($this->swapsPuzzles) {
            $parameters['swaps'] = 1;
        }

        if ($this->onInstagram) {
            $parameters['instagram'] = 1;
        }

        if ($this->sort->isDefault() === false) {
            $parameters['sort'] = $this->sort->value;
        }

        if ($this->limit !== self::PAGE_SIZE) {
            $parameters['limit'] = $this->limit;
        }

        return $parameters;
    }

    private static function flag(mixed $value): bool
    {
        return is_string($value) && in_array(strtolower($value), ['1', 'true', 'on', 'yes'], true);
    }

    /**
     * Whole pages of PAGE_SIZE, between one page and MAX_LIMIT.
     */
    private static function limit(mixed $value): int
    {
        if (!is_string($value) || !ctype_digit($value)) {
            return self::PAGE_SIZE;
        }

        $pages = (int) ceil(((int) $value) / self::PAGE_SIZE);

        return max(1, min($pages, intdiv(self::MAX_LIMIT, self::PAGE_SIZE))) * self::PAGE_SIZE;
    }
}
