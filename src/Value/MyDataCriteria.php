<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\Collection;

/**
 * The state of "My data" (docs/features/my-data.md) - the URL is the state, so everything a crafted link can carry is
 * normalised here: an unknown tab, sort or filter value falls back to its default, never an error.
 *
 * Grammar of the query string (a default is left out of the canonical URL):
 *
 * - `tab` = `results` (default) | `puzzles` | `wishlist`
 * - `q` = the player's own search text (puzzle name, code, brand), at most 100 characters
 * - `sort` = results: `date` (default) | `time` | `pieces` | `name` | `brand`; puzzles and wish list: `added` (default)
 *   | `name` | `pieces` | `brand`
 * - `dir` = `asc` | `desc` (default: the newest first for `date` / `added`, ascending for the others)
 * - `pieces` = PiecesRange's grammar (`N` | `A-B` | `A-` | `-B`)
 * - `page` = 1…
 * - results only: `type` = `solo` | `duo` | `team`, `year` = 1990…2100, `first=1` (first tries only), `imported=1`
 *   (from the player's spreadsheets only), `comment=1`, `photo=1`
 * - puzzles only: `collection` = a collection's uuid | `main` (the main collection), `comment=1`
 */
readonly final class MyDataCriteria
{
    public const int PAGE_SIZE = 50;
    public const int MAX_SEARCH_LENGTH = 100;
    public const int MAX_PAGE = 100_000;
    public const string MAIN_COLLECTION = 'main';
    public const array SOLVING_TYPES = ['solo', 'duo', 'team'];

    private function __construct(
        public MyDataTab $tab,
        public null|string $search,
        public MyDataSort $sort,
        public bool $descending,
        public null|PiecesRange $pieces,
        public null|string $solvingType,
        public null|int $year,
        public bool $firstTryOnly,
        public bool $importedOnly,
        public bool $withComment,
        public bool $withPhoto,
        // A collection's uuid or Collection::SYSTEM_ID (the main collection)
        public null|string $collectionId,
        public int $page,
    ) {
    }

    /**
     * @param array<mixed> $query
     */
    public static function fromUserInput(array $query): self
    {
        $tab = MyDataTab::tryFrom(self::text($query, 'tab') ?? '') ?? MyDataTab::Results;

        $sort = MyDataSort::tryFrom(self::text($query, 'sort') ?? '');

        if ($sort === null || in_array($sort, $tab->sorts(), true) === false) {
            $sort = $tab->sorts()[0];
        }

        $descending = match (self::text($query, 'dir')) {
            'asc' => false,
            'desc' => true,
            default => $sort->descendingByDefault(),
        };

        $search = self::text($query, 'q');

        if ($search !== null) {
            $search = mb_check_encoding($search, 'UTF-8') ? trim((string) preg_replace('/[\p{Cc}\s]+/u', ' ', $search)) : '';
            $search = $search === '' ? null : mb_substr($search, 0, self::MAX_SEARCH_LENGTH);
        }

        $isResults = $tab === MyDataTab::Results;
        $isPuzzles = $tab === MyDataTab::Puzzles;

        $solvingType = self::text($query, 'type');
        $yearText = self::text($query, 'year') ?? '';
        $year = preg_match('/^\d{4}$/', $yearText) === 1 ? (int) $yearText : null;

        $collection = self::text($query, 'collection');

        $collectionId = match (true) {
            $collection === self::MAIN_COLLECTION => Collection::SYSTEM_ID,
            $collection !== null && Uuid::isValid($collection) => strtolower($collection),
            default => null,
        };

        $pageText = self::text($query, 'page') ?? '';
        $page = preg_match('/^\d{1,6}$/', $pageText) === 1 ? (int) $pageText : 1;

        return new self(
            tab: $tab,
            search: $search,
            sort: $sort,
            descending: $descending,
            pieces: PiecesRange::parse(self::text($query, 'pieces')),
            solvingType: $isResults && in_array($solvingType, self::SOLVING_TYPES, true) ? $solvingType : null,
            year: $isResults && $year !== null && $year >= 1990 && $year <= 2100 ? $year : null,
            firstTryOnly: $isResults && self::flag($query, 'first'),
            importedOnly: $isResults && self::flag($query, 'imported'),
            withComment: ($isResults || $isPuzzles) && self::flag($query, 'comment'),
            withPhoto: $isResults && self::flag($query, 'photo'),
            collectionId: $isPuzzles ? $collectionId : null,
            page: min(self::MAX_PAGE, max(1, $page)),
        );
    }

    public function offset(): int
    {
        return ($this->page - 1) * self::PAGE_SIZE;
    }

    public function onPage(int $page): self
    {
        return new self(
            $this->tab,
            $this->search,
            $this->sort,
            $this->descending,
            $this->pieces,
            $this->solvingType,
            $this->year,
            $this->firstTryOnly,
            $this->importedOnly,
            $this->withComment,
            $this->withPhoto,
            $this->collectionId,
            max(1, $page),
        );
    }

    public function hasFilters(): bool
    {
        return $this->query(['tab' => null, 'sort' => null, 'dir' => null, 'page' => null]) !== [];
    }

    public function collectionParam(): null|string
    {
        return $this->collectionId === Collection::SYSTEM_ID ? self::MAIN_COLLECTION : $this->collectionId;
    }

    /**
     * The canonical query string of this state with some values changed - a null (or a default) takes a value out.
     * A change of anything but the page starts at the first page again.
     *
     * @param array<string, null|string|int> $changes
     * @return array<string, string>
     */
    public function query(array $changes = []): array
    {
        $current = [
            'tab' => $this->tab === MyDataTab::Results ? null : $this->tab->value,
            'q' => $this->search,
            'sort' => $this->sort === $this->tab->sorts()[0] ? null : $this->sort->value,
            'dir' => $this->descending === $this->sort->descendingByDefault() ? null : ($this->descending ? 'desc' : 'asc'),
            'pieces' => $this->pieces?->toParam(),
            'type' => $this->solvingType,
            'year' => $this->year,
            'first' => $this->firstTryOnly ? 1 : null,
            'imported' => $this->importedOnly ? 1 : null,
            'comment' => $this->withComment ? 1 : null,
            'photo' => $this->withPhoto ? 1 : null,
            'collection' => $this->collectionParam(),
            'page' => $this->page > 1 && $changes === [] ? $this->page : null,
        ];

        $query = [];

        foreach ([...$current, ...$changes] as $name => $value) {
            if ($value !== null && $value !== '') {
                $query[$name] = (string) $value;
            }
        }

        return $query;
    }

    /**
     * The link of a column's header: a click on the sorted column turns it round, on another one sorts by it.
     *
     * @return array<string, string>
     */
    public function sortQuery(string $sortValue): array
    {
        $sort = MyDataSort::tryFrom($sortValue);

        if ($sort === null || in_array($sort, $this->tab->sorts(), true) === false) {
            return $this->query();
        }

        $descending = $sort === $this->sort ? $this->descending === false : $sort->descendingByDefault();

        return $this->query([
            'sort' => $sort === $this->tab->sorts()[0] ? null : $sort->value,
            'dir' => $descending === $sort->descendingByDefault() ? null : ($descending ? 'desc' : 'asc'),
        ]);
    }

    /**
     * @param array<mixed> $query
     */
    private static function text(array $query, string $name): null|string
    {
        $value = $query[$name] ?? null;

        return is_string($value) ? $value : null;
    }

    /**
     * @param array<mixed> $query
     */
    private static function flag(array $query, string $name): bool
    {
        return ($query[$name] ?? null) === '1';
    }
}
