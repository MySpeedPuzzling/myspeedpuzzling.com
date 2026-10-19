<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\Insights;

use DateTimeImmutable;
use DateTimeZone;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Results\InsightsSoloResult;
use SpeedPuzzling\Web\Value\DifficultyTier;
use SpeedPuzzling\Web\Value\InsightsImageTrait;
use SpeedPuzzling\Web\Value\InsightsInclusionFilter;
use SpeedPuzzling\Web\Value\InsightsPeriod;
use SpeedPuzzling\Web\Value\InsightsPuzzlePersonality;
use SpeedPuzzling\Web\Value\InsightsResultsFilter;
use SpeedPuzzling\Web\Value\InsightsTimeOfDay;
use SpeedPuzzling\Web\Value\PiecesRange;

/**
 * The normalized filter state of the Insights page (docs/features/insights/computation.md "Core sections" §1). The
 * URL is the state, like Compare's ComparisonCriteria: the input may carry anything a crafted link can, so every value
 * is normalized here - invalid values fall back to the default, members-only values are dropped silently for free
 * players (never an error; `strippedForMembers` names them so the page can show the lock). Free = the last 3 or 6
 * months and the piece count (README §3); everything else is members only.
 *
 * Within one multi-value filter the values are alternatives (brand A or B); different filters all apply.
 */
