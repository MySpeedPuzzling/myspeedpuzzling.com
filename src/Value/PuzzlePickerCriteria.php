<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

use Ramsey\Uuid\Uuid;
use Symfony\Component\HttpFoundation\Request;

/**
 * Normalized state of the puzzle picker ("What should I solve next?").
 *
 * The URL *is* the state (bookmarkable, shareable, back button works), so the
 * input can carry anything a crafted link can: unknown enum values, garbage in
 * `pieces[]`, mangled UUIDs, personal filters from a guest. Everything is
 * normalized here so the query stays graceful and the guest branch can never be
 * talked into personal data by a URL.
 *
 * Grammar of `pieces[]` values: `N` (exact), `A-B` (between), `A-` (at least),
 * `-B` (at most). The filter form additionally posts `pieces_min` / `pieces_max`,
 * which are folded into the same list, so the canonical URL only ever uses `pieces[]`.
 */
final readonly class PuzzlePickerCriteria
{
    public const int PICK_SIZE = 6;

    public const int MAX_PIECES_RANGES = 12;

    public const int MAX_BRANDS = 20;

    public const int MAX_PIECES = 100000;

    /** @var list<int|string> */
    public const array PIECES_CHIPS = [54, 100, 150, 200, 300, 500, 750, 1000, 1500, '2000-'];

    private const string SEED_PATTERN = '/^[a-z0-9]{4,16}$/';

    /**
     * @param list<array{null|int, null|int}> $pieces
     * @param list<string> $brandIds
     */
    private function __construct(
        public PuzzlePickerSource $source,
        public PuzzlePickerSolved $solved,
        public array $pieces,
        public array $brandIds,
        public bool $includeLentOut,
        public null|string $seed,
        public bool $isAuthenticated,
    ) {
    }

    public static function fromRequest(Request $request, bool $isAuthenticated): self
    {
        $query = $request->query->all();

        return self::fromUserInput(
            source: is_string($query['source'] ?? null) ? $query['source'] : null,
            solved: is_string($query['solved'] ?? null) ? $query['solved'] : null,
            pieces: self::listInput($query['pieces'] ?? null),
            piecesMin: is_string($query['pieces_min'] ?? null) ? $query['pieces_min'] : null,
            piecesMax: is_string($query['pieces_max'] ?? null) ? $query['pieces_max'] : null,
            brandIds: self::listInput($query['brand'] ?? null),
            includeLentOut: ($query['lent'] ?? null) === '1',
            seed: is_string($query['seed'] ?? null) ? $query['seed'] : null,
            isAuthenticated: $isAuthenticated,
        );
    }

    /**
     * @param array<mixed> $pieces
     * @param array<mixed> $brandIds
     */
    public static function fromUserInput(
        null|string $source,
        null|string $solved,
        array $pieces,
        null|string $piecesMin,
        null|string $piecesMax,
        array $brandIds,
        bool $includeLentOut,
        null|string $seed,
        bool $isAuthenticated,
    ): self {
        $defaultSource = self::defaultSourceFor($isAuthenticated);
        $sourceValue = $source !== null ? PuzzlePickerSource::tryFrom($source) ?? $defaultSource : $defaultSource;
        $solvedValue = $solved !== null ? PuzzlePickerSolved::tryFrom($solved) ?? PuzzlePickerSolved::Any : PuzzlePickerSolved::Any;

        // Guests have no shelf and no history: personal filters are dropped
        // server-side, so a crafted URL cannot reach the personal branches.
        if ($isAuthenticated === false) {
            $sourceValue = PuzzlePickerSource::Any;
            $solvedValue = PuzzlePickerSolved::Any;
            $includeLentOut = false;
        }

        return new self(
            source: $sourceValue,
            solved: $solvedValue,
            pieces: self::normalizePieces($pieces, $piecesMin, $piecesMax),
            brandIds: self::normalizeBrandIds($brandIds),
            includeLentOut: $includeLentOut,
            seed: $seed !== null && preg_match(self::SEED_PATTERN, $seed) === 1 ? $seed : null,
            isAuthenticated: $isAuthenticated,
        );
    }

    public function withSeed(null|string $seed): self
    {
        return new self(
            source: $this->source,
            solved: $this->solved,
            pieces: $this->pieces,
            brandIds: $this->brandIds,
            includeLentOut: $this->includeLentOut,
            seed: $seed,
            isAuthenticated: $this->isAuthenticated,
        );
    }

    public function isDefault(): bool
    {
        return $this->source === $this->defaultSource()
            && $this->solved === PuzzlePickerSolved::Any
            && $this->pieces === []
            && $this->brandIds === []
            && $this->includeLentOut === false;
    }

    /**
     * True when the criteria touch the player's own shelf or history — those
     * are the filters a guest cannot use and the ones an empty result should
     * suggest loosening first.
     */
    public function hasPersonalFilters(): bool
    {
        return $this->source !== PuzzlePickerSource::Any
            || $this->solved !== PuzzlePickerSolved::Any
            || $this->includeLentOut;
    }

    /**
     * Query parameters reproducing this state. Only non-default values are
     * emitted, so bare defaults produce an empty array (the canonical URL).
     *
     * @return array<string, mixed>
     */
    public function toQueryParams(null|string $seedOverride = null): array
    {
        $parameters = [];

        if ($this->source !== $this->defaultSource()) {
            $parameters['source'] = $this->source->value;
        }

        if ($this->solved !== PuzzlePickerSolved::Any) {
            $parameters['solved'] = $this->solved->value;
        }

        if ($this->pieces !== []) {
            $parameters['pieces'] = $this->piecesValues();
        }

        if ($this->brandIds !== []) {
            $parameters['brand'] = $this->brandIds;
        }

        if ($this->includeLentOut) {
            $parameters['lent'] = '1';
        }

        $seed = $seedOverride ?? $this->seed;

        if ($seed !== null) {
            $parameters['seed'] = $seed;
        }

        return $parameters;
    }

    /**
     * Chips shown above the card, each with the query string that drops it.
     *
     * @return list<PuzzlePickerActiveFilter>
     */
    public function activeFilters(): array
    {
        $filters = [];

        if ($this->source !== PuzzlePickerSource::Any) {
            $filters[] = new PuzzlePickerActiveFilter(
                key: 'source',
                type: 'source',
                translationKey: 'puzzle_picker.chips.source.' . $this->source->value,
                translationParameters: [],
                queryParametersWithoutThis: $this->with(source: PuzzlePickerSource::Any)->toQueryParams(),
            );
        }

        if ($this->solved !== PuzzlePickerSolved::Any) {
            $filters[] = new PuzzlePickerActiveFilter(
                key: 'solved',
                type: 'solved',
                translationKey: 'puzzle_picker.chips.solved.' . $this->solved->value,
                translationParameters: [],
                queryParametersWithoutThis: $this->with(solved: PuzzlePickerSolved::Any)->toQueryParams(),
            );
        }

        foreach ($this->pieces as $index => [$min, $max]) {
            $remaining = $this->pieces;
            unset($remaining[$index]);

            [$translationKey, $translationParameters] = match (true) {
                $min !== null && $max !== null && $min === $max => ['puzzle_picker.chips.pieces.exact', ['%count%' => $min]],
                $min !== null && $max !== null => ['puzzle_picker.chips.pieces.between', ['%min%' => $min, '%max%' => $max]],
                $min !== null => ['puzzle_picker.chips.pieces.at_least', ['%min%' => $min]],
                default => ['puzzle_picker.chips.pieces.at_most', ['%max%' => (int) $max]],
            };

            $filters[] = new PuzzlePickerActiveFilter(
                key: 'pieces:' . self::formatPiecesRange([$min, $max]),
                type: 'pieces',
                translationKey: $translationKey,
                translationParameters: $translationParameters,
                queryParametersWithoutThis: $this->with(pieces: array_values($remaining))->toQueryParams(),
            );
        }

        foreach ($this->brandIds as $brandId) {
            $filters[] = new PuzzlePickerActiveFilter(
                key: 'brand:' . $brandId,
                type: 'brand',
                translationKey: 'puzzle_picker.chips.brand',
                translationParameters: [],
                queryParametersWithoutThis: $this->with(brandIds: array_values(array_diff($this->brandIds, [$brandId])))->toQueryParams(),
                value: $brandId,
            );
        }

        if ($this->includeLentOut) {
            $filters[] = new PuzzlePickerActiveFilter(
                key: 'lent',
                type: 'lent',
                translationKey: 'puzzle_picker.chips.include_lent_out',
                translationParameters: [],
                queryParametersWithoutThis: $this->with(includeLentOut: false)->toQueryParams(),
            );
        }

        return $filters;
    }

    /**
     * Pieces ranges in URL grammar (`500`, `300-499`, `2000-`, `-199`).
     *
     * @return list<string>
     */
    public function piecesValues(): array
    {
        return array_map(self::formatPiecesRange(...), $this->pieces);
    }

    /**
     * The first pieces range that is not one of the preset chips — what the
     * custom min/max inputs of the filter form should show.
     *
     * @return null|array{null|int, null|int}
     */
    public function customPiecesRange(): null|array
    {
        $chipValues = array_map(strval(...), self::PIECES_CHIPS);

        foreach ($this->pieces as $range) {
            if (in_array(self::formatPiecesRange($range), $chipValues, true) === false) {
                return $range;
            }
        }

        return null;
    }

    public function defaultSource(): PuzzlePickerSource
    {
        return self::defaultSourceFor($this->isAuthenticated);
    }

    public static function defaultSourceFor(bool $isAuthenticated): PuzzlePickerSource
    {
        return $isAuthenticated ? PuzzlePickerSource::Mine : PuzzlePickerSource::Any;
    }

    /**
     * @param array{null|int, null|int} $range
     */
    public static function formatPiecesRange(array $range): string
    {
        [$min, $max] = $range;

        if ($min !== null && $max !== null && $min === $max) {
            return (string) $min;
        }

        return ($min ?? '') . '-' . ($max ?? '');
    }

    /**
     * @param list<array{null|int, null|int}>|null $pieces
     * @param list<string>|null $brandIds
     */
    private function with(
        null|PuzzlePickerSource $source = null,
        null|PuzzlePickerSolved $solved = null,
        null|array $pieces = null,
        null|array $brandIds = null,
        null|bool $includeLentOut = null,
    ): self {
        return new self(
            source: $source ?? $this->source,
            solved: $solved ?? $this->solved,
            pieces: $pieces ?? $this->pieces,
            brandIds: $brandIds ?? $this->brandIds,
            includeLentOut: $includeLentOut ?? $this->includeLentOut,
            seed: $this->seed,
            isAuthenticated: $this->isAuthenticated,
        );
    }

    /**
     * Repeated query parameters may arrive as `pieces[]=…` (array) or as a
     * single `pieces=…` (string); anything else is ignored.
     *
     * @return array<mixed>
     */
    private static function listInput(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (is_string($value)) {
            return [$value];
        }

        return [];
    }

    /**
     * @param array<mixed> $pieces
     *
     * @return list<array{null|int, null|int}>
     */
    private static function normalizePieces(array $pieces, null|string $piecesMin, null|string $piecesMax): array
    {
        $ranges = [];

        foreach ($pieces as $value) {
            if (is_string($value) === false) {
                continue;
            }

            $range = self::parsePiecesRange($value);

            if ($range !== null) {
                $ranges[self::formatPiecesRange($range)] = $range;
            }
        }

        $customRange = self::parsePiecesRange(trim($piecesMin ?? '') . '-' . trim($piecesMax ?? ''));

        if ($customRange !== null) {
            $ranges[self::formatPiecesRange($customRange)] = $customRange;
        }

        return array_slice(array_values($ranges), 0, self::MAX_PIECES_RANGES);
    }

    /**
     * @return null|array{null|int, null|int}
     */
    private static function parsePiecesRange(string $value): null|array
    {
        $value = trim($value);

        if (preg_match('/^(\d{1,6})$/', $value, $matches) === 1) {
            $count = (int) $matches[1];

            return self::isValidPiecesCount($count) ? [$count, $count] : null;
        }

        if (preg_match('/^(\d{0,6})-(\d{0,6})$/', $value, $matches) !== 1) {
            return null;
        }

        $min = $matches[1] !== '' ? (int) $matches[1] : null;
        $max = $matches[2] !== '' ? (int) $matches[2] : null;

        if ($min === null && $max === null) {
            return null;
        }

        if (($min !== null && self::isValidPiecesCount($min) === false) || ($max !== null && self::isValidPiecesCount($max) === false)) {
            return null;
        }

        if ($min !== null && $max !== null && $min > $max) {
            [$min, $max] = [$max, $min];
        }

        return [$min, $max];
    }

    private static function isValidPiecesCount(int $count): bool
    {
        return $count >= 1 && $count <= self::MAX_PIECES;
    }

    /**
     * @param array<mixed> $brandIds
     *
     * @return list<string>
     */
    private static function normalizeBrandIds(array $brandIds): array
    {
        $normalized = [];

        foreach ($brandIds as $brandId) {
            if (is_string($brandId) && Uuid::isValid($brandId) && in_array($brandId, $normalized, true) === false) {
                $normalized[] = $brandId;
            }
        }

        return array_slice($normalized, 0, self::MAX_BRANDS);
    }
}
