<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

use DateTimeImmutable;
use Ramsey\Uuid\Uuid;

/**
 * Normalized state of the comparison page (docs/features/player-comparison.md, "Filters").
 *
 * The URL is the state, so the input may carry anything a crafted link can: unknown values, mangled uuids, impossible
 * dates, members-only filters from a free player. Everything is normalized here: invalid values fall back to the
 * default, members-only values are dropped silently (never an error) - the same server-side rule as
 * PuzzleSearchCriteria. normalized() / toQueryParameters() give the UI the values that were actually applied, to reflect
 * back into its props and the URL (like PuzzleSearch::normalizeState()).
 *
 * The default "puzzles to show" depends on the line-up (D10), hence the subject count as input. With two subjects (or
 * one) "two_plus" means the same as "everyone" and is normalized to it.
 */
final readonly class ComparisonCriteria
{
    /** "Show more" step and the default page size */
    public const int PAGE_SIZE = 50;

    /** The most puzzles one page hydrates and renders */
    public const int MAX_LIMIT = 500;

    public const int MAX_OFFSET = 100000;

    public const int MAX_BRANDS = 20;

    /** Same meaning (and value) as on the puzzle search: puzzles without a computed difficulty yet */
    public const int UNRATED_DIFFICULTY = PuzzleSearchCriteria::UNRATED_DIFFICULTY;

    private const string DATE_FORMAT = 'Y-m-d';

    private const string MIN_DATE = '1990-01-01';

    private const string MAX_DATE = '2100-12-31';

    /**
     * @param list<string> $brandIds manufacturer ids
     * @param list<int> $difficultyTiers DifficultyTier values and/or UNRATED_DIFFICULTY, ascending
     */
    private function __construct(
        public int $subjectCount,
        public ComparisonShow $show,
        public ComparisonTimes $times,
        public ComparisonPeriod $period,
        public null|DateTimeImmutable $customFrom,
        public null|DateTimeImmutable $customTo,
        public PiecesRange $pieces,
        public array $brandIds,
        public array $difficultyTiers,
        public ComparisonSort $sort,
        public null|ComparisonSubjectRef $highlightA,
        public null|ComparisonSubjectRef $highlightB,
        public int $offset,
        public int $limit,
    ) {
    }

    /**
     * Flat scalars as they come from the URL / Live props. Lists accept anything iterable-ish the URL can produce;
     * non-list input is ignored.
     *
     * @param int $subjectCount subjects in the compared line-up (decides the default "puzzles to show")
     * @param array<mixed> $brands manufacturer uuids
     * @param array<mixed> $difficulty tier numbers, 0 = not rated yet
     */
    public static function fromUserInput(
        int $subjectCount,
        bool $isMember,
        null|string $show = null,
        null|string $times = null,
        null|string $period = null,
        null|string $from = null,
        null|string $to = null,
        null|string $pieces = null,
        array $brands = [],
        array $difficulty = [],
        null|string $sort = null,
        null|string $highlightA = null,
        null|string $highlightB = null,
        null|int|string $offset = null,
        null|int|string $limit = null,
    ): self {
        $subjectCount = max(0, $subjectCount);

        $showValue = ComparisonShow::tryFrom((string) $show) ?? ComparisonShow::defaultFor($subjectCount);

        // With two subjects "solved by 2+" is "solved by both"
        if ($showValue === ComparisonShow::TwoPlus && $subjectCount <= 2) {
            $showValue = ComparisonShow::Everyone;
        }

        $timesValue = ComparisonTimes::tryFrom((string) $times) ?? ComparisonTimes::Best;
        $periodValue = ComparisonPeriod::tryFrom((string) $period) ?? ComparisonPeriod::All;
        $sortValue = ComparisonSort::tryFrom((string) $sort) ?? ComparisonSort::Recent;
        $brandIds = self::normalizeBrandIds($brands);
        $difficultyTiers = self::normalizeDifficultyTiers($difficulty);

        // Members-only filters (D3, Filters table): dropped, never an error - the UI shows them locked
        if ($isMember === false) {
            if ($timesValue === ComparisonTimes::FirstTries) {
                $timesValue = ComparisonTimes::Best;
            }

            if ($periodValue === ComparisonPeriod::Custom) {
                $periodValue = ComparisonPeriod::All;
            }

            if ($sortValue->isMembersOnly()) {
                $sortValue = ComparisonSort::Recent;
            }

            $brandIds = [];
            $difficultyTiers = [];
        }

        // Lead / lag need two highlighted subjects
        if ($sortValue->needsHighlightPair() && $subjectCount < 2) {
            $sortValue = ComparisonSort::Recent;
        }

        $customFrom = null;
        $customTo = null;

        if ($periodValue === ComparisonPeriod::Custom) {
            $customFrom = self::parseDate($from);
            $customTo = self::parseDate($to);

            if ($customFrom === null && $customTo === null) {
                $periodValue = ComparisonPeriod::All;
            } elseif ($customFrom !== null && $customTo !== null && $customFrom > $customTo) {
                [$customFrom, $customTo] = [$customTo, $customFrom];
            }
        }

        $highlightAValue = $highlightA !== null ? ComparisonSubjectRef::tryFromString($highlightA) : null;
        $highlightBValue = $highlightB !== null ? ComparisonSubjectRef::tryFromString($highlightB) : null;

        if ($highlightAValue !== null && $highlightBValue !== null && $highlightAValue->equals($highlightBValue)) {
            $highlightBValue = null;
        }

        return new self(
            subjectCount: $subjectCount,
            show: $showValue,
            times: $timesValue,
            period: $periodValue,
            customFrom: $customFrom,
            customTo: $customTo,
            pieces: PiecesRange::parse($pieces) ?? PiecesRange::any(),
            brandIds: $brandIds,
            difficultyTiers: $difficultyTiers,
            sort: $sortValue,
            highlightA: $highlightAValue,
            highlightB: $highlightBValue,
            offset: self::clampInt($offset, 0, 0, self::MAX_OFFSET),
            limit: self::normalizeLimit($limit),
        );
    }

    /**
     * First day (inclusive, midnight) of the times that count; null = no lower bound. The relative periods start at
     * midnight so the result is the same all day.
     */
    public function solvedFrom(DateTimeImmutable $now): null|DateTimeImmutable
    {
        $months = $this->period->months();

        if ($months !== null) {
            return $now->setTime(0, 0)->modify("-{$months} months");
        }

        if ($this->period === ComparisonPeriod::Custom) {
            return $this->customFrom;
        }

        return null;
    }

    /**
     * Exclusive upper bound (midnight after the last day) of the times that count; null = no upper bound.
     */
    public function solvedBefore(): null|DateTimeImmutable
    {
        if ($this->period === ComparisonPeriod::Custom && $this->customTo !== null) {
            return $this->customTo->modify('+1 day');
        }

        return null;
    }

    /**
     * The aggregate statement joins difficulty only when a member filters or sorts by it.
     */
    public function needsDifficulty(): bool
    {
        return $this->difficultyTiers !== [] || $this->sort === ComparisonSort::Difficulty;
    }

    /**
     * Sorting by name needs the names of all compared puzzles, not only of the shown page.
     */
    public function needsPuzzleNames(): bool
    {
        return $this->sort === ComparisonSort::Name;
    }

    public function isDefaultShow(): bool
    {
        return $this->show === ComparisonShow::defaultFor($this->subjectCount);
    }

    /**
     * The applied values as flat scalars, keyed like fromUserInput()'s parameters - for the UI to write back into its
     * props / URL.
     *
     * @return array{
     *     show: string,
     *     times: string,
     *     period: string,
     *     from: null|string,
     *     to: null|string,
     *     pieces: null|string,
     *     brands: list<string>,
     *     difficulty: list<int>,
     *     sort: string,
     *     highlightA: null|string,
     *     highlightB: null|string,
     *     offset: int,
     *     limit: int,
     * }
     */
    public function normalized(): array
    {
        $pieces = $this->pieces->toParam();

        return [
            'show' => $this->show->value,
            'times' => $this->times->value,
            'period' => $this->period->value,
            'from' => $this->customFrom?->format(self::DATE_FORMAT),
            'to' => $this->customTo?->format(self::DATE_FORMAT),
            'pieces' => $pieces !== '' ? $pieces : null,
            'brands' => $this->brandIds,
            'difficulty' => $this->difficultyTiers,
            'sort' => $this->sort->value,
            'highlightA' => $this->highlightA?->toString(),
            'highlightB' => $this->highlightB?->toString(),
            'offset' => $this->offset,
            'limit' => $this->limit,
        ];
    }

    /**
     * Only what differs from the defaults, keyed like normalized() - for share links and the synced URL. Paging is never
     * shared.
     *
     * @return array<string, string|list<string>>
     */
    public function toQueryParameters(): array
    {
        $normalized = $this->normalized();
        $parameters = [];

        if ($this->isDefaultShow() === false) {
            $parameters['show'] = $normalized['show'];
        }

        if ($this->times !== ComparisonTimes::Best) {
            $parameters['times'] = $normalized['times'];
        }

        if ($this->period !== ComparisonPeriod::All) {
            $parameters['period'] = $normalized['period'];
        }

        if ($normalized['from'] !== null) {
            $parameters['from'] = $normalized['from'];
        }

        if ($normalized['to'] !== null) {
            $parameters['to'] = $normalized['to'];
        }

        if ($normalized['pieces'] !== null) {
            $parameters['pieces'] = $normalized['pieces'];
        }

        if ($this->brandIds !== []) {
            $parameters['brands'] = $this->brandIds;
        }

        if ($this->difficultyTiers !== []) {
            $parameters['difficulty'] = array_map(strval(...), $this->difficultyTiers);
        }

        if ($this->sort !== ComparisonSort::Recent) {
            $parameters['sort'] = $normalized['sort'];
        }

        if ($normalized['highlightA'] !== null) {
            $parameters['highlightA'] = $normalized['highlightA'];
        }

        if ($normalized['highlightB'] !== null) {
            $parameters['highlightB'] = $normalized['highlightB'];
        }

        return $parameters;
    }

    private static function parseDate(null|string $value): null|DateTimeImmutable
    {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        $date = DateTimeImmutable::createFromFormat('!' . self::DATE_FORMAT, $value);

        // createFromFormat() rolls 2026-02-31 over into March - a date that does not exist is no date
        if ($date === false || $date->format(self::DATE_FORMAT) !== $value) {
            return null;
        }

        if ($value < self::MIN_DATE || $value > self::MAX_DATE) {
            return null;
        }

        return $date;
    }

    /**
     * @param array<mixed> $brands
     * @return list<string>
     */
    private static function normalizeBrandIds(array $brands): array
    {
        $ids = [];

        foreach ($brands as $brand) {
            if (is_string($brand) === false || Uuid::isValid($brand) === false) {
                continue;
            }

            $ids[strtolower($brand)] = true;

            if (count($ids) >= self::MAX_BRANDS) {
                break;
            }
        }

        return array_keys($ids);
    }

    /**
     * @param array<mixed> $difficulty
     * @return list<int>
     */
    private static function normalizeDifficultyTiers(array $difficulty): array
    {
        $tiers = [];

        foreach ($difficulty as $tier) {
            if (is_int($tier) === false && (is_string($tier) === false || preg_match('/^\d{1,2}$/', $tier) !== 1)) {
                continue;
            }

            $tier = (int) $tier;

            if ($tier === self::UNRATED_DIFFICULTY || DifficultyTier::tryFrom($tier) !== null) {
                $tiers[$tier] = true;
            }
        }

        $tiers = array_keys($tiers);
        sort($tiers);

        return $tiers;
    }

    private static function normalizeLimit(null|int|string $limit): int
    {
        $value = self::clampInt($limit, self::PAGE_SIZE, 1, self::MAX_LIMIT);

        // "Show more" adds whole steps
        return min(self::MAX_LIMIT, (int) ceil($value / self::PAGE_SIZE) * self::PAGE_SIZE);
    }

    private static function clampInt(null|int|string $value, int $default, int $min, int $max): int
    {
        if (is_string($value)) {
            $value = preg_match('/^\d{1,9}$/', trim($value)) === 1 ? (int) trim($value) : null;
        }

        if ($value === null) {
            return $default;
        }

        return max($min, min($max, $value));
    }
}
