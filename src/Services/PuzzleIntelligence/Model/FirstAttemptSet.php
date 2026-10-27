<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\PuzzleIntelligence\Model;

use SpeedPuzzling\Web\Services\PuzzleIntelligence\PaceTimeline;

/**
 * Every qualifying first attempt (PlayerBaselineCalculator's one rule) in memory, column by column, with its
 * difficulty index (time ÷ the solver's pace at the solve, PaceTimeline) and the moment that index is known from - the
 * one load the insights batch's difficulty step and the prediction model step share, and what the evaluation command
 * works on. Moments are epoch seconds of (COALESCE(finished_at, tracked_at), tracked_at).
 *
 * Kept compact - the batch runs in a container of 512 MB: plain columns, one string per player and puzzle id,
 * the attempts of a player × piece count ("pair") a contiguous range (the rows come in the pace order), the attempts of
 * a puzzle one permutation with offsets, the index's "known from" moment only where it differs from the solve (a
 * solver's first pace). No small array per attempt, pair or puzzle.
 */
final class FirstAttemptSet
{
    public const float INDEX_CEILING = 5.0;

    /** @var list<string> */
    public array $id = [];
    /** @var list<string> */
    public array $player = [];
    /** @var list<string> */
    public array $puzzle = [];
    /** @var list<int> */
    public array $pieces = [];
    /** @var list<int> */
    public array $seconds = [];
    /** @var list<float> */
    public array $solved = [];
    /** @var list<float> */
    public array $tracked = [];
    /** @var list<null|float> the difficulty index (0 < index <= 5), null = none - the very value PuzzleDifficultyCalculator computes */
    public array $index = [];
    /** @var list<int> 1-based position of the attempt in its pair */
    public array $position = [];

    /** @var array<int, float> */
    private array $knownSolved = [];
    /** @var array<int, float> */
    private array $knownTracked = [];

    /** @var array<string, int> "player|pieces" => first attempt of the pair */
    private array $pairStart = [];
    /** @var array<string, int> */
    private array $pairCount = [];

    /** @var list<int> the attempts grouped by puzzle */
    private array $puzzleOrder = [];
    /** @var array<string, int> */
    private array $puzzleStart = [];
    /** @var array<string, int> */
    private array $puzzleCount = [];

    /**
     * @param iterable<array{id: string, player_id: string, puzzle_id: string, pieces_count: int|string, seconds_to_solve: int|string, solved_ts: float|int|string, tracked_ts: float|int|string, ...}> $rows
     *                                                                                                                                                                                                in the pace order (player, pieces, solve moment, id) for the batch; other orders are sorted first
     */
    public static function build(iterable $rows, PaceTimeline $timeline): self
    {
        $set = new self();
        // One string per player and puzzle id (hundreds of thousands of rows, a few thousand distinct ids)
        $players = [];
        $puzzles = [];
        $previous = null;
        $sorted = true;

        foreach ($rows as $row) {
            $player = $players[$row['player_id']] ??= $row['player_id'];
            $pieces = (int) $row['pieces_count'];
            $solved = (float) $row['solved_ts'];
            $tracked = (float) $row['tracked_ts'];
            $current = [$player, $pieces, $solved, $tracked, $row['id']];

            if ($previous !== null && ($previous <=> $current) > 0) {
                $sorted = false;
            }

            $previous = $current;
            $set->id[] = $row['id'];
            $set->player[] = $player;
            $set->puzzle[] = $puzzles[$row['puzzle_id']] ??= $row['puzzle_id'];
            $set->pieces[] = $pieces;
            $set->seconds[] = (int) $row['seconds_to_solve'];
            $set->solved[] = $solved;
            $set->tracked[] = $tracked;
        }

        unset($players, $puzzles, $previous);

        if ($sorted === false) {
            $set->sortIntoThePaceOrder();
        }

        $count = count($set->id);

        $indices = [];
        $position = [];

        for ($i = 0; $i < $count; $i++) {
            $player = $set->player[$i];
            $pieces = $set->pieces[$i];
            $solved = $set->solved[$i];
            $tracked = $set->tracked[$i];
            $seconds = $set->seconds[$i];
            $key = $player . '|' . $pieces;

            if (!isset($set->pairStart[$key])) {
                $set->pairStart[$key] = $i;
                $set->pairCount[$key] = 0;
            }

            $position[] = ++$set->pairCount[$key];

            $pace = $timeline->paceWithKnownFrom($player, $pieces, $solved, $tracked);
            $index = null;

            // PuzzleDifficultyCalculator::computeDifficultyIndices(): no index from a zero pace, a 0-second time or above the ceiling
            if ($pace !== null && $pace[0] > 0) {
                $candidate = $seconds / $pace[0];

                if ($candidate > 0.0 && $candidate <= self::INDEX_CEILING) {
                    $index = $candidate;

                    if ($pace[1] !== [$solved, $tracked]) {
                        $set->knownSolved[$i] = $pace[1][0];
                        $set->knownTracked[$i] = $pace[1][1];
                    }
                }
            }

            $indices[] = $index;
            $puzzle = $set->puzzle[$i];
            $set->puzzleCount[$puzzle] = ($set->puzzleCount[$puzzle] ?? 0) + 1;
        }

        $set->index = $indices;
        $set->position = $position;
        unset($indices, $position);

        // The attempts of every puzzle, as one permutation
        $offset = 0;

        foreach ($set->puzzleCount as $puzzle => $puzzleCount) {
            $set->puzzleStart[$puzzle] = $offset;
            $offset += $puzzleCount;
        }

        // In the order of the solves, so a puzzle's attempts come out chronological (sorted once, not per puzzle)
        $chronological = range(0, $count - 1);
        $solvedCopy = $set->solved;
        $trackedCopy = $set->tracked;
        array_multisort($solvedCopy, SORT_ASC, SORT_NUMERIC, $trackedCopy, SORT_ASC, SORT_NUMERIC, $chronological);
        unset($solvedCopy, $trackedCopy);

        $order = array_fill(0, $count, 0);
        $filled = [];

        foreach ($chronological as $i) {
            $puzzle = $set->puzzle[$i];
            $slot = $set->puzzleStart[$puzzle] + ($filled[$puzzle] ?? 0);
            $filled[$puzzle] = ($filled[$puzzle] ?? 0) + 1;
            $order[$slot] = $i;
        }

        $set->puzzleOrder = array_values($order);

        return $set;
    }

