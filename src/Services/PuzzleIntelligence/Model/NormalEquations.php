<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\PuzzleIntelligence\Model;

/**
 * Least squares accumulated row by row (XᵀX and Xᵀy - the rows themselves are never kept, a fit over a hundred
 * thousand samples stays a few kilobytes), solved without negative coefficients: a negative share of a variance means
 * nothing, so the most negative coefficient is fixed at 0 and the rest solved again.
 */
final class NormalEquations
{
    /** @var array<int, array<int, float>> */
    private array $gram;

    /** @var array<int, float> */
    private array $moment;

    public function __construct(
        private readonly int $columns,
    ) {
        $this->gram = array_fill(0, $columns, array_fill(0, $columns, 0.0));
        $this->moment = array_fill(0, $columns, 0.0);
    }

    /**
     * @param list<float> $row
     */
    public function add(array $row, float $target): void
    {
        foreach ($row as $a => $value) {
            if ($value == 0.0) {
                continue;
            }

            foreach ($row as $b => $other) {
                $this->gram[$a][$b] += $value * $other;
            }

            $this->moment[$a] += $value * $target;
        }
    }

    /**
     * @return list<float>
     */
    public function solveNonNegative(): array
    {
        $active = range(0, $this->columns - 1);
        $solution = array_fill(0, $this->columns, 0.0);

        while ($active !== []) {
            $coefficients = $this->solve($active);
            $negative = null;

            foreach ($active as $position => $column) {
                if ($coefficients[$position] < 0.0 && ($negative === null || $coefficients[$position] < $coefficients[$negative])) {
                    $negative = $position;
                }
            }

            if ($negative === null) {
                foreach ($active as $position => $column) {
                    $solution[$column] = $coefficients[$position];
                }

                return array_values($solution);
            }

            unset($active[$negative]);
            $active = array_values($active);
        }

        return $solution;
    }

    /**
     * Gaussian elimination with partial pivoting on the active columns.
     *
     * @param list<int> $columns
     * @return list<float>
     */
    private function solve(array $columns): array
    {
        $size = count($columns);
        $matrix = [];

        foreach ($columns as $a => $columnA) {
            $line = [];

            foreach ($columns as $columnB) {
                $line[] = $this->gram[$columnA][$columnB];
            }

            $line[] = $this->moment[$columnA];
            $matrix[$a] = $line;
        }

        for ($pivot = 0; $pivot < $size; $pivot++) {
            $best = $pivot;

            for ($row = $pivot + 1; $row < $size; $row++) {
                if (abs($matrix[$row][$pivot]) > abs($matrix[$best][$pivot])) {
                    $best = $row;
                }
            }

            [$matrix[$pivot], $matrix[$best]] = [$matrix[$best], $matrix[$pivot]];

            if (abs($matrix[$pivot][$pivot]) < 1e-12) {
                continue;
            }

            for ($row = 0; $row < $size; $row++) {
                if ($row === $pivot) {
                    continue;
                }

                $factor = $matrix[$row][$pivot] / $matrix[$pivot][$pivot];

                for ($column = $pivot; $column <= $size; $column++) {
                    $matrix[$row][$column] -= $factor * $matrix[$pivot][$column];
                }
            }
        }

        $solution = [];

        for ($row = 0; $row < $size; $row++) {
            $solution[] = abs($matrix[$row][$row]) < 1e-12 ? 0.0 : $matrix[$row][$size] / $matrix[$row][$row];
        }

        return $solution;
    }
}
