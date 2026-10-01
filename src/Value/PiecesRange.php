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
     * The quick-pick chips, shared by every piece-count filter: the most
     * solved counts (99 and 100 are one chip - both are "the 100 class"),
     * anything else goes through the custom from-to range.
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
            $presets[] = $preset;
        }

        return $presets;
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
