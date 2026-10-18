<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\Insights\Model;

use DateTimeImmutable;
use SpeedPuzzling\Web\Results\Insights\InsightsPuzzleRef;
use SpeedPuzzling\Web\Results\Insights\PendingQualification;
use SpeedPuzzling\Web\Results\Insights\PuzzleRatingTimeLists;
use SpeedPuzzling\Web\Results\Insights\RatingMove;
use SpeedPuzzling\Web\Results\Insights\RatingSolvedPuzzle;
use SpeedPuzzling\Web\Results\Insights\ShelfPuzzle;
use SpeedPuzzling\Web\Results\TimePredictionResult;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\MspRatingCalculator;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\MspRatingFormula;
use SpeedPuzzling\Web\Value\RatingMoveKind;
use SpeedPuzzling\Web\Value\RatingStanding;

/**
 * A player's MSP rating at one piece count rebuilt from the stored entries, and every what-if on it: re-solves,
 * first tries from the shelf, defending entries about to leave the window, puzzles waiting to qualify
 * (docs/features/insights/computation.md §4c.2). Pure arithmetic over what RatingModelLoader read - MspRatingFormula
 * and the sorted time lists, so a what-if that changes nothing reproduces the stored rating.
 *
 * Rating numbers inside are the formula's (0-1.x); results handed out are ladder points (× DISPLAY_SCALE).
 */
final class RatingModel
{
    public const float DISPLAY_SCALE = 1000.0;

    /** A move worth less than this many ladder points is not one */
    public const float MINIMUM_GAIN = 0.05;

    /** An entry leaving the window within this many days can be defended */
    public const int DEFEND_WITHIN_DAYS = 60;

    /** "Near the ladder": at most this many new results away */
    public const int LADDER_REACH = 10;

    /** Pending qualification: puzzles with at least this many public first-attempt solvers */
    public const int PENDING_MINIMUM_SOLVERS = MspRatingCalculator::NEAR_QUALIFYING_MIN_SOLVERS;

    /**
     * @param array<string, RatingSolvedPuzzle> $solved
     * @param list<float> $ladder everybody else's ratings, best first
     * @param list<RatingMove> $moves biggest gain first
     * @param list<array{puzzleId: string, firstAttempt: null|float, bestTime: float}> $moveEffects the entries each move sets, parallel to $moves
     * @param list<PendingQualification> $pending
     */
    private function __construct(
        public readonly DateTimeImmutable $now,
        public readonly int $piecesCount,
        public readonly bool $private,
        public readonly PortfolioScore $firstAttempts,
        public readonly PortfolioScore $bestTimes,
        public readonly array $solved,
        public readonly int $firstAttemptsInWindow,
        public readonly int $resultsInWindow,
        public readonly array $ladder,
        public readonly array $moves,
        public readonly array $moveEffects,
        public readonly array $pending,
    ) {
    }

