<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value\DataImport;

/**
 * What PiecesReader made of one cell of a pieces column (docs/features/data-import/value-readers.md "Pieces").
 */
final readonly class ReadPieces
{
    /**
     * @param list<int> $numbers The numbers found in a value that is a problem, as written ("13x99" → 13, 99;
     *        "~1000" → 1000) - choices to offer, never taken by themselves
     */
    private function __construct(
        public PiecesCertainty $certainty,
        public null|int $pieces = null,
        public null|PiecesProblem $problem = null,
        /** The value as typed, when it says more than a number - what would go to the notes */
        public null|string $note = null,
        public array $numbers = [],
    ) {
    }

    public static function noPieces(): self
    {
        return new self(PiecesCertainty::NoPieces);
    }

    public static function certain(int $pieces): self
    {
        return new self(PiecesCertainty::Certain, $pieces);
    }

    public static function fromText(int $pieces): self
    {
        return new self(PiecesCertainty::ReadFromText, $pieces);
    }

    /**
     * @param list<int> $numbers
     */
    public static function problem(PiecesProblem $problem, null|string $note = null, array $numbers = []): self
    {
        return new self(PiecesCertainty::Problem, null, $problem, $note, $numbers);
    }
}
