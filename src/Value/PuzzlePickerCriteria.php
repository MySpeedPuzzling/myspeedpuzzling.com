<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\Collection;
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
 * My history: the solved state is one solve-count range — `solved=never` is
 * [0, 0], `solved=before` is [1, ∞[, and `solved_min` / `solved_max` refine or
 * override it bound by bound (the canonical URL uses `solved=` for the two
 * named shapes and the numeric bounds otherwise); `since` + `since_unit`
 * (`d`|`w`|`m`) keep puzzles I have not solved for that long — never-solved
 * puzzles included unless `since_require_solved=1`; `my_time` (fastest /
 * latest / first) + `my_time_op` (`lt`|`gt`) + `my_time_minutes` compare one
 * of my solo times with a threshold.
 *
 * Puzzle: `community` (`few`|`rated`|`popular`) on the number of solo solves the
 * community has on the puzzle.
 *
 * Insights layer (members): `difficulty[]` (tiers 1–6), `gap` + `gap_min` (my
 * fastest vs. my prediction, minutes), `order` (`gap_slower` / `gap_faster`),
 * `collections[]` (only these collections of mine — a uuid or the system
 * collection sentinel; implies source `mine`) and `predicted_max` — the "I have
 * about N minutes" budget, which is free for everyone but switches engine: my
 * predicted time for members with predictions, the community solo average
 * otherwise (see usesPersonalPrediction()).
 */
final readonly class PuzzlePickerCriteria
{
    public const int PICK_SIZE = 6;

    public const int MAX_PIECES_RANGES = 12;

    public const int MAX_BRANDS = 20;

    public const int MAX_COLLECTIONS = 20;

    public const int MAX_PIECES = 100000;

    public const int MAX_SOLVE_COUNT = 999;

    public const int MAX_SINCE_AMOUNT = 999;

    public const int MIN_MY_TIME_MINUTES = 1;

    public const int MAX_MY_TIME_MINUTES = 1440;

    public const int MIN_GAP_MINUTES = 1;

    public const int MAX_GAP_MINUTES = 600;

    public const int MIN_PREDICTED_MINUTES = 5;

    public const int MAX_PREDICTED_MINUTES = 1440;

    /** @var list<int|string> */
    public const array PIECES_CHIPS = [54, 100, 150, 200, 300, 500, 750, 1000, 1500, '2000-'];

    private const string SEED_PATTERN = '/^[a-z0-9]{4,16}$/';

    /**
     * Named shape of the solve-count range: Never = [0, 0], Before = [1, ∞[,
     * Any = everything else (the radio the filter form shows as checked).
     */
    public PuzzlePickerSolved $solved;

    /**
     * @param int $solvedMin Lower bound of my solve count (0 = no bound)
     * @param null|int $solvedMax Upper bound of my solve count (null = no bound)
     * @param list<array{null|int, null|int}> $pieces
     * @param list<string> $brandIds
     * @param list<string> $collectionIds Collection uuids and/or Collection::SYSTEM_ID
     * @param list<int> $difficultyTiers
     */
    private function __construct(
        public PuzzlePickerSource $source,
        public int $solvedMin,
        public null|int $solvedMax,
        public null|int $sinceAmount,
        public PuzzlePickerSinceUnit $sinceUnit,
        public bool $sinceRequireSolved,
        public null|PuzzlePickerMyTime $myTime,
        public PuzzlePickerMyTimeOperator $myTimeOperator,
        public null|int $myTimeMinutes,
        public array $pieces,
        public array $brandIds,
        public bool $includeLentOut,
        public array $collectionIds,
        public null|PuzzlePickerCommunity $community,
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
        $this->solved = self::solvedShapeOf($solvedMin, $solvedMax);
    }

    /**
     * @param bool $insightsAllowed Active membership: difficulty tiers, specific collections
     * @param bool $predictionsAllowed Active membership without the time-predictions opt-out: gap filter, gap orders, personal time budget
     */
    public static function fromRequest(Request $request, bool $isAuthenticated, bool $insightsAllowed = false, bool $predictionsAllowed = false): self
    {
        return self::fromQuery($request->query->all(), $isAuthenticated, $insightsAllowed, $predictionsAllowed);
    }

    /**
     * Same as fromRequest() for a raw query array — the shape stored in the
     * session by "remember my last filters".
     *
     * @param array<mixed> $query
     */
    public static function fromQuery(array $query, bool $isAuthenticated, bool $insightsAllowed = false, bool $predictionsAllowed = false): self
    {
        return self::fromUserInput(
            source: self::stringInput($query['source'] ?? null),
            solved: self::stringInput($query['solved'] ?? null),
            pieces: self::listInput($query['pieces'] ?? null),
            piecesMin: self::stringInput($query['pieces_min'] ?? null),
            piecesMax: self::stringInput($query['pieces_max'] ?? null),
            brandIds: self::listInput($query['brand'] ?? null),
            includeLentOut: ($query['lent'] ?? null) === '1',
            seed: self::stringInput($query['seed'] ?? null),
            isAuthenticated: $isAuthenticated,
            solvedMin: self::stringInput($query['solved_min'] ?? null),
            solvedMax: self::stringInput($query['solved_max'] ?? null),
            since: self::stringInput($query['since'] ?? null),
            sinceUnit: self::stringInput($query['since_unit'] ?? null),
            sinceRequireSolved: ($query['since_require_solved'] ?? null) === '1',
            myTime: self::stringInput($query['my_time'] ?? null),
            myTimeOperator: self::stringInput($query['my_time_op'] ?? null),
            myTimeMinutes: self::stringInput($query['my_time_minutes'] ?? null),
            collectionIds: self::listInput($query['collections'] ?? null),
            community: self::stringInput($query['community'] ?? null),
            difficultyTiers: self::listInput($query['difficulty'] ?? null),
            gap: self::stringInput($query['gap'] ?? null),
            gapMinMinutes: self::stringInput($query['gap_min'] ?? null),
            predictedMaxMinutes: self::stringInput($query['predicted_max'] ?? null),
            order: self::stringInput($query['order'] ?? null),
            insightsAllowed: $insightsAllowed,
            predictionsAllowed: $predictionsAllowed,
        );
    }

    /**
     * @param array<mixed> $pieces
     * @param array<mixed> $brandIds
     * @param array<mixed> $collectionIds
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
        null|string $solvedMin = null,
        null|string $solvedMax = null,
        null|string $since = null,
        null|string $sinceUnit = null,
        bool $sinceRequireSolved = false,
        null|string $myTime = null,
        null|string $myTimeOperator = null,
        null|string $myTimeMinutes = null,
        array $collectionIds = [],
        null|string $community = null,
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
        [$solvedMinValue, $solvedMaxValue] = self::normalizeSolveCountRange($solved, $solvedMin, $solvedMax);
        $sinceAmountValue = self::parseInt($since, 1, self::MAX_SINCE_AMOUNT);
        $sinceUnitValue = $sinceUnit !== null ? PuzzlePickerSinceUnit::tryFrom($sinceUnit) ?? PuzzlePickerSinceUnit::Day : PuzzlePickerSinceUnit::Day;
        $myTimeValue = $myTime !== null ? PuzzlePickerMyTime::tryFrom($myTime) : null;
        $myTimeOperatorValue = $myTimeOperator !== null ? PuzzlePickerMyTimeOperator::tryFrom($myTimeOperator) ?? PuzzlePickerMyTimeOperator::Under : PuzzlePickerMyTimeOperator::Under;
        $myTimeMinutesValue = self::parseInt($myTimeMinutes, self::MIN_MY_TIME_MINUTES, self::MAX_MY_TIME_MINUTES);
        $communityValue = $community !== null ? PuzzlePickerCommunity::tryFrom($community) : null;
        $gapValue = $gap !== null ? PuzzlePickerGap::tryFrom($gap) : null;
        $orderValue = $order !== null ? PuzzlePickerOrder::tryFrom($order) ?? PuzzlePickerOrder::Random : PuzzlePickerOrder::Random;
        $gapMinValue = $gapValue !== null ? self::parseInt($gapMinMinutes, self::MIN_GAP_MINUTES, self::MAX_GAP_MINUTES) : null;
        $predictedMaxValue = self::parseInt($predictedMaxMinutes, self::MIN_PREDICTED_MINUTES, self::MAX_PREDICTED_MINUTES);

        // The "my time" filter needs both the metric and the threshold; the
        // "since" checkbox is meaningless without a period.
        if ($myTimeValue === null || $myTimeMinutesValue === null) {
            $myTimeValue = null;
            $myTimeMinutesValue = null;
            $myTimeOperatorValue = PuzzlePickerMyTimeOperator::Under;
        }

        if ($sinceAmountValue === null) {
            $sinceUnitValue = PuzzlePickerSinceUnit::Day;
            $sinceRequireSolved = false;
        }

        // Guests have no shelf and no history: personal filters are dropped
        // server-side, so a crafted URL cannot reach the personal branches.
        if ($isAuthenticated === false) {
            $sourceValue = PuzzlePickerSource::Any;
            $solvedMinValue = 0;
            $solvedMaxValue = null;
            $sinceAmountValue = null;
            $sinceUnitValue = PuzzlePickerSinceUnit::Day;
            $sinceRequireSolved = false;
            $myTimeValue = null;
            $myTimeOperatorValue = PuzzlePickerMyTimeOperator::Under;
            $myTimeMinutesValue = null;
            $includeLentOut = false;
            $insightsAllowed = false;
        }

        // Insights are members-only; predictions additionally respect the
        // player's opt-out. Non-eligible values are dropped, not errored on.
        // The time budget survives — it just runs on the community engine.
        if ($insightsAllowed === false) {
            $difficultyTiers = [];
            $collectionIds = [];
            $predictionsAllowed = false;
        }

        if ($predictionsAllowed === false) {
            $gapValue = null;
            $gapMinValue = null;
            $orderValue = PuzzlePickerOrder::Random;
        }

        $collectionIdsValue = self::normalizeCollectionIds($collectionIds);

        // Specific collections are a subset of my shelf
        if ($collectionIdsValue !== []) {
            $sourceValue = PuzzlePickerSource::Mine;
        }

        return new self(
            source: $sourceValue,
            solvedMin: $solvedMinValue,
            solvedMax: $solvedMaxValue,
            sinceAmount: $sinceAmountValue,
            sinceUnit: $sinceUnitValue,
            sinceRequireSolved: $sinceRequireSolved,
            myTime: $myTimeValue,
            myTimeOperator: $myTimeOperatorValue,
            myTimeMinutes: $myTimeMinutesValue,
            pieces: self::normalizePieces($pieces, $piecesMin, $piecesMax),
            brandIds: self::normalizeBrandIds($brandIds),
            includeLentOut: $includeLentOut,
            collectionIds: $collectionIdsValue,
            community: $communityValue,
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
        return $this->with(seed: $seed, replaceSeed: true);
    }

    public function isDefault(): bool
    {
        return $this->source === $this->defaultSource()
            && $this->solvedMin === 0
            && $this->solvedMax === null
            && $this->sinceAmount === null
            && $this->myTime === null
            && $this->pieces === []
            && $this->brandIds === []
            && $this->includeLentOut === false
            && $this->collectionIds === []
            && $this->community === null
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
            || $this->solvedMin !== 0
            || $this->solvedMax !== null
            || $this->sinceAmount !== null
            || $this->myTime !== null
            || $this->includeLentOut
            || $this->collectionIds !== []
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
     * Threshold of the "my time" filter in seconds, null when the filter is off.
     */
    public function myTimeSeconds(): null|int
    {
        return $this->myTimeMinutes !== null ? $this->myTimeMinutes * 60 : null;
    }

    /**
     * True when the system collection ("My puzzles") is among the picked collections.
     */
    public function includesSystemCollection(): bool
    {
        return in_array(Collection::SYSTEM_ID, $this->collectionIds, true);
    }

    /**
     * The picked custom collections (uuids only, without the system sentinel).
     *
     * @return list<string>
     */
    public function customCollectionIds(): array
    {
        return array_values(array_filter($this->collectionIds, static fn (string $id): bool => $id !== Collection::SYSTEM_ID));
    }

    /**
     * The preset whose filters equal the current criteria (seed aside), if
     * any — the chip to highlight. Presets a player is not eligible for are
     * never reported, so a stripped "Beat my record" cannot masquerade as
     * plain "solved before".
     */
    public function activePreset(): null|PuzzlePickerPreset
    {
        if ($this->isAuthenticated === false) {
            return null;
        }

        $current = $this->withSeed(null)->toQueryParams();

        foreach (PuzzlePickerPreset::cases() as $preset) {
            if ($preset->requiresPredictions() && $this->predictionsAllowed === false) {
                continue;
            }

            $presetCriteria = self::fromQuery($preset->queryParams(), $this->isAuthenticated, $this->insightsAllowed, $this->predictionsAllowed);

            if ($presetCriteria->toQueryParams() === $current) {
                return $preset;
            }
        }

        return null;
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

        // The two named shapes keep their short spelling; every other range
        // is spelled out bound by bound
        if ($this->solved !== PuzzlePickerSolved::Any) {
            $parameters['solved'] = $this->solved->value;
        } else {
            if ($this->solvedMin !== 0) {
                $parameters['solved_min'] = (string) $this->solvedMin;
            }

            if ($this->solvedMax !== null) {
                $parameters['solved_max'] = (string) $this->solvedMax;
            }
        }

        if ($this->sinceAmount !== null) {
            $parameters['since'] = (string) $this->sinceAmount;

            if ($this->sinceUnit !== PuzzlePickerSinceUnit::Day) {
                $parameters['since_unit'] = $this->sinceUnit->value;
            }

            if ($this->sinceRequireSolved) {
                $parameters['since_require_solved'] = '1';
            }
        }

        if ($this->myTime !== null && $this->myTimeMinutes !== null) {
            $parameters['my_time'] = $this->myTime->value;

            if ($this->myTimeOperator !== PuzzlePickerMyTimeOperator::Under) {
                $parameters['my_time_op'] = $this->myTimeOperator->value;
            }

            $parameters['my_time_minutes'] = (string) $this->myTimeMinutes;
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

        if ($this->collectionIds !== []) {
            $parameters['collections'] = $this->collectionIds;
        }

        if ($this->community !== null) {
            $parameters['community'] = $this->community->value;
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

        // Specific collections replace the shelf chip - they are the shelf
        if ($this->collectionIds !== []) {
            foreach ($this->collectionIds as $collectionId) {
                $filters[] = new PuzzlePickerActiveFilter(
                    key: 'collection:' . $collectionId,
                    type: 'collection',
                    translationKey: $collectionId === Collection::SYSTEM_ID ? 'collections.system_name' : 'puzzle_picker.chips.collection',
                    translationParameters: [],
                    queryParametersWithoutThis: $this->with(collectionIds: array_values(array_diff($this->collectionIds, [$collectionId])))->toQueryParams(),
                    value: $collectionId,
                );
            }
        } elseif ($this->source !== PuzzlePickerSource::Any) {
            $filters[] = new PuzzlePickerActiveFilter(
                key: 'source',
                type: 'source',
                translationKey: 'puzzle_picker.chips.source.' . $this->source->value,
                translationParameters: [],
                queryParametersWithoutThis: $this->with(source: PuzzlePickerSource::Any)->toQueryParams(),
            );
        }

        // One chip for the whole solve-count constraint, whatever its shape
        if ($this->solvedMin !== 0 || $this->solvedMax !== null) {
            [$translationKey, $translationParameters] = match (true) {
                $this->solved === PuzzlePickerSolved::Never => ['puzzle_picker.chips.solved.never', []],
                $this->solved === PuzzlePickerSolved::Before => ['puzzle_picker.chips.solved.before', []],
                $this->solvedMax !== null && $this->solvedMin === $this->solvedMax => ['puzzle_picker.chips.solved.exact', ['%count%' => $this->solvedMin]],
                $this->solvedMax === null => ['puzzle_picker.chips.solved.at_least', ['%min%' => $this->solvedMin]],
                $this->solvedMin === 0 => ['puzzle_picker.chips.solved.at_most', ['%max%' => $this->solvedMax]],
                default => ['puzzle_picker.chips.solved.between', ['%min%' => $this->solvedMin, '%max%' => $this->solvedMax]],
            };

            $filters[] = new PuzzlePickerActiveFilter(
                key: 'solved',
                type: 'solved',
                translationKey: $translationKey,
                translationParameters: $translationParameters,
                queryParametersWithoutThis: $this->with(clearSolved: true)->toQueryParams(),
            );
        }

        if ($this->sinceAmount !== null) {
            $filters[] = new PuzzlePickerActiveFilter(
                key: 'since',
                type: 'since',
                translationKey: 'puzzle_picker.chips.' . ($this->sinceRequireSolved ? 'since_solved' : 'since') . '.' . $this->sinceUnit->value,
                translationParameters: ['%count%' => $this->sinceAmount],
                queryParametersWithoutThis: $this->with(clearSince: true)->toQueryParams(),
            );
        }

        if ($this->myTime !== null && $this->myTimeMinutes !== null) {
            $filters[] = new PuzzlePickerActiveFilter(
                key: 'my_time',
                type: 'my_time',
                translationKey: 'puzzle_picker.chips.my_time.' . $this->myTime->value . '_' . $this->myTimeOperator->value,
                translationParameters: ['%minutes%' => $this->myTimeMinutes],
                queryParametersWithoutThis: $this->with(clearMyTime: true)->toQueryParams(),
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

        if ($this->community !== null) {
            $filters[] = new PuzzlePickerActiveFilter(
                key: 'community',
                type: 'community',
                translationKey: 'puzzle_picker.chips.community.' . $this->community->value,
                translationParameters: ['%count%' => $this->community->threshold()],
                queryParametersWithoutThis: $this->with(clearCommunity: true)->toQueryParams(),
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
     * Copy with some fields replaced. Nullable fields cannot be "set to null"
     * through a null argument, hence the explicit clear flags.
     *
     * @param list<array{null|int, null|int}>|null $pieces
     * @param list<string>|null $brandIds
     * @param list<string>|null $collectionIds
     * @param list<int>|null $difficultyTiers
     */
    private function with(
        null|PuzzlePickerSource $source = null,
        bool $clearSolved = false,
        bool $clearSince = false,
        bool $clearMyTime = false,
        null|array $pieces = null,
        null|array $brandIds = null,
        null|bool $includeLentOut = null,
        null|array $collectionIds = null,
        bool $clearCommunity = false,
        null|array $difficultyTiers = null,
        null|PuzzlePickerOrder $order = null,
        bool $clearGap = false,
        bool $clearPredictedMax = false,
        null|string $seed = null,
        bool $replaceSeed = false,
    ): self {
        return new self(
            source: $source ?? $this->source,
            solvedMin: $clearSolved ? 0 : $this->solvedMin,
            solvedMax: $clearSolved ? null : $this->solvedMax,
            sinceAmount: $clearSince ? null : $this->sinceAmount,
            sinceUnit: $clearSince ? PuzzlePickerSinceUnit::Day : $this->sinceUnit,
            sinceRequireSolved: $clearSince ? false : $this->sinceRequireSolved,
            myTime: $clearMyTime ? null : $this->myTime,
            myTimeOperator: $clearMyTime ? PuzzlePickerMyTimeOperator::Under : $this->myTimeOperator,
            myTimeMinutes: $clearMyTime ? null : $this->myTimeMinutes,
            pieces: $pieces ?? $this->pieces,
            brandIds: $brandIds ?? $this->brandIds,
            includeLentOut: $includeLentOut ?? $this->includeLentOut,
            collectionIds: $collectionIds ?? $this->collectionIds,
            community: $clearCommunity ? null : $this->community,
            difficultyTiers: $difficultyTiers ?? $this->difficultyTiers,
            gap: $clearGap ? null : $this->gap,
            gapMinMinutes: $clearGap ? null : $this->gapMinMinutes,
            predictedMaxMinutes: $clearPredictedMax ? null : $this->predictedMaxMinutes,
            order: $order ?? $this->order,
            seed: $replaceSeed ? $seed : $this->seed,
            isAuthenticated: $this->isAuthenticated,
            insightsAllowed: $this->insightsAllowed,
            predictionsAllowed: $this->predictionsAllowed,
        );
    }

    /**
     * Named shape of a solve-count range (see the $solved property).
     */
    private static function solvedShapeOf(int $min, null|int $max): PuzzlePickerSolved
    {
        if ($max === 0) {
            return PuzzlePickerSolved::Never;
        }

        if ($min === 1 && $max === null) {
            return PuzzlePickerSolved::Before;
        }

        return PuzzlePickerSolved::Any;
    }

    /**
     * The solve-count range from the named shape and the explicit bounds. An
     * explicit bound wins over the shape's own bound; when the two collide
     * (`solved=never&solved_min=2`, `solved=before&solved_max=0`) the explicit
     * one is kept and the shape's bound is dropped.
     *
     * @return array{int, null|int}
     */
    private static function normalizeSolveCountRange(null|string $solved, null|string $solvedMin, null|string $solvedMax): array
    {
        $shape = $solved !== null ? PuzzlePickerSolved::tryFrom($solved) ?? PuzzlePickerSolved::Any : PuzzlePickerSolved::Any;

        [$lower, $upper] = match ($shape) {
            PuzzlePickerSolved::Never => [0, 0],
            PuzzlePickerSolved::Before => [1, null],
            PuzzlePickerSolved::Any => [0, null],
        };

        $min = self::parseInt($solvedMin, 0, self::MAX_SOLVE_COUNT);
        $max = self::parseInt($solvedMax, 0, self::MAX_SOLVE_COUNT);

        if ($min !== null && $max !== null && $min > $max) {
            [$min, $max] = [$max, $min];
        }

        if ($min !== null) {
            $lower = $min;
        }

        if ($max !== null) {
            $upper = $max;
        }

        if ($upper !== null && $lower > $upper) {
            if ($min !== null) {
                $upper = null;
            } else {
                $lower = 0;
            }
        }

        return [$lower, $upper];
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

    private static function stringInput(mixed $value): null|string
    {
        return is_string($value) ? $value : null;
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
     * Collection uuids and/or the system collection sentinel, deduplicated;
     * anything else is dropped. Ownership is not checked here — the query
     * only ever looks at the player's own collection items, so a foreign id
     * simply matches nothing.
     *
     * @param array<mixed> $collectionIds
     *
     * @return list<string>
     */
    private static function normalizeCollectionIds(array $collectionIds): array
    {
        $normalized = [];

        foreach ($collectionIds as $collectionId) {
            if (is_string($collectionId) === false || in_array($collectionId, $normalized, true)) {
                continue;
            }

            if ($collectionId === Collection::SYSTEM_ID || Uuid::isValid($collectionId)) {
                $normalized[] = $collectionId;
            }
        }

        return array_slice($normalized, 0, self::MAX_COLLECTIONS);
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
     * Whole number within [min, max]; anything else (blank, text, out of range) is null.
     */
    private static function parseInt(null|string $value, int $min, int $max): null|int
    {
        if ($value === null || preg_match('/^\d{1,5}$/', trim($value)) !== 1) {
            return null;
        }

        $number = (int) trim($value);

        return $number >= $min && $number <= $max ? $number : null;
    }
}
