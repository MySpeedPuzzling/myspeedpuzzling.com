<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\Insights;

use SpeedPuzzling\Web\Query\GetInsightsRatingInputs;
use SpeedPuzzling\Web\Query\GetInsightsShelf;
use SpeedPuzzling\Web\Query\GetPlayerPredictions;
use SpeedPuzzling\Web\Results\Insights\ShelfPick;
use SpeedPuzzling\Web\Results\Insights\ShelfPicksResult;
use SpeedPuzzling\Web\Results\Insights\ShelfPuzzle;
use SpeedPuzzling\Web\Results\TimePredictionResult;
use SpeedPuzzling\Web\Services\Insights\Model\RatingWhatIf;
use SpeedPuzzling\Web\Value\PiecesRange;
use SpeedPuzzling\Web\Value\ShelfPickSource;

/**
 * "What from my shelf suits me?" (docs/features/insights/computation.md §4c.7): the unsolved shelf puzzles with an
 * expected time (GetPlayerPredictions in bulk: your pace × the puzzle's difficulty), ranked by the share of its solvers
 * the expected time beats - the place you would likely take. Members only: free players get the number of unsolved
 * shelf puzzles (one statement); members seven at most whatever the shelf's size.
 */
readonly final class ShelfPicks
{
    public const int SHOWN = 12;

    /** "Faster than N % of its solvers" only from this many solvers on */
    public const int MINIMUM_SOLVERS = 5;

    public function __construct(
        private GetInsightsShelf $getInsightsShelf,
        private GetPlayerPredictions $getPlayerPredictions,
        private GetInsightsRatingInputs $getInsightsRatingInputs,
    ) {
    }

    public function build(string $playerId, bool $isMember, null|PiecesRange $pieces = null): ShelfPicksResult
    {
        $shelf = array_values(array_filter(
            $this->getInsightsShelf->unsolved($playerId),
            static fn (ShelfPuzzle $puzzle): bool => $pieces === null || $pieces->contains($puzzle->puzzle->piecesCount),
        ));

        if ($isMember === false || $shelf === []) {
            return new ShelfPicksResult(locked: $isMember === false, unsolvedOnShelf: count($shelf), withExpectedTime: 0, picks: []);
        }

        $estimates = $this->estimates($playerId, $shelf);
        $times = $this->solverTimes(array_map(strval(...), array_keys($estimates)));
        $picks = [];

        foreach ($shelf as $puzzle) {
            $estimate = $estimates[$puzzle->puzzle->puzzleId] ?? null;

            if ($estimate === null) {
                continue;
            }

            $solverTimes = $times[$puzzle->puzzle->puzzleId] ?? [];

            $picks[] = new ShelfPick(
                puzzle: $puzzle->puzzle,
                expectedSeconds: $estimate->predictedSeconds,
                expectedLowSeconds: $estimate->rangeLowSeconds,
                expectedHighSeconds: $estimate->rangeHighSeconds,
                source: ShelfPickSource::Statistical,
                fasterThanShare: count($solverTimes) >= self::MINIMUM_SOLVERS ? RatingWhatIf::percentileInserting($solverTimes, $estimate->predictedSeconds) : null,
                solverCount: count($solverTimes),
                difficultyTier: $puzzle->difficultyTier,
                medianSoloSeconds: $puzzle->medianSoloSeconds,
            );
        }

        $withExpectedTime = count($picks);

        usort($picks, static fn (ShelfPick $a, ShelfPick $b): int => ($b->fasterThanShare ?? -1.0) <=> ($a->fasterThanShare ?? -1.0)
            ?: $a->expectedSeconds <=> $b->expectedSeconds
            ?: strcmp($a->puzzle->puzzleId, $b->puzzle->puzzleId));

        return new ShelfPicksResult(false, count($shelf), $withExpectedTime, array_slice($picks, 0, self::SHOWN));
    }

    /**
     * The expected time of each shelf puzzle that has one. Phase 5d: puzzles without a statistical prediction get the
     * picture-only estimate here (ShelfPickSource::ImageEstimate).
     *
     * @param list<ShelfPuzzle> $shelf
     *
     * @return array<string, TimePredictionResult>
     */
    private function estimates(string $playerId, array $shelf): array
    {
        $rated = [];

        foreach ($shelf as $puzzle) {
            if ($puzzle->difficulty !== null) {
                $rated[] = $puzzle->puzzle->puzzleId;
            }
        }

        return $rated !== [] ? $this->getPlayerPredictions->forPuzzles($playerId, $rated) : [];
    }

    /**
     * The public first-attempt times of each puzzle: the rating's stored lists where a puzzle has one (500 pieces, 9+
     * solvers - most of a shelf), the rest aggregated from the results.
     *
     * @param list<string> $puzzleIds
     *
     * @return array<string, list<int>>
     */
    private function solverTimes(array $puzzleIds): array
    {
        $times = [];

        foreach ($this->getInsightsRatingInputs->ratingTimes($puzzleIds, withBestTimes: false) as $puzzleId => $lists) {
            $times[$puzzleId] = $lists->firstAttemptTimes;
        }

        $rest = array_values(array_filter($puzzleIds, static fn (string $puzzleId): bool => isset($times[$puzzleId]) === false));

        return $rest !== [] ? [...$times, ...$this->getInsightsShelf->firstAttemptTimes($rest)] : $times;
    }
}
