<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * One puzzle of a `pick` round entry's record (RoundEntryPuzzles): which of the round's puzzles - by its
 * `competition_round_puzzle.id`, never the catalogue puzzle id - whether it is finished, and optionally the elapsed
 * round time it was finished at (a cumulative split) or the pieces placed on it when the time ran out.
 */
final readonly class RoundEntryPuzzle
{
    public function __construct(
        // competition_round_puzzle.id, lower-cased
        public string $roundPuzzleId,
        public bool $done,
        // Done only: the elapsed round time when it was finished
        public null|int $atSeconds = null,
        // Not done only: the pieces placed when the time ran out
        public null|int $piecesPlaced = null,
    ) {
    }

    /**
     * Canonical key order: roundPuzzleId, done, then atSeconds / piecesPlaced when given.
     *
     * @return array{roundPuzzleId: string, done: bool, atSeconds?: int, piecesPlaced?: int}
     */
    public function toWire(): array
    {
        $wire = ['roundPuzzleId' => $this->roundPuzzleId, 'done' => $this->done];

        if ($this->atSeconds !== null) {
            $wire['atSeconds'] = $this->atSeconds;
        }

        if ($this->piecesPlaced !== null) {
            $wire['piecesPlaced'] = $this->piecesPlaced;
        }

        return $wire;
    }

    public function equals(self $other): bool
    {
        return $this->roundPuzzleId === $other->roundPuzzleId
            && $this->done === $other->done
            && $this->atSeconds === $other->atSeconds
            && $this->piecesPlaced === $other->piecesPlaced;
    }
}