    /**
     * @param list<RatingSolvedPuzzle> $solved
     * @param list<ShelfPuzzle> $shelf unsolved shelf puzzles (any piece count - others are skipped)
     * @param array<string, PuzzleRatingTimeLists> $timeLists
     * @param array<string, TimePredictionResult> $predictions personal for solved puzzles, statistical for the shelf
     * @param list<float> $ladder
     */
    public static function build(
        DateTimeImmutable $now,
        int $piecesCount,
        bool $private,
        array $solved,
        array $shelf,
        array $timeLists,
        array $predictions,
        array $ladder,
    ): self {
        $windowStart = MspRatingFormula::windowStart($now);
        $firstAttemptPoints = [];
        $bestTimePoints = [];
        $solvedById = [];
        $firstAttemptsInWindow = 0;
        $resultsInWindow = 0;

        foreach ($solved as $puzzle) {
            $solvedById[$puzzle->puzzleId] = $puzzle;

            if ($puzzle->firstAttemptEntry !== null) {
                $firstAttemptPoints[$puzzle->puzzleId] = $puzzle->firstAttemptEntry->points;
            }

            if ($puzzle->bestTimeEntry !== null) {
                $bestTimePoints[$puzzle->puzzleId] = $puzzle->bestTimeEntry->points;
            }

            if ($puzzle->firstAttemptSeconds !== null && $puzzle->firstAttemptAt !== null && $puzzle->firstAttemptAt >= $windowStart) {
                $firstAttemptsInWindow++;
            }

            if ($puzzle->latestAt >= $windowStart) {
                $resultsInWindow++;
            }
        }

        $firstAttempts = new PortfolioScore($firstAttemptPoints);
        $bestTimes = new PortfolioScore($bestTimePoints);
        $current = PortfolioScore::rating($firstAttempts->mean(), $bestTimes->mean(), $firstAttempts->count() > 0);

        if ($private) {
            return new self($now, $piecesCount, true, $firstAttempts, $bestTimes, $solvedById, $firstAttemptsInWindow, $resultsInWindow, $ladder, [], [], []);
        }

        $defendBefore = MspRatingFormula::windowStart($now->modify('+' . self::DEFEND_WITHIN_DAYS . ' days'));
        $candidates = [];

        // Re-solves (and defending entries about to leave): a better or at least fresh best time
        foreach ($solved as $puzzle) {
            $lists = $timeLists[$puzzle->puzzleId] ?? null;
            $prediction = $predictions[$puzzle->puzzleId] ?? null;

            if ($puzzle->puzzle === null || $lists === null || $lists->qualifies === false || $lists->difficultyWeight === null || $prediction === null) {
                continue;
            }

            $expected = max(1, $prediction->predictedSeconds);
            $newBest = min($puzzle->bestSeconds, $expected);
            $newPoints = RatingWhatIf::percentileReplacing($lists->bestTimes, $puzzle->bestSeconds, $newBest) * $lists->difficultyWeight;
            $rating = PortfolioScore::rating($firstAttempts->mean(), $bestTimes->meanWith($puzzle->puzzleId, $newPoints), $firstAttempts->count() > 0);
            $daysUntilLeaving = $puzzle->bestTimeEntry !== null && $puzzle->bestTimeEntry->solvedAt < $defendBefore
                ? self::daysUntilLeaving($puzzle->bestTimeEntry->solvedAt, $now)
                : null;

            $candidates[] = [
                'move' => new RatingMove(
                    kind: $daysUntilLeaving !== null ? RatingMoveKind::Defend : RatingMoveKind::Resolve,
                    puzzle: $puzzle->puzzle,
                    expectedSeconds: $expected,
                    currentBestSeconds: $puzzle->bestSeconds,
                    gain: ($rating - $current) * self::DISPLAY_SCALE,
                    gainPerHour: ($rating - $current) * self::DISPLAY_SCALE / ($expected / 3600),
                    daysUntilLeaving: $daysUntilLeaving,
                    solverCount: $lists->solverCount,
                    fasterThanShare: null,
                    onShelf: $puzzle->onShelf,
                ),
                'effect' => ['puzzleId' => $puzzle->puzzleId, 'firstAttempt' => null, 'bestTime' => $newPoints],
            ];
        }

        // First tries from the shelf: a new entry in both portfolios
        foreach ($shelf as $shelfPuzzle) {
            $puzzleId = $shelfPuzzle->puzzle->puzzleId;
            $lists = $timeLists[$puzzleId] ?? null;
            $prediction = $predictions[$puzzleId] ?? null;

            if ($shelfPuzzle->puzzle->piecesCount !== $piecesCount || isset($solvedById[$puzzleId]) || $lists === null || $lists->qualifies === false || $lists->difficultyWeight === null || $prediction === null) {
                continue;
            }

            $expected = max(1, $prediction->predictedSeconds);
            $firstAttemptPercentile = RatingWhatIf::percentileInserting($lists->firstAttemptTimes, $expected);
            $firstAttemptNew = $firstAttemptPercentile * $lists->difficultyWeight;
            $bestTimeNew = RatingWhatIf::percentileInserting($lists->bestTimes, $expected) * $lists->difficultyWeight;
            $rating = PortfolioScore::rating($firstAttempts->meanWith($puzzleId, $firstAttemptNew), $bestTimes->meanWith($puzzleId, $bestTimeNew), true);

            $candidates[] = [
                'move' => new RatingMove(
                    kind: RatingMoveKind::FirstTry,
                    puzzle: $shelfPuzzle->puzzle,
                    expectedSeconds: $expected,
                    currentBestSeconds: null,
                    gain: ($rating - $current) * self::DISPLAY_SCALE,
                    gainPerHour: ($rating - $current) * self::DISPLAY_SCALE / ($expected / 3600),
                    daysUntilLeaving: null,
                    solverCount: $lists->solverCount,
                    fasterThanShare: $firstAttemptPercentile,
                    onShelf: true,
                ),
                'effect' => ['puzzleId' => $puzzleId, 'firstAttempt' => $firstAttemptNew, 'bestTime' => $bestTimeNew],
            ];
        }

        $candidates = array_values(array_filter($candidates, static fn (array $candidate): bool => $candidate['move']->gain >= self::MINIMUM_GAIN));
        usort($candidates, static fn (array $a, array $b): int => $b['move']->gain <=> $a['move']->gain ?: strcmp($a['move']->puzzle->puzzleId, $b['move']->puzzle->puzzleId));

        return new self(
            now: $now,
            piecesCount: $piecesCount,
            private: false,
            firstAttempts: $firstAttempts,
            bestTimes: $bestTimes,
            solved: $solvedById,
            firstAttemptsInWindow: $firstAttemptsInWindow,
            resultsInWindow: $resultsInWindow,
            ladder: $ladder,
            moves: array_column($candidates, 'move'),
            moveEffects: array_column($candidates, 'effect'),
            pending: self::pending($solved, $timeLists, $firstAttempts, $bestTimes, $current, $now, $windowStart),
        );
    }

