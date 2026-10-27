<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\PuzzleIntelligence\Model;

use SpeedPuzzling\Web\Services\PuzzleIntelligence\Prior\PriorContext;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\Prior\PuzzleWeightedBrandPrior;

/**
 * BrandPiecesPrior's fit in memory: the groups' sums and every puzzle's share in them - fitted from a FirstAttemptSet
 * (fit(), the batch's daily fit and the evaluation) or loaded from what the batch stored (PredictionModelRecalculator).
 * Builds the PriorContext of an attempt the way the live statements build it from the stored rows.
 */
final class PriorLookup
{
    /** @var null|array<string, array{0: float, 1: int}> "manufacturer|pieces" => [Σ puzzle mean log index, puzzles] (candidate) */
    private null|array $groupPuzzleMeans = null;

    /**
     * @param array<string, null|string> $manufacturerByPuzzle
     * @param array<string, array{0: float, 1: int}> $groupSums "manufacturer|pieces" => [sum of log indices, count]
     * @param array<string, array{0: float, 1: int}> $puzzleShares puzzle => its own [sum, count] in its group
     */
    public function __construct(
        public readonly array $manufacturerByPuzzle,
        public readonly array $groupSums,
        public readonly array $puzzleShares,
        /** @var array<string, int> puzzle => pieces count of the puzzles with a share (the candidate's puzzle-weighted prior) */
        public readonly array $piecesByPuzzle = [],
    ) {
    }

    /**
     * Fits the groups on the indices of attempts solved before $cutBefore (null = all).
     *
     * @param array<string, null|string> $manufacturerByPuzzle
     */
    public static function fit(FirstAttemptSet $set, array $manufacturerByPuzzle, null|float $cutBefore = null): self
    {
        /** @var array<string, array{0: float, 1: int}> $puzzleSums */
        $puzzleSums = [];

        foreach ($set->index as $i => $index) {
            if ($index === null || ($cutBefore !== null && $set->solved[$i] >= $cutBefore)) {
                continue;
            }

            $puzzle = (string) $set->puzzle[$i];

            if (($manufacturerByPuzzle[$puzzle] ?? null) === null) {
                continue;
            }

            $puzzleSums[$puzzle] ??= [0.0, 0];
            $puzzleSums[$puzzle][0] += log($index);
            $puzzleSums[$puzzle][1]++;
        }

        $groupSums = [];
        $pieces = [];

        foreach ($puzzleSums as $puzzle => [$sum, $count]) {
            $pieces[$puzzle] = $set->pieces[$set->attemptsOfPuzzle($puzzle)[0]];
            $key = $manufacturerByPuzzle[$puzzle] . '|' . $pieces[$puzzle];
            $groupSums[$key] ??= [0.0, 0];
            $groupSums[$key][0] += $sum;
            $groupSums[$key][1] += $count;
        }

        return new self($manufacturerByPuzzle, $groupSums, $puzzleSums, $pieces);
    }

    /**
     * The candidate model's puzzle-weighted brand × pieces prior of a puzzle (PuzzleWeightedBrandPrior) from the same
     * sums - the puzzle's own share left out; null without a brand or with too few other puzzles.
     */
    public function puzzleWeightedBrandPrior(string $puzzleId, int $piecesCount, float $pseudoPuzzles): null|float
    {
        $manufacturer = $this->manufacturerByPuzzle[$puzzleId] ?? null;

        if ($manufacturer === null) {
            return null;
        }

        if ($this->groupPuzzleMeans === null) {
            $this->groupPuzzleMeans = [];
            $piecesOf = $this->piecesByPuzzle;

            foreach ($this->puzzleShares as $puzzle => [$sum, $count]) {
                $puzzleManufacturer = $this->manufacturerByPuzzle[$puzzle] ?? null;
                $pieces = $piecesOf[$puzzle] ?? null;

                if ($puzzleManufacturer === null || $pieces === null || $count <= 0) {
                    continue;
                }

                $key = $puzzleManufacturer . '|' . $pieces;
                $this->groupPuzzleMeans[$key] ??= [0.0, 0];
                $this->groupPuzzleMeans[$key][0] += $sum / $count;
                $this->groupPuzzleMeans[$key][1]++;
            }
        }

        $group = $this->groupPuzzleMeans[$manufacturer . '|' . $piecesCount] ?? null;

        if ($group === null) {
            return null;
        }

        $own = $this->puzzleShares[$puzzleId] ?? null;
        $ownMean = $own !== null && $own[1] > 0 && ($this->piecesByPuzzle[$puzzleId] ?? null) === $piecesCount ? $own[0] / $own[1] : null;

        return PuzzleWeightedBrandPrior::fromSums($group[0], $group[1], $ownMean, $pseudoPuzzles);
    }

    public function contextFor(string $puzzleId, int $piecesCount): PriorContext
    {
        $manufacturer = $this->manufacturerByPuzzle[$puzzleId] ?? null;
        $group = $manufacturer !== null ? ($this->groupSums[$manufacturer . '|' . $piecesCount] ?? null) : null;
        $own = $this->puzzleShares[$puzzleId] ?? [0.0, 0];

        return new PriorContext(
            puzzleId: $puzzleId,
            piecesCount: $piecesCount,
            manufacturerId: $manufacturer,
            brandPiecesGroupSumLog: $group[0] ?? 0.0,
            brandPiecesGroupCount: $group[1] ?? 0,
            brandPiecesOwnSumLog: $group !== null ? $own[0] : 0.0,
            brandPiecesOwnCount: $group !== null ? $own[1] : 0,
        );
    }
}
