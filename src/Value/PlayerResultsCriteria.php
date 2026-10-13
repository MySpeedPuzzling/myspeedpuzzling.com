<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

use DateTimeImmutable;
use Ramsey\Uuid\Uuid;
use Symfony\Component\HttpFoundation\Request;

/**
 * Normalized state of a player's Results page (docs/features/navigation README §4.7, PROF-05a). The URL is the state,
 * so the input may carry anything a crafted link can: unknown values, mangled uuids, impossible dates, members-only
 * filters from a free viewer. Everything is normalized here - an invalid value falls back to its default, a members-only
 * value of a non-member is dropped silently (never an error, never a chip), the same server-side rule as
 * ComparisonCriteria::fromUserInput().
 *
 * URL grammar (toQuery() emits the non-defaults in this order):
 * - `category` solo|duo|group - default: Solo, or the first tab with results when the player has no solo result
 *   (decided by PlayerResultsBuilder, which knows the history; null here = "the default")
 * - `view` puzzles|every (default puzzles), `sort` PlayerResultsSort (default newest; easiest/hardest members only)
 * - free filters: `q` (≤ 100 characters), `brand` (the brand's name), `pieces` (PiecesRange), `team` (a pair/team id)
 * - members: `first`, `unboxed`, `relax` (`1`), `from` / `to` (Y-m-d), `speed` + `speed_cmp` (faster|slower) +
 *   `speed_unit` (ppm|min), `difficultyTiers[]` (DifficultyFilter values, `0` = not rated yet - the name of /puzzle's)
 * - `page` (≥ 1; the builder clamps it to the last page)
 *
 * Every change of the list (category, view, sort, a filter) starts from page 1 again.
 */
