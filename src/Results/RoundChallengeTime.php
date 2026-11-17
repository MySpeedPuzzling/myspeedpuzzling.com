<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use SpeedPuzzling\Web\Value\RoundEntryPuzzles;

/**
 * One challenge of a solve-all block as a standing's round cell shows it (real events W4, world-series-rules.md
 * "Review amendments"): the puzzle's label (never its name - a label is public before the reveal) and its own time, its
 * split minus the previous puzzle's (RoundEntryPuzzles::duration()). Only for pick rounds of K ≥ 2 with splits.
 */
readonly final class RoundChallengeTime
{
    public function __construct(
        public string $label,
        public int $seconds,
    ) {
    }

    /**
     * The entry's finished puzzles with their own time known, in solving order - empty for every other round, an entry
     * without a record and a record without splits.
     *
     * @param array<string, string> $labelsByRoundPuzzleId lower-case competition_round_puzzle.id => its effective label
     * @return list<self>
     */
    public static function listOf(null|RoundEntryPuzzles $record, null|int $toSolve, array $labelsByRoundPuzzleId): array
    {
        if ($record === null || $toSolve === null || $toSolve < 2) {
            return [];
        }

        $times = [];

        foreach ($record->items as $index => $item) {
            $duration = $item->done ? $record->duration($index) : null;

            if ($duration !== null) {
                $times[] = new self($labelsByRoundPuzzleId[strtolower($item->roundPuzzleId)] ?? '?', $duration);
            }
        }

        return $times;
    }

    /**
     * @return array{label: string, seconds: int}
     */
    public function toArray(): array
    {
        return ['label' => $this->label, 'seconds' => $this->seconds];
    }
}
