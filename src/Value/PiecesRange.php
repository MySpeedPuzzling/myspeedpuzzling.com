<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

use InvalidArgumentException;

/**
 * Inclusive piece-count bounds for every piece-count filter (puzzle search,
 * profile results, lists, marketplace, puzzle picker, API). A null bound means
 * "unbounded on that side"; an exact count is both bounds equal.
 *
 * URL grammar (parse() / toParam()): `N` exact, `A-B` between, `A-` at least,
 * `-B` at most. The legacy search buckets (`1-499`, `501-999`, `1001+`) are
 * valid input too, so old links keep filtering the same puzzles.
 */
final readonly class PiecesRange
{
    public const int MAX_PIECES = 100000;

    /**
     * Piece counts that are a class on the catalogue pages, their difficulty lists and in every
     * piece-count filter: the class takes in every count within CLASS_TOLERANCE_PERCENT of it, so a
     * box printed with a few pieces more is still a 500-piece puzzle to the people who solve it
     * (JIGZUP prints 504, 2026-10-18): 200 = 198-202, 500 = 495-505, 1000 = 990-1010. Counts below
     * 200 stay exact - 99 and 100 have pages of their own. Rankings, ratings and insights keep the
     * exact count everywhere.
     *
     * @var list<int>
     */
    public const array CLASSES = [200, 250, 300, 350, 500, 750, 1000, 1500, 2000, 3000];

    public const int CLASS_TOLERANCE_PERCENT = 1;

    /**
     * The quick-pick chips, shared by every piece-count filter: the most
     * solved counts (99 and 100 are one chip - both are "the 100 class"),
     * anything else goes through the custom from-to range.
     *
     * A count that is one of CLASSES filters by its class (the "500" chip = 495-505).
     *
     * @var array<int|string, string> URL param => label (PHP turns "500" keys into ints)
     */
    public const array PRESETS = [
        '99-100' => '99/100',
        '200' => '200',
        '300' => '300',
        '500' => '500',
        '750' => '750',
        '1000' => '1000',
        '1500' => '1500',
        '2000-' => '2000+',
    ];

    private function __construct(
        public null|int $minPieces,
        public null|int $maxPieces,
    ) {
    }

    public static function any(): self
    {
        return new self(null, null);
    }

    /**
     * @throws InvalidArgumentException when the bounds contradict each other
     */
    public static function between(null|int $minPieces, null|int $maxPieces): self
    {
        if ($minPieces !== null && $maxPieces !== null && $minPieces > $maxPieces) {
            throw new InvalidArgumentException(sprintf('Minimum %d is above maximum %d.', $minPieces, $maxPieces));
        }

        return new self($minPieces, $maxPieces);
    }

    /**
     * Lenient counterpart of between() for user-typed bounds: an out-of-range
     * bound is ignored, swapped bounds are put in order.
     *
     * @return null|self null when no usable bound is left
     */
    public static function fromBounds(null|int $minPieces, null|int $maxPieces): null|self
    {
        $minPieces = $minPieces !== null && self::isValidCount($minPieces) ? $minPieces : null;
        $maxPieces = $maxPieces !== null && self::isValidCount($maxPieces) ? $maxPieces : null;

        if ($minPieces === null && $maxPieces === null) {
            return null;
        }

        if ($minPieces !== null && $maxPieces !== null && $minPieces > $maxPieces) {
            [$minPieces, $maxPieces] = [$maxPieces, $minPieces];
        }

        return new self($minPieces, $maxPieces);
    }

    /**
     * @return null|self null for empty or malformed input
     */
    public static function parse(null|string $value): null|self
    {
        // Legacy "1001+" from the old search buckets - typed into a URL unencoded, the "+" arrives as one trailing space
        if (preg_match('/^(\d{1,6})[+ ]$/', $value ?? '', $matches) === 1) {
            $value = $matches[1] . '-';
        }

        $value = trim($value ?? '');

        if (preg_match('/^(\d{1,6})$/', $value, $matches) === 1) {
            $count = (int) $matches[1];

            return self::isValidCount($count) ? new self($count, $count) : null;
        }

        if (preg_match('/^(\d{0,6})-(\d{0,6})$/', $value, $matches) !== 1) {
            return null;
        }

        $min = $matches[1] !== '' ? (int) $matches[1] : null;
        $max = $matches[2] !== '' ? (int) $matches[2] : null;

        if (($min !== null && self::isValidCount($min) === false) || ($max !== null && self::isValidCount($max) === false)) {
            return null;
        }

        return self::fromBounds($min, $max);
    }

    /**
     * @return list<self>
     */
    public static function presets(): array
    {
        $presets = [];

        foreach (array_keys(self::PRESETS) as $param) {
            $preset = self::parse((string) $param);
            assert($preset !== null);
            $presets[] = $preset->minPieces !== null && $preset->minPieces === $preset->maxPieces
                ? self::ofClass($preset->minPieces)
                : $preset;
        }

        return $presets;
    }

    /**
     * The bounds of a piece-count class (see CLASSES) - the exact count for any other count.
     */
    public static function ofClass(int $pieces): self
    {
        if (in_array($pieces, self::CLASSES, true) === false) {
            return new self($pieces, $pieces);
        }

        $tolerance = intdiv($pieces * self::CLASS_TOLERANCE_PERCENT, 100);

        return new self($pieces - $tolerance, $pieces + $tolerance);
    }

    /**
     * The class a puzzle's piece count belongs to: 504 → 500, an odd count outside every class → itself.
     */
    public static function classOf(int $piecesCount): int
    {
        foreach (self::CLASSES as $class) {
            if (self::ofClass($class)->contains($piecesCount)) {
                return $class;
            }
        }

        return $piecesCount;
    }

    /**
     * SQL twin of classOf() for grouping rows by class, e.g. sqlClassOf('puzzle.pieces_count').
     * Filter rows with sqlWithinClasses() - a CASE in a WHERE cannot use the pieces_count index.
     */
    public static function sqlClassOf(string $column): string
    {
        $cases = '';

        foreach (self::CLASSES as $class) {
            $range = self::ofClass($class);
            $cases .= sprintf(' WHEN %s BETWEEN %d AND %d THEN %d', $column, $range->minPieces, $range->maxPieces, $class);
        }

        return sprintf('(CASE%s ELSE %s END)', $cases, $column);
    }

    /**
     * Index-friendly condition: the column is within the class of one of these counts.
     *
     * @param list<int> $classes
     */
    public static function sqlWithinClasses(string $column, array $classes): string
    {
        if ($classes === []) {
            return 'false';
        }

        $conditions = array_map(static function (int $class) use ($column): string {
            $range = self::ofClass($class);

            return sprintf('%s BETWEEN %d AND %d', $column, $range->minPieces, $range->maxPieces);
        }, $classes);

        return '(' . implode(' OR ', $conditions) . ')';
    }

    /**
     * The class these bounds are exactly (495-505 → 500), null for any other range.
     */
    public function classCount(): null|int
    {
        foreach (self::CLASSES as $class) {
            $range = self::ofClass($class);

            if ($this->minPieces === $range->minPieces && $this->maxPieces === $range->maxPieces) {
                return $class;
            }
        }

        return null;
    }

    public function isUnbounded(): bool
    {
        return $this->minPieces === null && $this->maxPieces === null;
    }

    public function toParam(): string
    {
        if ($this->minPieces !== null && $this->minPieces === $this->maxPieces) {
            return (string) $this->minPieces;
        }

        if ($this->isUnbounded()) {
            return '';
        }

        return ($this->minPieces ?? '') . '-' . ($this->maxPieces ?? '');
    }

    public function label(): string
    {
        $param = $this->toParam();

        if (isset(self::PRESETS[$param])) {
            return self::PRESETS[$param];
        }

        $class = $this->classCount();

        if ($class !== null) {
            return (string) $class;
        }

        return match (true) {
            $this->minPieces === null => '≤ ' . $this->maxPieces,
            $this->maxPieces === null => $this->minPieces . '+',
            $this->minPieces === $this->maxPieces => (string) $this->minPieces,
            default => $this->minPieces . '–' . $this->maxPieces,
        };
    }

    public function contains(int $piecesCount): bool
    {
        return ($this->minPieces === null || $piecesCount >= $this->minPieces)
            && ($this->maxPieces === null || $piecesCount <= $this->maxPieces);
    }

    private static function isValidCount(int $count): bool
    {
        return $count >= 1 && $count <= self::MAX_PIECES;
    }
}