    /**
     * The rating the stored entries make (formula units).
     */
    public function rating(): float
    {
        return PortfolioScore::rating($this->firstAttempts->mean(), $this->bestTimes->mean(), $this->firstAttempts->count() > 0);
    }

    public function onLadder(): bool
    {
        return $this->private === false
            && $this->firstAttemptsInWindow >= MspRatingCalculator::MINIMUM_FIRST_ATTEMPTS
            && $this->resultsInWindow >= MspRatingCalculator::MINIMUM_TOTAL_SOLVES
            && $this->firstAttempts->count() >= MspRatingCalculator::MINIMUM_FIRST_ATTEMPTS;
    }

    /**
     * New results needed to get on the ladder: first tries on puzzles that count (they are results too) and results.
     */
    public function newResultsNeeded(): int
    {
        return max(
            max(0, MspRatingCalculator::MINIMUM_FIRST_ATTEMPTS - $this->firstAttempts->count()),
            max(0, MspRatingCalculator::MINIMUM_TOTAL_SOLVES - $this->resultsInWindow),
        );
    }

    public function standing(): RatingStanding
    {
        return match (true) {
            $this->private => RatingStanding::Private,
            $this->onLadder() => RatingStanding::OnLadder,
            $this->firstAttempts->count() === 0 && $this->bestTimes->count() === 0 => RatingStanding::NoEntries,
            $this->newResultsNeeded() <= self::LADDER_REACH => RatingStanding::NearLadder,
            default => RatingStanding::OffLadder,
        };
    }

    /**
     * The place a rating (formula units) would have on today's ladder: one more than the players rated higher.
     */
    public function place(float $rating): int
    {
        $low = 0;
        $high = count($this->ladder);

        // ladder is best first: the first index whose rating is not higher
        while ($low < $high) {
            $middle = ($low + $high) >> 1;

            if ($this->ladder[$middle] > $rating) {
                $low = $middle + 1;
            } else {
                $high = $middle;
            }
        }

        return $low + 1;
    }

    /**
     * The rating (formula units) after the best $count moves together.
     */
    public function ratingAfterBest(int $count): float
    {
        $firstAttemptChanges = [];
        $bestTimeChanges = [];

        foreach (array_slice($this->moveEffects, 0, $count) as $effect) {
            if ($effect['firstAttempt'] !== null) {
                $firstAttemptChanges[$effect['puzzleId']] = $effect['firstAttempt'];
            }

            $bestTimeChanges[$effect['puzzleId']] = $effect['bestTime'];
        }

        $firstAttempts = $this->firstAttempts->with($firstAttemptChanges);

        return PortfolioScore::rating($firstAttempts->mean(), $this->bestTimes->with($bestTimeChanges)->mean(), $firstAttempts->count() > 0);
    }

    /**
     * Days until an entry solved at $solvedAt leaves the 24-month window.
     */
    public static function daysUntilLeaving(DateTimeImmutable $solvedAt, DateTimeImmutable $now): int
    {
        $leavesAt = $solvedAt->modify('+' . MspRatingFormula::WINDOW_MONTHS . ' months');

        return $leavesAt > $now ? (int) $now->diff($leavesAt)->days : 0;
    }

