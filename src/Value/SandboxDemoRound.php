<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * One round of a sandbox demo event (docs/features/sandbox/README.md "Demo events") - what the command makes of it.
 */
readonly final class SandboxDemoRound
{
    public const string PUBLISHED = 'published';
    public const string RUNNING = 'running';
    public const string PLANNED = 'planned';

    /**
     * @param list<string> $labels the round puzzles' labels in their order (a block of challenges), empty = the letters
     * @param list<string> $divisions names of the event's divisions this round uses
     */
    public function __construct(
        public string $key,
        public string $name,
        public RoundCategory $category,
        // Days after the demo day (-1 = the day before)
        public int $dayOffset,
        // Local start time HH:MM in the event's zone
        public string $time,
        public int $minutesLimit,
        public int $piecesPerPuzzle,
        public int $puzzles = 1,
        // "Solve all N (order recorded)" - a `pick` round with K = N
        public bool $solveAll = false,
        public array $labels = [],
        public bool $unboxed = false,
        public bool $woodenPuzzle = false,
        // The round whose puzzle this round shares (shared puzzles S2) - null = its own puzzles
        public null|string $sharesPuzzleOf = null,
        // The first table number, tables given by name; null = copied from $tablesFrom
        public null|int $firstTable = null,
        // The round whose tables this round gets ("Use these tables in later rounds", W3)
        public null|string $tablesFrom = null,
        // self::PUBLISHED (all recorded, published) | self::RUNNING (some recorded) | self::PLANNED (nothing)
        public string $state = self::PLANNED,
        // Typical seconds an average entrant needs for one of its puzzles
        public int $baseSeconds = 2400,
        public array $divisions = [],
    ) {
    }

    public function teamSize(): null|int
    {
        return $this->category === RoundCategory::Team ? 4 : null;
    }
}
