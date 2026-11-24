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
     * @param list<null|string> $brands the catalogue brand (exact name) preferred for each puzzle position, null = any
     * @param list<array{brand: string, name: string}> $secretPuzzles new secret puzzles made for the round instead of
     *     catalogue ones - hidden entirely, revealed when the stopwatch starts with the tick (SPWS demo)
     * @param list<string> $unboxedLabels labels of the round's puzzles unboxed on their own (the rest not)
     * @param list<int> $piecesOfPuzzles the pieces of each puzzle position, empty = $piecesPerPuzzle for every one
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
        public array $brands = [],
        public array $secretPuzzles = [],
        public array $unboxedLabels = [],
        // Table names on this (first) round of its kind, every round of the kind gets the same list (SPWS hall: A-H ×
        // 1-22 without H22; 2 = "A1 L" / "A1 R"); null = plain numbers
        public null|int $seatsPerTable = null,
        // A published round: the 5th entrant by name gets exactly the 4th's result - a shared place
        public bool $tie = false,
        public array $piecesOfPuzzles = [],
        // A published round's no-show: the n-th entrant from the end by name (0 = the last). Different per round, the
        // no-show entrant has results elsewhere and stays ranked in the standing (as_zero_pieces)
        public int $noShowFromEnd = 0,
        // A running round: its stopwatch runs from the round's scheduled start (started then, or scheduled while it lies
        // ahead) - the judge's "Finished now" works on the demo day (spws-demo-ux-leftovers.md §5)
        public bool $stopwatchFromStart = false,
        // A running round: only results that lie within this many minutes of the start are seeded (finished totals,
        // challenge 1 splits) - so from that long after the start every seeded time is in the clock's past; null = all
        public null|int $seededWithinMinutes = null,
    ) {
        // 1 = the time's-up entrant (second to last), which the planner would silently drop
        if ($noShowFromEnd < 0 || $noShowFromEnd === 1) {
            throw new \InvalidArgumentException('A demo round\'s no-show cannot be the time\'s-up entrant');
        }
    }

    public function piecesOf(int $position): int
    {
        return $this->piecesOfPuzzles[$position] ?? $this->piecesPerPuzzle;
    }

    public function teamSize(): null|int
    {
        return $this->category === RoundCategory::Team ? 4 : null;
    }
}