    /**
     * @param list<RatingSolvedPuzzle> $solved
     * @param array<string, PuzzleRatingTimeLists> $timeLists
     *
     * @return list<PendingQualification>
     */
    private static function pending(
        array $solved,
        array $timeLists,
        PortfolioScore $firstAttempts,
        PortfolioScore $bestTimes,
        float $current,
        DateTimeImmutable $now,
        DateTimeImmutable $windowStart,
    ): array {
        $pending = [];

        foreach ($solved as $puzzle) {
            $lists = $timeLists[$puzzle->puzzleId] ?? null;

            if (
                $puzzle->puzzle === null
                || $lists === null
                || $lists->qualifies
                || $lists->difficultyWeight === null
                || $lists->solverCount < self::PENDING_MINIMUM_SOLVERS
                || $lists->solverCount >= MspRatingFormula::MIN_SOLVERS_PER_PUZZLE
            ) {
                continue;
            }

            $firstAttemptNew = null;
            $bestTimeNew = null;

            if ($puzzle->firstAttemptSeconds !== null && $puzzle->firstAttemptAt !== null && $puzzle->firstAttemptAt >= $windowStart) {
                $firstAttemptNew = MspRatingFormula::percentileInSortedTimes($puzzle->firstAttemptSeconds, $lists->firstAttemptTimes)
                    * $lists->difficultyWeight * MspRatingFormula::decay($puzzle->firstAttemptAt, $now);
            }

            if ($puzzle->latestAt >= $windowStart) {
                $bestTimeNew = MspRatingFormula::percentileInSortedTimes($puzzle->bestSeconds, $lists->bestTimes)
                    * $lists->difficultyWeight * MspRatingFormula::decay($puzzle->latestAt, $now);
            }

            if ($firstAttemptNew === null && $bestTimeNew === null) {
                continue;
            }

            $rating = PortfolioScore::rating(
                $firstAttemptNew !== null ? $firstAttempts->meanWith($puzzle->puzzleId, $firstAttemptNew) : $firstAttempts->mean(),
                $bestTimeNew !== null ? $bestTimes->meanWith($puzzle->puzzleId, $bestTimeNew) : $bestTimes->mean(),
                $firstAttempts->count() > 0 || $firstAttemptNew !== null,
            );

            $pending[] = new PendingQualification(
                puzzle: $puzzle->puzzle,
                solverCount: $lists->solverCount,
                solversNeeded: MspRatingFormula::MIN_SOLVERS_PER_PUZZLE - $lists->solverCount,
                gainWhenItCounts: ($rating - $current) * self::DISPLAY_SCALE,
            );
        }

        usort($pending, static fn (PendingQualification $a, PendingQualification $b): int => $b->gainWhenItCounts <=> $a->gainWhenItCounts
            ?: $a->solversNeeded <=> $b->solversNeeded
            ?: strcmp($a->puzzle->puzzleId, $b->puzzle->puzzleId));

        return $pending;
    }

    /**
     * Puzzle ids whose time lists a model needs: rated solved puzzles and the shelf.
     *
     * @param list<RatingSolvedPuzzle> $solved
     * @param list<ShelfPuzzle> $shelf
     *
     * @return list<string>
     */
    public static function timeListIds(array $solved, array $shelf, int $piecesCount): array
    {
        $ids = [];

        foreach ($solved as $puzzle) {
            if ($puzzle->qualifies !== null && $puzzle->puzzle !== null) {
                $ids[] = $puzzle->puzzleId;
            }
        }

        foreach ($shelf as $shelfPuzzle) {
            if ($shelfPuzzle->puzzle->piecesCount === $piecesCount) {
                $ids[] = $shelfPuzzle->puzzle->puzzleId;
            }
        }

        return $ids;
    }

    /**
     * The puzzles of timeListIds() whose first-attempt list a model reads: first tries from the shelf and puzzles
     * waiting to qualify (a re-solve reads the best times only).
     *
     * @param list<RatingSolvedPuzzle> $solved
     * @param list<ShelfPuzzle> $shelf
     *
     * @return list<string>
     */
    public static function firstAttemptListIds(array $solved, array $shelf, int $piecesCount): array
    {
        $ids = [];

        foreach ($solved as $puzzle) {
            if ($puzzle->qualifies === false && $puzzle->puzzle !== null) {
                $ids[] = $puzzle->puzzleId;
            }
        }

        foreach ($shelf as $shelfPuzzle) {
            if ($shelfPuzzle->puzzle->piecesCount === $piecesCount) {
                $ids[] = $shelfPuzzle->puzzle->puzzleId;
            }
        }

        return $ids;
    }

    public function puzzleRef(string $puzzleId): null|InsightsPuzzleRef
    {
        return ($this->solved[$puzzleId] ?? null)?->puzzle;
    }
}