final readonly class InsightsCriteria
{
    public const int MAX_BRANDS = 20;

    /** Same meaning as on the puzzle search and Compare: puzzles without a computed difficulty yet */
    public const int UNRATED_DIFFICULTY = 0;

    public const InsightsPeriod FREE_DEFAULT_PERIOD = InsightsPeriod::Last6Months;

    public const InsightsPeriod MEMBER_DEFAULT_PERIOD = InsightsPeriod::Last24Months;

    private const int MIN_YEAR = 2000;

    private const int MAX_YEAR = 2100;

    private const string DATE_FORMAT = 'Y-m-d';

    /**
     * @param list<string> $brandIds manufacturer ids, lower case
     * @param list<int> $difficultyTiers DifficultyTier values and/or UNRATED_DIFFICULTY, ascending
     * @param list<int> $weekdays ISO-8601 days (1 = Monday … 7 = Sunday), ascending
     * @param list<InsightsPuzzlePersonality> $personalities
     * @param list<InsightsImageTrait> $imageTraits
     * @param list<InsightsTimeOfDay> $timesOfDay
     * @param list<string> $strippedForMembers query keys whose values were dropped because the player is no member
     */
    private function __construct(
        public bool $isMember,
        public InsightsPeriod $period,
        public null|int $year,
        public null|DateTimeImmutable $customFrom,
        public null|DateTimeImmutable $customTo,
        public PiecesRange $pieces,
        public InsightsResultsFilter $results,
        public array $brandIds,
        public array $difficultyTiers,
        public InsightsInclusionFilter $events,
        public InsightsInclusionFilter $unboxed,
        public InsightsInclusionFilter $shelf,
        public array $weekdays,
        public array $personalities,
        public array $imageTraits,
        public array $timesOfDay,
        public array $strippedForMembers,
    ) {
    }

    /**
     * @param array<mixed> $query the URL query (Request::$query->all()) or the page's props, keyed like toQueryParameters()
     * @param bool $hasTimezone the player has a time zone (player.timezone) - the time-of-day filter needs one
     */
    public static function fromUserInput(array $query, bool $isMember, bool $hasTimezone = false): self
    {
        $stripped = [];

        $periodValue = InsightsPeriod::tryFrom(self::string($query, 'period') ?? '');
        $year = null;
        $customFrom = null;
        $customTo = null;

        if ($periodValue === InsightsPeriod::PastYear) {
            $year = self::year(self::string($query, 'year'));

            if ($year === null) {
                $periodValue = null;
            }
        }

        if ($periodValue === InsightsPeriod::Custom) {
            $customFrom = self::date(self::string($query, 'from'));
            $customTo = self::date(self::string($query, 'to'));

            if ($customFrom === null && $customTo === null) {
                $periodValue = null;
            } elseif ($customFrom !== null && $customTo !== null && $customFrom > $customTo) {
                [$customFrom, $customTo] = [$customTo, $customFrom];
            }
        }

        if ($isMember === false && $periodValue !== null && $periodValue->isFree() === false) {
            $stripped[] = 'period';
            $periodValue = null;
        }

        if ($periodValue === null) {
            $periodValue = $isMember ? self::MEMBER_DEFAULT_PERIOD : self::FREE_DEFAULT_PERIOD;
        }

        if ($periodValue !== InsightsPeriod::PastYear) {
            $year = null;
        }

        if ($periodValue !== InsightsPeriod::Custom) {
            $customFrom = null;
            $customTo = null;
        }

        $results = InsightsResultsFilter::tryFrom(self::string($query, 'results') ?? '') ?? InsightsResultsFilter::All;
        $brandIds = self::brandIds(self::list($query, 'brands'));
        $difficulty = self::difficultyTiers(self::list($query, 'difficulty'));
        $events = InsightsInclusionFilter::tryFrom(self::string($query, 'events') ?? '') ?? InsightsInclusionFilter::Any;
        $unboxed = InsightsInclusionFilter::tryFrom(self::string($query, 'unboxed') ?? '') ?? InsightsInclusionFilter::Any;
        $shelf = InsightsInclusionFilter::tryFrom(self::string($query, 'shelf') ?? '') ?? InsightsInclusionFilter::Any;
        $weekdays = self::weekdays(self::list($query, 'weekdays'));
        $personalities = self::enumList(self::list($query, 'personality'), InsightsPuzzlePersonality::class);
        $traits = self::enumList(self::list($query, 'traits'), InsightsImageTrait::class);
        $timesOfDay = self::enumList(self::list($query, 'timeOfDay'), InsightsTimeOfDay::class);

        // The time of day exists only in the player's own zone
        if ($hasTimezone === false) {
            $timesOfDay = [];
        }

        if ($isMember === false) {
            $membersOnly = [
                'results' => $results !== InsightsResultsFilter::All,
                'brands' => $brandIds !== [],
                'difficulty' => $difficulty !== [],
                'events' => $events !== InsightsInclusionFilter::Any,
                'unboxed' => $unboxed !== InsightsInclusionFilter::Any,
                'shelf' => $shelf !== InsightsInclusionFilter::Any,
                'weekdays' => $weekdays !== [],
                'personality' => $personalities !== [],
                'traits' => $traits !== [],
                'timeOfDay' => $timesOfDay !== [],
            ];

            foreach ($membersOnly as $key => $given) {
                if ($given) {
                    $stripped[] = $key;
                }
            }

            $results = InsightsResultsFilter::All;
            $brandIds = [];
            $difficulty = [];
            $events = InsightsInclusionFilter::Any;
            $unboxed = InsightsInclusionFilter::Any;
            $shelf = InsightsInclusionFilter::Any;
            $weekdays = [];
            $personalities = [];
            $traits = [];
            $timesOfDay = [];
        }

        return new self(
            isMember: $isMember,
            period: $periodValue,
            year: $year,
            customFrom: $customFrom,
            customTo: $customTo,
            pieces: PiecesRange::parse(self::string($query, 'pieces')) ?? PiecesRange::any(),
            results: $results,
            brandIds: $brandIds,
            difficultyTiers: $difficulty,
            events: $events,
            unboxed: $unboxed,
            shelf: $shelf,
            weekdays: $weekdays,
            personalities: $personalities,
            imageTraits: $traits,
            timesOfDay: $timesOfDay,
            strippedForMembers: $stripped,
        );
    }

    /**
     * First moment (inclusive, midnight UTC - solve days are stored as UTC dates) of the results that count; null = no
     * lower bound. Relative periods start at midnight, so the page is the same all day (and its cache key with it).
     */
    public function periodFrom(DateTimeImmutable $now): null|DateTimeImmutable
    {
        $now = $now->setTimezone(new DateTimeZone('UTC'));
        $months = $this->period->months();

        if ($months !== null) {
            // The same day N months back, clamped to that month's last day (31 Aug − 6 months = 28/29 Feb, never 3 Mar)
            $target = $now->setDate((int) $now->format('Y'), (int) $now->format('n'), 1)->modify("-{$months} months");
            $day = min((int) $now->format('j'), (int) $target->format('t'));

            return $target->setDate((int) $target->format('Y'), (int) $target->format('n'), $day)->setTime(0, 0);
        }

        return match ($this->period) {
            InsightsPeriod::ThisYear => $now->setDate((int) $now->format('Y'), 1, 1)->setTime(0, 0),
            InsightsPeriod::PastYear => $now->setDate((int) $this->year, 1, 1)->setTime(0, 0),
            InsightsPeriod::Custom => $this->customFrom,
            default => null,
        };
    }

    /**
     * Exclusive upper bound (midnight after the last day); null = up to now.
     */
    public function periodBefore(): null|DateTimeImmutable
    {
        return match ($this->period) {
            InsightsPeriod::PastYear => new DateTimeImmutable(sprintf('%d-01-01 00:00:00', (int) $this->year + 1), new DateTimeZone('UTC')),
            InsightsPeriod::Custom => $this->customTo?->modify('+1 day'),
            default => null,
        };
    }

    public function inPeriod(DateTimeImmutable $solvedAt, DateTimeImmutable $now): bool
    {
        $from = $this->periodFrom($now);
        $before = $this->periodBefore();

        return ($from === null || $solvedAt >= $from) && ($before === null || $solvedAt < $before);
    }

    /**
     * The filters that describe the puzzle (pieces, brand, difficulty, personality, picture, shelf) - close calls and
     * the learning curve follow only these: they are about puzzles, not about when or how a result was made.
     */
    public function matchesPuzzle(InsightsSoloResult $result): bool
    {
        if ($this->pieces->contains($result->piecesCount) === false) {
            return false;
        }

        if ($this->brandIds !== [] && in_array(strtolower((string) $result->manufacturerId), $this->brandIds, true) === false) {
            return false;
        }

        if ($this->difficultyTiers !== [] && in_array($result->difficultyTier ?? self::UNRATED_DIFFICULTY, $this->difficultyTiers, true) === false) {
            return false;
        }

        if ($this->shelf->accepts($result->onShelf) === false) {
            return false;
        }

        if ($this->personalities !== [] && array_intersect(self::values($this->personalities), self::values($result->personalities())) === []) {
            return false;
        }

        if ($this->imageTraits !== [] && array_intersect(self::values($this->imageTraits), self::values($result->imageTraits())) === []) {
            return false;
        }

        return true;
    }

    /**
     * Every filter but the period: the puzzle filters plus results, events, unboxed, weekday and time of day.
     *
     * @param int $weekday ISO-8601 day of the solve
     */
    public function matchesResult(InsightsSoloResult $result, int $weekday, null|InsightsTimeOfDay $timeOfDay): bool
    {
        if ($this->matchesPuzzle($result) === false) {
            return false;
        }

        if ($this->results === InsightsResultsFilter::FirstTries && $result->firstAttempt === false) {
            return false;
        }

        if ($this->results === InsightsResultsFilter::Repeats && $result->firstAttempt) {
            return false;
        }

        if ($this->events->accepts($result->atEvent) === false || $this->unboxed->accepts($result->unboxed) === false) {
            return false;
        }

        if ($this->weekdays !== [] && in_array($weekday, $this->weekdays, true) === false) {
            return false;
        }

        if ($this->timesOfDay !== [] && ($timeOfDay === null || in_array($timeOfDay, $this->timesOfDay, true) === false)) {
            return false;
        }

        return true;
    }

    /**
     * The single piece-count class the selection is about (the community learning curve uses it), null for several.
     */
    public function singlePiecesClass(): null|int
    {
        if ($this->pieces->minPieces === null || $this->pieces->maxPieces === null) {
            return null;
        }

        $class = PiecesRange::classOf($this->pieces->minPieces);

        return PiecesRange::classOf($this->pieces->maxPieces) === $class ? $class : null;
    }

    public function isDefaultPeriod(): bool
    {
        return $this->period === ($this->isMember ? self::MEMBER_DEFAULT_PERIOD : self::FREE_DEFAULT_PERIOD);
    }

    /**
     * Only what differs from the defaults - for the URL and a share of the filter state; also the cache key of a
     * built page (sorted, so the same filters are the same key).
     *
     * @return array<string, string|list<string>>
     */
    public function toQueryParameters(): array
    {
        $parameters = [];

        if ($this->isDefaultPeriod() === false) {
            $parameters['period'] = $this->period->value;
        }

        if ($this->year !== null) {
            $parameters['year'] = (string) $this->year;
        }

        if ($this->customFrom !== null) {
            $parameters['from'] = $this->customFrom->format(self::DATE_FORMAT);
        }

        if ($this->customTo !== null) {
            $parameters['to'] = $this->customTo->format(self::DATE_FORMAT);
        }

        if ($this->pieces->toParam() !== '') {
            $parameters['pieces'] = $this->pieces->toParam();
        }

        if ($this->results !== InsightsResultsFilter::All) {
            $parameters['results'] = $this->results->value;
        }

        if ($this->brandIds !== []) {
            $parameters['brands'] = $this->brandIds;
        }

        if ($this->difficultyTiers !== []) {
            $parameters['difficulty'] = array_map(strval(...), $this->difficultyTiers);
        }

        foreach (['events' => $this->events, 'unboxed' => $this->unboxed, 'shelf' => $this->shelf] as $key => $filter) {
            if ($filter !== InsightsInclusionFilter::Any) {
                $parameters[$key] = $filter->value;
            }
        }

        if ($this->weekdays !== []) {
            $parameters['weekdays'] = array_map(strval(...), $this->weekdays);
        }

        if ($this->personalities !== []) {
            $parameters['personality'] = self::values($this->personalities);
        }

        if ($this->imageTraits !== []) {
            $parameters['traits'] = self::values($this->imageTraits);
        }

        if ($this->timesOfDay !== []) {
            $parameters['timeOfDay'] = self::values($this->timesOfDay);
        }

        return $parameters;
    }

    /**
     * A short stable key of the applied filters, membership and the player's time zone (solve days, weekdays and the
     * time of day depend on it) - for the page cache.
     *
     * @param null|string $timezone the zone the report is built with (InsightsPageLoader's), null = none
     */
    public function cacheKey(null|string $timezone): string
    {
        $parameters = $this->toQueryParameters();
        ksort($parameters);

        return substr(md5(($this->isMember ? 'm' : 'f') . '|' . ($timezone ?? '') . '|' . json_encode($parameters, JSON_THROW_ON_ERROR)), 0, 16);
    }

    /**
     * @param list<\BackedEnum> $cases
     * @return list<string>
     */
    private static function values(array $cases): array
    {
        return array_map(static fn (\BackedEnum $case): string => (string) $case->value, $cases);
    }

    /**
     * @param array<mixed> $query
     */
    private static function string(array $query, string $key): null|string
    {
        $value = $query[$key] ?? null;

        if (is_int($value)) {
            return (string) $value;
        }

        return is_string($value) ? trim($value) : null;
    }

    /**
     * @param array<mixed> $query
     * @return list<mixed>
     */
    private static function list(array $query, string $key): array
    {
        $value = $query[$key] ?? [];

        if (is_string($value) || is_int($value)) {
            $value = [$value];
        }

        return is_array($value) ? array_values($value) : [];
    }

    private static function year(null|string $value): null|int
    {
        if ($value === null || preg_match('/^\d{4}$/', $value) !== 1) {
            return null;
        }

        $year = (int) $value;

        return $year >= self::MIN_YEAR && $year <= self::MAX_YEAR ? $year : null;
    }

    private static function date(null|string $value): null|DateTimeImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }

        $date = DateTimeImmutable::createFromFormat('!' . self::DATE_FORMAT, $value, new DateTimeZone('UTC'));

        // createFromFormat() rolls 2026-02-31 over into March - a date that does not exist is no date
        if ($date === false || $date->format(self::DATE_FORMAT) !== $value) {
            return null;
        }

        $year = (int) $date->format('Y');

        return $year >= self::MIN_YEAR && $year <= self::MAX_YEAR ? $date : null;
    }

    /**
     * @param list<mixed> $values
     * @return list<string>
     */
    private static function brandIds(array $values): array
    {
        $ids = [];

        foreach ($values as $value) {
            if (is_string($value) && Uuid::isValid($value)) {
                $ids[strtolower($value)] = true;
            }

            if (count($ids) >= self::MAX_BRANDS) {
                break;
            }
        }

        $ids = array_keys($ids);
        sort($ids);

        return $ids;
    }

    /**
     * @param list<mixed> $values
     * @return list<int>
     */
    private static function difficultyTiers(array $values): array
    {
        $tiers = [];

        foreach ($values as $value) {
            $tier = self::smallInt($value);

            if ($tier !== null && ($tier === self::UNRATED_DIFFICULTY || DifficultyTier::tryFrom($tier) !== null)) {
                $tiers[$tier] = true;
            }
        }

        $tiers = array_keys($tiers);
        sort($tiers);

        return $tiers;
    }

    /**
     * @param list<mixed> $values
     * @return list<int>
     */
    private static function weekdays(array $values): array
    {
        $days = [];

        foreach ($values as $value) {
            $day = self::smallInt($value);

            if ($day !== null && $day >= 1 && $day <= 7) {
                $days[$day] = true;
            }
        }

        $days = array_keys($days);
        sort($days);

        return $days;
    }

    /**
     * @template T of \BackedEnum
     * @param list<mixed> $values
     * @param class-string<T> $enum
     * @return list<T>
     */
    private static function enumList(array $values, string $enum): array
    {
        $chosen = [];

        foreach ($values as $value) {
            if (is_string($value) && ($case = $enum::tryFrom($value)) !== null) {
                $chosen[(string) $case->value] = true;
            }
        }

        $list = [];

        foreach ($enum::cases() as $case) {
            if (isset($chosen[(string) $case->value])) {
                $list[] = $case;
            }
        }

        return $list;
    }

    private static function smallInt(mixed $value): null|int
    {
        if (is_int($value)) {
            return $value;
        }

        return is_string($value) && preg_match('/^\d{1,2}$/', $value) === 1 ? (int) $value : null;
    }
}
