<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\PuzzleIntelligence\Group;

use SpeedPuzzling\Web\Services\PuzzleIntelligence\Model\FirstAttemptSet;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\PaceTimeline;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\TimePredictionCalculator;

/**
 * The shown difficulty of a puzzle as of any moment, for the puzzles with a pair/team result only - the as-of rule of
 * PredictionEngine::difficultyAt() (the indices known strictly before the moment, an index known from its solve or the
 * solver's first pace row - FirstAttemptSet::indexOf()), without holding every qualifying first attempt: the rows come
 * a puzzle at a time (GetGroupPuzzleFirstAttempts) and only each puzzle's index events stay, as packed lists sorted by
 * the moment they are known from, with running sums of their logs. ~10 MB on the production copy.
 */
final class GroupDifficultyTimeline
{
    /** @var array<string, list<float>> */
    private array $knownSolved = [];
    /** @var array<string, list<float>> */
    private array $knownTracked = [];
    /** @var array<string, list<float>> */
    private array $indices = [];
    /** @var array<string, list<float>> Σ log of the first n indices, n = 0..count */
    private array $logSums = [];
    /** @var array<string, array{0: int, 1: list<float>}> the first n known indices, sorted - at()'s running median */
    private array $sorted = [];

    /**
     * @param iterable<array{player_id: string, puzzle_id: string, pieces_count: int|string, seconds_to_solve: int|string, solved_ts: float|int|string, tracked_ts: float|int|string, ...}> $rows by puzzle
     */
    public static function build(iterable $rows, PaceTimeline $timeline): self
    {
        $built = new self();
        $puzzle = null;
        $events = [];

        foreach ($rows as $row) {
            if ($row['puzzle_id'] !== $puzzle) {
                if ($puzzle !== null) {
                    $built->addPuzzle($puzzle, $events);
                }

                $puzzle = $row['puzzle_id'];
                $events = [];
            }

            $known = FirstAttemptSet::indexOf($timeline, $row['player_id'], (int) $row['pieces_count'], (int) $row['seconds_to_solve'], (float) $row['solved_ts'], (float) $row['tracked_ts']);

            if ($known !== null) {
                $events[] = $known;
            }
        }

        if ($puzzle !== null) {
            $built->addPuzzle($puzzle, $events);
        }

        return $built;
    }

    /**
     * The puzzle's index count, sum of log indices and median score (k >= 5, rounded like the stored score) from the
     * indices known strictly before the moment.
     *
     * @return array{0: int, 1: float, 2: null|float}
     */
    public function at(string $puzzleId, float $solved, float $tracked): array
    {
        $knownSolved = $this->knownSolved[$puzzleId] ?? null;

        if ($knownSolved === null) {
            return [0, 0.0, null];
        }

        $knownTracked = $this->knownTracked[$puzzleId];
        $low = 0;
        $high = count($knownSolved);

        while ($low < $high) {
            $middle = intdiv($low + $high, 2);

            if (($knownSolved[$middle] <=> $solved ?: $knownTracked[$middle] <=> $tracked) < 0) {
                $low = $middle + 1;
            } else {
                $high = $middle;
            }
        }

        $k = $low;
        $sum = $this->logSums[$puzzleId][$k];

        if ($k < TimePredictionCalculator::RATED_INDICES) {
            return [$k, $sum, null];
        }

        // The results come in the order of the solves, so a puzzle's k only grows: its known indices are kept sorted
        // and the next ones inserted (a k below the kept one - never in the replay - sorts afresh)
        [$sortedCount, $sorted] = $this->sorted[$puzzleId] ?? [0, []];

        if ($k >= $sortedCount) {
            for ($next = $sortedCount; $next < $k; $next++) {
                self::insertSorted($sorted, $this->indices[$puzzleId][$next]);
            }

            $this->sorted[$puzzleId] = [$k, $sorted];
            $known = $sorted;
        } else {
            $known = array_slice($this->indices[$puzzleId], 0, $k);
            sort($known);
        }

        $middle = intdiv($k, 2);

        return [$k, $sum, round($k % 2 === 0 ? ($known[$middle - 1] + $known[$middle]) / 2.0 : $known[$middle], 4)];
    }

    /**
     * @param list<float> $sorted
     */
    private static function insertSorted(array &$sorted, float $value): void
    {
        $low = 0;
        $high = count($sorted);

        while ($low < $high) {
            $middle = intdiv($low + $high, 2);

            if ($sorted[$middle] < $value) {
                $low = $middle + 1;
            } else {
                $high = $middle;
            }
        }

        array_splice($sorted, $low, 0, [$value]);
    }

    /**
     * @param list<array{0: float, 1: float, 2: float}> $events index, known from (solved, tracked)
     */
    private function addPuzzle(string $puzzleId, array $events): void
    {
        if ($events === []) {
            return;
        }

        $solved = array_column($events, 1);
        $tracked = array_column($events, 2);
        $indices = array_column($events, 0);
        array_multisort($solved, SORT_ASC, SORT_NUMERIC, $tracked, SORT_ASC, SORT_NUMERIC, $indices);

        $sums = [0.0];
        $sum = 0.0;

        foreach ($indices as $index) {
            $sum += log($index);
            $sums[] = $sum;
        }

        $this->knownSolved[$puzzleId] = $solved;
        $this->knownTracked[$puzzleId] = $tracked;
        $this->indices[$puzzleId] = $indices;
        $this->logSums[$puzzleId] = $sums;
    }
}