final readonly class PlayerResultsCriteria
{
    public const string CATEGORY_SOLO = 'solo';
    public const string CATEGORY_DUO = 'duo';
    public const string CATEGORY_GROUP = 'group';

    public const array CATEGORIES = [self::CATEGORY_SOLO, self::CATEGORY_DUO, self::CATEGORY_GROUP];

    public const int MAX_QUERY_LENGTH = 100;

    // A page far beyond any history (2,200 puzzles = 44 pages) - the builder clamps it to the last one anyway
    public const int MAX_PAGE = 10000;

    public const string SPEED_FASTER = 'faster';
    public const string SPEED_SLOWER = 'slower';
    public const string SPEED_UNIT_PPM = 'ppm';
    public const string SPEED_UNIT_MINUTES = 'min';

    private const float MAX_SPEED = 100000.0;

    private const string DATE_FORMAT = 'Y-m-d';

    private const string MIN_DATE = '1990-01-01';

    private const string MAX_DATE = '2100-12-31';

    // A brand's name as the select sends it (manufacturer.name)
    private const int MAX_BRAND_LENGTH = 255;

    /**
     * @param list<string> $difficultyTiers DifficultyFilter::normalize() values
     */
    private function __construct(
        public null|string $category,
        public PlayerResultsView $view,
        public PlayerResultsSort $sort,
        public null|string $query,
        public null|string $brand,
        public null|PiecesRange $pieces,
        public null|string $team,
        public bool $onlyFirstTries,
        public bool $onlyUnboxed,
        public bool $onlyRelax,
        public null|DateTimeImmutable $dateFrom,
        public null|DateTimeImmutable $dateTo,
        public null|float $speedValue,
        public string $speedComparison,
        public string $speedUnit,
        public array $difficultyTiers,
        public int $page,
    ) {
    }

    public static function fromRequest(Request $request, bool $isMember): self
    {
        return self::fromQuery($request->query->all(), $isMember);
    }

    /**
     * @param array<mixed> $query
     */
    public static function fromQuery(array $query, bool $isMember): self
    {
        return self::fromUserInput(
            isMember: $isMember,
            category: self::stringInput($query['category'] ?? null),
            view: self::stringInput($query['view'] ?? null),
            sort: self::stringInput($query['sort'] ?? null),
            query: self::stringInput($query['q'] ?? null),
            brand: self::stringInput($query['brand'] ?? null),
            pieces: self::stringInput($query['pieces'] ?? null),
            piecesMin: self::stringInput($query['pieces_min'] ?? null),
            piecesMax: self::stringInput($query['pieces_max'] ?? null),
            piecesWas: self::stringInput($query['pieces_was'] ?? null),
            team: self::stringInput($query['team'] ?? null),
            onlyFirstTries: self::flag($query['first'] ?? null),
            onlyUnboxed: self::flag($query['unboxed'] ?? null),
            onlyRelax: self::flag($query['relax'] ?? null),
            from: self::stringInput($query['from'] ?? null),
            to: self::stringInput($query['to'] ?? null),
            speed: self::stringInput($query['speed'] ?? null),
            speedComparison: self::stringInput($query['speed_cmp'] ?? null),
            speedUnit: self::stringInput($query['speed_unit'] ?? null),
            difficultyTiers: is_array($query['difficultyTiers'] ?? null) ? $query['difficultyTiers'] : [],
            page: self::stringInput($query['page'] ?? null),
        );
    }

    /**
     * Raw values as a URL or a form carries them. The filter form offers the piece counts twice - `pieces` chips and the
     * custom `pieces_min` / `pieces_max` bounds - and says what it showed in `pieces_was` (the applied range), so the
     * one the visitor changed wins without JavaScript: typed bounds that differ from what was applied, else the chip
     * (a link carries only one of them anyway).
     *
     * @param array<mixed> $difficultyTiers
     */
    public static function fromUserInput(
        bool $isMember,
        null|string $category = null,
        null|string $view = null,
        null|string $sort = null,
        null|string $query = null,
        null|string $brand = null,
        null|string $pieces = null,
        null|string $piecesMin = null,
        null|string $piecesMax = null,
        null|string $piecesWas = null,
        null|string $team = null,
        bool $onlyFirstTries = false,
        bool $onlyUnboxed = false,
        bool $onlyRelax = false,
        null|string $from = null,
        null|string $to = null,
        null|string $speed = null,
        null|string $speedComparison = null,
        null|string $speedUnit = null,
        array $difficultyTiers = [],
        null|int|string $page = null,
    ): self {
        $categoryValue = in_array($category, self::CATEGORIES, true) ? $category : null;
        $viewValue = PlayerResultsView::tryFrom((string) $view) ?? PlayerResultsView::Puzzles;
        $sortValue = PlayerResultsSort::tryFrom((string) $sort) ?? PlayerResultsSort::DEFAULT;

        $queryValue = trim((string) $query);
        $queryValue = $queryValue === '' ? null : mb_substr($queryValue, 0, self::MAX_QUERY_LENGTH);

        $brandValue = trim((string) $brand);
        $brandValue = $brandValue === '' || mb_strlen($brandValue) > self::MAX_BRAND_LENGTH ? null : $brandValue;

        $piecesValue = self::piecesRange($pieces, $piecesMin, $piecesMax, $piecesWas);
        $teamValue = $team !== null && Uuid::isValid($team) ? strtolower($team) : null;

        $dateFrom = self::parseDate($from);
        $dateTo = self::parseDate($to);

        if ($dateFrom !== null && $dateTo !== null && $dateFrom > $dateTo) {
            [$dateFrom, $dateTo] = [$dateTo, $dateFrom];
        }

        $speedValue = self::parseSpeed($speed);
        $speedComparisonValue = $speedComparison === self::SPEED_SLOWER ? self::SPEED_SLOWER : self::SPEED_FASTER;
        $speedUnitValue = $speedUnit === self::SPEED_UNIT_MINUTES ? self::SPEED_UNIT_MINUTES : self::SPEED_UNIT_PPM;
        $difficultyValue = DifficultyFilter::normalize($difficultyTiers);

        // Members-only filters and sorts: dropped, never an error - the page shows them locked
        if ($isMember === false) {
            $onlyFirstTries = false;
            $onlyUnboxed = false;
            $onlyRelax = false;
            $dateFrom = null;
            $dateTo = null;
            $speedValue = null;
            $difficultyValue = [];

            if ($sortValue->isMembersOnly()) {
                $sortValue = PlayerResultsSort::DEFAULT;
            }
        }

        // The speed's comparison and unit mean nothing without a speed
        if ($speedValue === null) {
            $speedComparisonValue = self::SPEED_FASTER;
            $speedUnitValue = self::SPEED_UNIT_PPM;
        }

        return new self(
            category: $categoryValue,
            view: $viewValue,
            sort: $sortValue,
            query: $queryValue,
            brand: $brandValue,
            pieces: $piecesValue,
            team: $teamValue,
            onlyFirstTries: $onlyFirstTries,
            onlyUnboxed: $onlyUnboxed,
            onlyRelax: $onlyRelax,
            dateFrom: $dateFrom,
            dateTo: $dateTo,
            speedValue: $speedValue,
            speedComparison: $speedComparisonValue,
            speedUnit: $speedUnitValue,
            difficultyTiers: $difficultyValue,
            page: self::parsePage($page),
        );
    }

    /**
     * The non-default values in the grammar's order - what links and the canonical form of the state carry.
     *
     * @return array<string, string|list<string>>
     */
    public function toQuery(): array
    {
        $query = [];

        if ($this->category !== null) {
            $query['category'] = $this->category;
        }

        if ($this->view !== PlayerResultsView::Puzzles) {
            $query['view'] = $this->view->value;
        }

        if ($this->sort !== PlayerResultsSort::DEFAULT) {
            $query['sort'] = $this->sort->value;
        }

        $query += $this->filterQuery();

        if ($this->page > 1) {
            $query['page'] = (string) $this->page;
        }

        return $query;
    }

    /**
     * The filters alone (search included), in the grammar's order
     *
     * @return array<string, string|list<string>>
     */
    public function filterQuery(): array
    {
        $query = [];

        if ($this->query !== null) {
            $query['q'] = $this->query;
        }

        if ($this->brand !== null) {
            $query['brand'] = $this->brand;
        }

        if ($this->pieces !== null) {
            $query['pieces'] = $this->pieces->toParam();
        }

        if ($this->team !== null) {
            $query['team'] = $this->team;
        }

        if ($this->onlyFirstTries) {
            $query['first'] = '1';
        }

        if ($this->onlyUnboxed) {
            $query['unboxed'] = '1';
        }

        if ($this->onlyRelax) {
            $query['relax'] = '1';
        }

        if ($this->dateFrom !== null) {
            $query['from'] = $this->dateFrom->format(self::DATE_FORMAT);
        }

        if ($this->dateTo !== null) {
            $query['to'] = $this->dateTo->format(self::DATE_FORMAT);
        }

        if ($this->speedValue !== null) {
            $query['speed'] = self::formatSpeed($this->speedValue);

            if ($this->speedComparison !== self::SPEED_FASTER) {
                $query['speed_cmp'] = $this->speedComparison;
            }

            if ($this->speedUnit !== self::SPEED_UNIT_PPM) {
                $query['speed_unit'] = $this->speedUnit;
            }
        }

        if ($this->difficultyTiers !== []) {
            $query['difficultyTiers'] = $this->difficultyTiers;
        }

        return $query;
    }

    public function hasFilters(): bool
    {
        return $this->filterQuery() !== [];
    }

    /**
     * One per removable chip: the search, each filter, each difficulty tier. The removal link is without($id).
     *
     * @return list<array{id: string, filter: string, value: string}>
     */
    public function chips(): array
    {
        $chips = [];

        foreach ($this->filterQuery() as $filter => $value) {
            if ($filter === 'speed_cmp' || $filter === 'speed_unit') {
                continue;
            }

            if (is_array($value)) {
                foreach ($value as $item) {
                    $chips[] = ['id' => $filter . ':' . $item, 'filter' => $filter, 'value' => $item];
                }

                continue;
            }

            $chips[] = ['id' => $filter, 'filter' => $filter, 'value' => $value];
        }

        return $chips;
    }

    public function activeFiltersCount(): int
    {
        return count($this->chips());
    }

    /**
     * Without one chip (chips()' id) - from page 1
     */
    public function without(string $chip): self
    {
        [$filter, $item] = array_pad(explode(':', $chip, 2), 2, null);

        return $this->copy(
            query: $filter === 'q' ? null : $this->query,
            brand: $filter === 'brand' ? null : $this->brand,
            pieces: $filter === 'pieces' ? null : $this->pieces,
            team: $filter === 'team' ? null : $this->team,
            onlyFirstTries: $filter === 'first' ? false : $this->onlyFirstTries,
            onlyUnboxed: $filter === 'unboxed' ? false : $this->onlyUnboxed,
            onlyRelax: $filter === 'relax' ? false : $this->onlyRelax,
            dateFrom: $filter === 'from' ? null : $this->dateFrom,
            dateTo: $filter === 'to' ? null : $this->dateTo,
            speedValue: $filter === 'speed' ? null : $this->speedValue,
            difficultyTiers: $filter === 'difficultyTiers'
                ? array_values(array_filter($this->difficultyTiers, static fn (string $tier): bool => $tier !== $item))
                : $this->difficultyTiers,
        );
    }

    /**
     * "Clear all": the same tab, view and sort without the search and the filters, from page 1
     */
    public function withoutFilters(): self
    {
        return $this->copy(
            query: null,
            brand: null,
            pieces: null,
            team: null,
            onlyFirstTries: false,
            onlyUnboxed: false,
            onlyRelax: false,
            dateFrom: null,
            dateTo: null,
            speedValue: null,
            difficultyTiers: [],
        );
    }

    /**
     * Another tab - null = the default tab (pass null for the default one, so its link carries no category)
     */
    public function withCategory(null|string $category): self
    {
        return $this->copy(category: in_array($category, self::CATEGORIES, true) ? $category : null);
    }

    public function withView(PlayerResultsView $view): self
    {
        return $this->copy(view: $view);
    }

    public function withSort(PlayerResultsSort $sort): self
    {
        return $this->copy(sort: $sort);
    }

    /**
     * The same list, more of its rows (paging keeps everything else)
     */
    public function withPage(int $page): self
    {
        return new self(
            category: $this->category,
            view: $this->view,
            sort: $this->sort,
            query: $this->query,
            brand: $this->brand,
            pieces: $this->pieces,
            team: $this->team,
            onlyFirstTries: $this->onlyFirstTries,
            onlyUnboxed: $this->onlyUnboxed,
            onlyRelax: $this->onlyRelax,
            dateFrom: $this->dateFrom,
            dateTo: $this->dateTo,
            speedValue: $this->speedValue,
            speedComparison: $this->speedComparison,
            speedUnit: $this->speedUnit,
            difficultyTiers: $this->difficultyTiers,
            page: self::parsePage($page),
        );
    }

    /**
     * Another list: everything not named stays, the page starts at 1
     *
     * @param list<string> $difficultyTiers
     */
    private function copy(
        null|false|string $category = false,
        null|PlayerResultsView $view = null,
        null|PlayerResultsSort $sort = null,
        null|false|string $query = false,
        null|false|string $brand = false,
        null|false|PiecesRange $pieces = false,
        null|false|string $team = false,
        null|bool $onlyFirstTries = null,
        null|bool $onlyUnboxed = null,
        null|bool $onlyRelax = null,
        null|false|DateTimeImmutable $dateFrom = false,
        null|false|DateTimeImmutable $dateTo = false,
        null|false|float $speedValue = false,
        null|array $difficultyTiers = null,
    ): self {
        $speed = $speedValue === false ? $this->speedValue : $speedValue;

        return new self(
            category: $category === false ? $this->category : $category,
            view: $view ?? $this->view,
            sort: $sort ?? $this->sort,
            query: $query === false ? $this->query : $query,
            brand: $brand === false ? $this->brand : $brand,
            pieces: $pieces === false ? $this->pieces : $pieces,
            team: $team === false ? $this->team : $team,
            onlyFirstTries: $onlyFirstTries ?? $this->onlyFirstTries,
            onlyUnboxed: $onlyUnboxed ?? $this->onlyUnboxed,
            onlyRelax: $onlyRelax ?? $this->onlyRelax,
            dateFrom: $dateFrom === false ? $this->dateFrom : $dateFrom,
            dateTo: $dateTo === false ? $this->dateTo : $dateTo,
            speedValue: $speed,
            speedComparison: $speed === null ? self::SPEED_FASTER : $this->speedComparison,
            speedUnit: $speed === null ? self::SPEED_UNIT_PPM : $this->speedUnit,
            difficultyTiers: $difficultyTiers ?? $this->difficultyTiers,
            page: 1,
        );
    }

    private static function piecesRange(null|string $pieces, null|string $piecesMin, null|string $piecesMax, null|string $piecesWas): null|PiecesRange
    {
        $bounds = PiecesRange::fromBounds(self::parseBound($piecesMin), self::parseBound($piecesMax));

        // Typed bounds win when they are new; bounds equal to what was applied are the form showing it back
        if ($bounds !== null && ($pieces === null || $bounds->toParam() !== PiecesRange::parse($piecesWas)?->toParam())) {
            return $bounds;
        }

        $range = PiecesRange::parse($pieces);

        return $range === null || $range->isUnbounded() ? null : $range;
    }

    private static function parseBound(null|string $value): null|int
    {
        $value = trim((string) $value);

        return preg_match('/^\d{1,6}$/', $value) === 1 ? (int) $value : null;
    }

    private static function parseDate(null|string $value): null|DateTimeImmutable
    {
        $value = trim((string) $value);

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1 || $value < self::MIN_DATE || $value > self::MAX_DATE) {
            return null;
        }

        $date = DateTimeImmutable::createFromFormat('!' . self::DATE_FORMAT, $value);

        // createFromFormat() rolls 2026-02-31 over to March - such a day does not exist
        if ($date === false || $date->format(self::DATE_FORMAT) !== $value) {
            return null;
        }

        return $date;
    }

    private static function parseSpeed(null|string $value): null|float
    {
        $value = str_replace(',', '.', trim((string) $value));

        if (preg_match('/^\d{1,6}(\.\d{1,3})?$/', $value) !== 1) {
            return null;
        }

        $speed = (float) $value;

        return $speed > 0 && $speed <= self::MAX_SPEED ? $speed : null;
    }

    private static function formatSpeed(float $speed): string
    {
        return rtrim(rtrim(number_format($speed, 3, '.', ''), '0'), '.');
    }

    private static function parsePage(null|int|string $page): int
    {
        if (is_string($page)) {
            $page = preg_match('/^\d{1,9}$/', trim($page)) === 1 ? (int) trim($page) : 1;
        }

        return max(1, min(self::MAX_PAGE, $page ?? 1));
    }

    private static function flag(mixed $value): bool
    {
        return in_array($value, ['1', 'on', 'true'], true);
    }

    private static function stringInput(mixed $value): null|string
    {
        if (is_string($value)) {
            return $value;
        }

        return is_int($value) || is_float($value) ? (string) $value : null;
    }
}
