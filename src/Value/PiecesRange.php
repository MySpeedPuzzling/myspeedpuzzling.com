<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

use InvalidArgumentException;
use SpeedPuzzling\Web\Results\PiecesFilter;

/**
 * Inclusive piece-count bounds for the puzzle search. A null bound means
 * "unbounded on that side"; an exact count is both bounds equal.
 *
 * The website only ever needs the handful of ranges PiecesFilter enumerates,
 * the API lets clients pick arbitrary bounds - both end up here, so SearchPuzzle
 * has one notion of a piece-count filter.
 */
final readonly class PiecesRange
{
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

    public static function fromFilter(PiecesFilter $filter): self
    {
        return match ($filter) {
            PiecesFilter::Any => self::any(),
            PiecesFilter::UpTo499 => new self(1, 499),
            PiecesFilter::Exactly500 => new self(500, 500),
            PiecesFilter::UpTo999 => new self(501, 999),
            PiecesFilter::Exactly1000 => new self(1000, 1000),
            PiecesFilter::MoreThan1000 => new self(1001, null),
        };
    }

    public function isUnbounded(): bool
    {
        return $this->minPieces === null && $this->maxPieces === null;
    }
}
