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
 * `pieces[]`, mangled UUIDs, personal filters from a guest, insights filters
 * from a non-member. Everything is normalized here so the query stays graceful
 * and neither the guest branch nor a non-member can be talked into personal or
 * members-only data by a URL — the same server-side rule as PuzzleSearchCriteria.
 *
 * Grammar of `pieces[]` values: `N` (exact), `A-B` (between), `A-` (at least),
 * `-B` (at most). The filter form additionally posts `pieces_min` / `pieces_max`,
 * which are folded into the same list, so the canonical URL only ever uses `pieces[]`.
 *
 * Insights layer (members): `difficulty[]` (tiers 1–6), `gap` + `gap_min` (my
 * fastest vs. my prediction, minutes), `order` (`gap_slower` / `gap_faster`) and
 * `predicted_max` — the "I have about N minutes" budget, which is free for
 * everyone but switches engine: my predicted time for members with predictions,
 * the community solo average otherwise (see usesPersonalPrediction()).
 */
final readonly class PuzzlePickerCriteria
{
    public const int PICK_SIZE = 6;

    public const int MAX_PIECES_RANGES = 12;

    public const int MAX_BRANDS = 20;

    public const int MAX_PIECES = 100000;

    public const int MIN_GAP_MINUTES = 1;

    public const int MAX_GAP_MINUTES = 600;

    public const int MIN_PREDICTED_MINUTES = 5;

    public const int MAX_PREDICTED_MINUTES = 1440;

    /** @var list<int|string> */
    public const array PIECES_CHIPS = [54, 100, 150, 200, 300, 500, 750, 1000, 1500, '2000-'];

    private const string SEED_PATTERN = '/^[a-z0-9]{4,16}$/';

    /**
     * @param list<array{null|int, null|int}> $pieces
     * @param list<string> $brandIds
     * @param list<int> $difficultyTiers
     */
    private function __construct(
        public PuzzlePickerSource $source,
        public PuzzlePickerSolved $solved,
        public array $pieces,
        public array $brandIds,
        public bool $includeLentOut,
        public array $difficultyTiers,
        public null|PuzzlePickerGap $gap,
        public null|int $gapMinMinutes,
        public null|int $predictedMaxMinutes,
        public PuzzlePickerOrder $order,
        public null|string $seed,
        public bool $isAuthenticated,
        public bool $insightsAllowed,
        public bool $predictionsAllowed,
    ) {
    }

    /**
     * @param bool $insightsAllowed Active membership: difficulty tiers
     * @param bool $predictionsAllowed Active membership without the time-predictions opt-out: gap filter, gap orders, personal time budget
     */
    public static function fromRequest(Request $request, bool $isAuthenticated, bool $insightsAllowed = false, bool $predictionsAllowed = false): self
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
            difficultyTiers: self::listInput($query['difficulty'] ?? null),
            gap: is_string($query['gap'] ?? null) ? $query['gap'] : null,
            gapMinMinutes: is_string($query['gap_min'] ?? null) ? $query['gap_min'] : null,
            predictedMaxMinutes: is_string($query['predicted_max'] ?? null) ? $query['predicted_max'] : null,
            order: is_string($query['order'] ?? null) ? $query['order'] : null,
            seed: is_string($query['seed'] ?? null) ? $query['seed'] : null,
            isAuthenticated: $isAuthenticated,
            insightsAllowed: $insightsAllowed,
            predictionsAllowed: $predictionsAllowed,
        );
    }

    /**
     * @param array<mixed> $pieces
     * @param array<mixed> $brandIds
     * @param array<mixed> $difficultyTiers
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
        array $difficultyTiers = [],
        null|string $gap = null,
        null|string $gapMinMinutes = null,
        null|string $predictedMaxMinutes = null,
        null|string $order = null,
        bool $insightsAllowed = false,
        bool $predictionsAllowed = false,
    ): self {
        $defaultSource = self::defaultSourceFor($isAuthenticated);
        $sourceValue = $source !== null ? PuzzlePickerSource::tryFrom($source) ?? $defaultSource : $defaultSource;
        $solvedValue = $solved !== null ? PuzzlePickerSolved::tryFrom($solved) ?? PuzzlePickerSolved::Any : PuzzlePickerSolved::Any;
        $gapValue = $gap !== null ? PuzzlePickerGap::tryFrom($gap) : null;
        $orderValue = $order !== null ? PuzzlePickerOrder::tryFrom($order) ?? PuzzlePickerOrder::Random : PuzzlePickerOrder::Random;
        $gapMinValue = $gapValue !== null ? self::parseMinutes($gapMinMinutes, self::MIN_GAP_MINUTES, self::MAX_GAP_MINUTES) : null;
        $predictedMaxValue = self::parseMinutes($predictedMaxMinutes, self::MIN_PREDICTED_MINUTES, self::MAX_PREDICTED_MINUTES);

        // Guests have no shelf and no history: personal filters are dropped
        // server-side, so a crafted URL cannot reach the personal branches.
        if ($isAuthenticated === false) {
            $sourceValue = PuzzlePickerSource::Any;
            $solvedValue = PuzzlePickerSolved::Any;
            $includeLentOut = false;
            $insightsAllowed = false;
        }

        // Insights are members-only; predictions additionally respect the
        // player's opt-out. Non-eligible values are dropped, not errored on.
        // The time budget survives — it just runs on the community engine.
        if ($insightsAllowed === false) {
            $difficultyTiers = [];
            $predictionsAllowed = false;
        }

        if ($predictionsAllowed === false) {
            $gapValue = null;
            $gapMinValue = null;
            $orderValue = PuzzlePickerOrder::Random;
        }

        return new self(
            source: $sourceValue,
            solved: $solvedValue,
            pieces: self::normalizePieces($pieces, $piecesMin, $piecesMax),
            brandIds: self::normalizeBrandIds($brandIds),
            includeLentOut: $includeLentOut,
            difficultyTiers: self::normalizeDifficultyTiers($difficultyTiers),
            gap: $gapValue,
            gapMinMinutes: $gapMinValue,
            predictedMaxMinutes: $predictedMaxValue,
            order: $orderValue,
            seed: $seed !== null && preg_match(self::SEED_PATTERN, $seed) === 1 ? $seed : null,
            isAuthenticated: $isAuthenticated,
            insightsAllowed: $insightsAllowed,
            predictionsAllowed: $predictionsAllowed,
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
            difficultyTiers: $this->difficultyTiers,
            gap: $this->gap,
            gapMinMinutes: $this->gapMinMinutes,
            predictedMaxMinutes: $this->predictedMaxMinutes,
            order: $this->order,
            seed: $seed,
            isAuthenticated: $this->isAuthenticated,
            insightsAllowed: $this->insightsAllowed,
            predictionsAllowed: $this->predictionsAllowed,
        );
    }

    public function isDefault(): bool
    {
        return $this->source === $this->defaultSource()
            && $this->solved === PuzzlePickerSolved::Any
            && $this->pieces === []
            && $this->brandIds === []
            && $this->includeLentOut === false
            && $this->difficultyTiers === []
            && $this->gap === null
            && $this->predictedMaxMinutes === null
            && $this->order === PuzzlePickerOrder::Random;
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
            || $this->includeLentOut
            || $this->gap !== null
            || $this->order->isGapOrder();
    }

    /**
     * Which engine the "I have about N minutes" budget runs on: my predicted
     * time (members with predictions) or the community solo average.
     */
    public function usesPersonalPrediction(): bool
    {
        return $this->predictionsAllowed;
    }

    /**
     * True when the query has to compute my predictions before sampling — the
     * gap filter, a gap order, or a personal time budget.
     */
    public function needsPredictions(): bool
    {
        return $this->predictionsAllowed
            && ($this->gap !== null || $this->order->isGapOrder() || $this->predictedMaxMinutes !== null);
    }

    /**
     * Minimum gap in seconds the gap filter asks for: the "by at least N min"
     * input, or one minute so a 0-second "gap" never counts.
     */
    public function gapMinSeconds(): int
    {
        return ($this->gapMinMinutes ?? self::MIN_GAP_MINUTES) * 60;
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

        if ($this->difficultyTiers !== []) {
            $parameters['difficulty'] = array_map(strval(...), $this->difficultyTiers);
        }

        if ($this->gap !== null) {
            $parameters['gap'] = $this->gap->value;

            if ($this->gapMinMinutes !== null) {
                $parameters['gap_min'] = (string) $this->gapMinMinutes;
            }
        }

        if ($this->predictedMaxMinutes !== null) {
            $parameters['predicted_max'] = (string) $this->predictedMaxMinutes;
        }

        if ($this->order !== PuzzlePickerOrder::Random) {
            $parameters['order'] = $this->order->value;
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

        if ($this->predictedMaxMinutes !== null) {
            $filters[] = new PuzzlePickerActiveFilter(
                key: 'predicted_max',
                type: 'predicted_max',
                translationKey: 'puzzle_picker.chips.predicted_max',
                translationParameters: ['%minutes%' => $this->predictedMaxMinutes],
                queryParametersWithoutThis: $this->with(clearPredictedMax: true)->toQueryParams(),
            );
        }

        foreach ($this->difficultyTiers as $tier) {
            $difficultyTier = DifficultyTier::from($tier);

            $filters[] = new PuzzlePickerActiveFilter(
                key: 'difficulty:' . $tier,
                type: 'difficulty',
                translationKey: 'puzzle_intelligence.difficulty.tiers.' . strtolower($difficultyTier->name),
                translationParameters: [],
                queryParametersWithoutThis: $this->with(difficultyTiers: array_values(array_diff($this->difficultyTiers, [$tier])))->toQueryParams(),
                value: (string) $tier,
            );
        }

        if ($this->gap !== null) {
            $filters[] = new PuzzlePickerActiveFilter(
                key: 'gap',
                type: 'gap',
                translationKey: 'puzzle_picker.chips.gap.' . $this->gap->value . ($this->gapMinMinutes !== null ? '_by' : ''),
                translationParameters: ['%minutes%' => $this->gapMinMinutes ?? 0],
                queryParametersWithoutThis: $this->with(clearGap: true)->toQueryParams(),
            );
        }

        if ($this->order !== PuzzlePickerOrder::Random) {
            $filters[] = new PuzzlePickerActiveFilter(
                key: 'order',
                type: 'order',
                translationKey: 'puzzle_picker.chips.order.' . $this->order->value,
                translationParameters: [],
                queryParametersWithoutThis: $this->with(order: PuzzlePickerOrder::Random)->toQueryParams(),
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
     * Copy with some fields replaced. Nullable fields (gap, budget) cannot be
     * "set to null" through a null argument, hence the explicit clear flags.
     *
     * @param list<array{null|int, null|int}>|null $pieces
     * @param list<string>|null $brandIds
     * @param list<int>|null $difficultyTiers
     */
    private function with(
        null|PuzzlePickerSource $source = null,
        null|PuzzlePickerSolved $solved = null,
        null|array $pieces = null,
        null|array $brandIds = null,
        null|bool $includeLentOut = null,
        null|array $difficultyTiers = null,
        null|PuzzlePickerOrder $order = null,
        bool $clearGap = false,
        bool $clearPredictedMax = false,
    ): self {
        return new self(
            source: $source ?? $this->source,
            solved: $solved ?? $this->solved,
            pieces: $pieces ?? $this->pieces,
            brandIds: $brandIds ?? $this->brandIds,
            includeLentOut: $includeLentOut ?? $this->includeLentOut,
            difficultyTiers: $difficultyTiers ?? $this->difficultyTiers,
            gap: $clearGap ? null : $this->gap,
            gapMinMinutes: $clearGap ? null : $this->gapMinMinutes,
            predictedMaxMinutes: $clearPredictedMax ? null : $this->predictedMaxMinutes,
            order: $order ?? $this->order,
            seed: $this->seed,
            isAuthenticated: $this->isAuthenticated,
            insightsAllowed: $this->insightsAllowed,
            predictionsAllowed: $this->predictionsAllowed,
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

    /**
     * Difficulty tiers 1–6 (DifficultyTier values), deduplicated and sorted;
     * anything else is dropped.
     *
     * @param array<mixed> $difficultyTiers
     *
     * @return list<int>
     */
    private static function normalizeDifficultyTiers(array $difficultyTiers): array
    {
        $tiers = [];

        foreach ($difficultyTiers as $tier) {
            if ((is_int($tier) || (is_string($tier) && preg_match('/^\d{1,2}$/', trim($tier)) === 1)) && DifficultyTier::tryFrom((int) $tier) !== null) {
                $tiers[(int) $tier] = (int) $tier;
            }
        }

        sort($tiers);

        return $tiers;
    }

    /**
     * Whole minutes within [min, max]; anything else (blank, text, out of range) is null.
     */
    private static function parseMinutes(null|string $value, int $min, int $max): null|int
    {
        if ($value === null || preg_match('/^\d{1,5}$/', trim($value)) !== 1) {
            return null;
        }

        $minutes = (int) trim($value);

        return $minutes >= $min && $minutes <= $max ? $minutes : null;
    }
}
