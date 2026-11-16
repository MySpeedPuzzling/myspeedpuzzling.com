<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Exceptions;

/**
 * Pieces placed of an unfinished result: at least 1 and fewer than the puzzle has - all of them placed is a finished
 * solve, which has a time (allPlaced()).
 */
final class InvalidPiecesPlaced extends \Exception
{
    public function __construct(
        readonly public int $piecesPlaced,
        readonly public int $puzzlePiecesCount,
    ) {
        parent::__construct(sprintf('%d pieces placed of a %d-piece puzzle is no unfinished result.', $piecesPlaced, $puzzlePiecesCount));
    }

    public function allPlaced(): bool
    {
        return $this->piecesPlaced >= $this->puzzlePiecesCount;
    }
}