    private function sortIntoThePaceOrder(): void
    {
        $order = array_keys($this->id);
        usort($order, fn (int $a, int $b): int => [$this->player[$a], $this->pieces[$a], $this->solved[$a], $this->tracked[$a], $this->id[$a]]
            <=> [$this->player[$b], $this->pieces[$b], $this->solved[$b], $this->tracked[$b], $this->id[$b]]);

        $this->id = array_map(fn (int $i): string => $this->id[$i], $order);
        $this->player = array_map(fn (int $i): string => $this->player[$i], $order);
        $this->puzzle = array_map(fn (int $i): string => $this->puzzle[$i], $order);
        $this->pieces = array_map(fn (int $i): int => $this->pieces[$i], $order);
        $this->seconds = array_map(fn (int $i): int => $this->seconds[$i], $order);
        $this->solved = array_map(fn (int $i): float => $this->solved[$i], $order);
        $this->tracked = array_map(fn (int $i): float => $this->tracked[$i], $order);
    }

    public function count(): int
    {
        return count($this->puzzle);
    }

    /**
     * Drops the attempts' ids (~30 MB of strings) once nothing reads them any more - the candidate's fit after its flags
     * are loaded (CandidateModelRecalculator); every other column stays.
     */
    public function forgetIds(): void
    {
        $this->id = [];
    }

    /**
     * @return array{0: float, 1: float}
     */
    public function knownFrom(int $i): array
    {
        return isset($this->knownSolved[$i])
            ? [$this->knownSolved[$i], $this->knownTracked[$i]]
            : [$this->solved[$i], $this->tracked[$i]];
    }

    /**
     * @return iterable<string, array{0: int, 1: int}> "player|pieces" => [first attempt, number of attempts] in the insights order
     */
    public function pairs(): iterable
    {
        foreach ($this->pairStart as $key => $start) {
            yield (string) $key => [$start, $this->pairCount[$key]];
        }
    }

    /**
     * @return null|array{0: int, 1: int}
     */
    public function pair(string $key): null|array
    {
        return isset($this->pairStart[$key]) ? [$this->pairStart[$key], $this->pairCount[$key]] : null;
    }

    /**
     * The pair's attempt at a 1-based position.
     */
    public function attemptAt(string $pairKey, int $position): null|int
    {
        $start = $this->pairStart[$pairKey] ?? null;

        if ($start === null || $position < 1 || $position > $this->pairCount[$pairKey]) {
            return null;
        }

        return $start + $position - 1;
    }

    /**
     * @return list<string>
     */
    public function puzzles(): array
    {
        return array_map('strval', array_keys($this->puzzleStart));
    }

    /**
     * The puzzle's attempts in the order of the solves (solve moment, tracked_at).
     *
     * @return list<int>
     */
    public function attemptsOfPuzzle(string $puzzleId): array
    {
        $start = $this->puzzleStart[$puzzleId] ?? null;

        if ($start === null) {
            return [];
        }

        $attempts = [];

        for ($slot = $start, $end = $start + $this->puzzleCount[$puzzleId]; $slot < $end; $slot++) {
            $attempts[] = $this->puzzleOrder[$slot];
        }

        return $attempts;
    }

    /**
     * The puzzle's difficulty indices, one per first attempt with one - PuzzleDifficultyCalculator::calculateFromIndices().
     *
     * @return list<float>
     */
    public function indicesOfPuzzle(string $puzzleId): array
    {
        $indices = [];

        foreach ($this->attemptsOfPuzzle($puzzleId) as $i) {
            $index = $this->index[$i];

            if ($index !== null) {
                $indices[] = $index;
            }
        }

        return $indices;
    }
}
