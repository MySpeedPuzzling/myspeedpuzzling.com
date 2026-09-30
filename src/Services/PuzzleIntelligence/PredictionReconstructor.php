<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\PuzzleIntelligence;

use DateTimeImmutable;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Results\TimePredictionResult;
use SpeedPuzzling\Web\Value\MetricConfidence;
use SpeedPuzzling\Web\Value\PredictionInputSolve;
use SpeedPuzzling\Web\Value\SolvingTimePrediction;
use SpeedPuzzling\Web\Value\TimePredictionSource;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * What today's prediction model would have predicted for past solves of one player, fed only with
 * the data that existed before each solve (docs/features/puzzle-intelligence/prediction-history.md).
 *
 * "Before" is the insights order: (COALESCE(finished_at, tracked_at), tracked_at). Everything the
 * live model reads from its tables is rebuilt here as of the solve - earlier attempts, the gap, the
 * player's improvement ratios, the player's baseline, the puzzle's difficulty - with the same
 * calculator code. Two documented approximations: the global improvement ratios are a snapshot at
 * the start of the solve's month, and the difficulty indices use the other players' baselines of
 * today (as the live difficulty does). Nothing from the solve itself or after it is ever used.
 *
 * Loads its inputs once per player: the player's solo times + the solves of the puzzles that need a
 * statistical prediction. The rest is PHP.
 */
final class PredictionReconstructor implements ResetInterface
{
    private const int PUZZLE_CHUNK_SIZE = 1000;
    // A snapshot ends before the 1st of a month, so only back-dated solves can still change it - a week
    // is fresh enough and spares the worker the month queries of every back-dated add
    private const int SNAPSHOT_CACHE_TTL = 7 * 86400;

    private null|float $scalingExponent = null;

    /** @var array<string, array<int, array<int, array<string, float>>>> month (Y-m) => pieces => from attempt => gap bucket => ratio */
    private array $globalRatioSnapshots = [];

    public function __construct(
        private readonly Connection $connection,
        private readonly PlayerBaselineCalculator $baselineCalculator,
        private readonly PuzzleDifficultyCalculator $difficultyCalculator,
        private readonly ImprovementRatioCalculator $improvementRatioCalculator,
        private readonly TimePredictionCalculator $calculator,
        private readonly CacheInterface $globalImprovementRatioSnapshotCache,
    ) {
    }

    public function reset(): void
    {
        $this->scalingExponent = null;
        $this->globalRatioSnapshots = [];
    }

    /**
     * @param list<string> $timeIds solo times with seconds of this player
     * @return array<string, SolvingTimePrediction> keyed by time id; ids that are not a solo time
     *                                              with seconds of the player are left out
     */
    public function reconstruct(string $playerId, array $timeIds): array
    {
        $targetIds = array_flip($timeIds);

        /** @var array<string, list<PredictionInputSolve>> $qualifyingByPuzzle chronological */
        $qualifyingByPuzzle = [];
        $targets = [];

        foreach ($this->fetchPlayerSolves($playerId) as $solve) {
            if ($solve->qualifying) {
                $qualifyingByPuzzle[$solve->puzzleId][] = $solve;
            }

            if (isset($targetIds[$solve->id])) {
                $targets[] = $solve;
            }
        }

        if ($targets === []) {
            return [];
        }

        $priorAttemptsOfTargets = [];
        $statisticalPuzzleIds = [];

        foreach ($targets as $target) {
            $prior = self::solvesBefore($qualifyingByPuzzle[$target->puzzleId] ?? [], $target);
            $priorAttemptsOfTargets[$target->id] = $prior;

            if ($prior === []) {
                $statisticalPuzzleIds[$target->puzzleId] = true;
            }
        }

        $playerTransitions = $this->playerTransitions($qualifyingByPuzzle);
        $firstAttemptCandidates = $this->firstAttemptCandidatesByPiecesCount($qualifyingByPuzzle);
        $puzzleFirstAttemptCandidates = $this->puzzleFirstAttemptCandidates(array_keys($statisticalPuzzleIds));

        $predictions = [];

        foreach ($targets as $target) {
            $prior = $priorAttemptsOfTargets[$target->id];

            $result = $prior !== []
                ? $this->personalPrediction($prior, $target, $playerTransitions)
                : $this->statisticalPrediction($target, $firstAttemptCandidates, $puzzleFirstAttemptCandidates[$target->puzzleId] ?? []);

            $predictions[$target->id] = $result !== null
                ? SolvingTimePrediction::predicted($result, TimePredictionSource::Reconstructed)
                : SolvingTimePrediction::notPredictable(TimePredictionSource::Reconstructed);
        }

        return $predictions;
    }

    /**
     * @param non-empty-list<PredictionInputSolve> $prior
     * @param list<array{transition: array{from_attempt: int, ratio: float, gap_days: float}, at: PredictionInputSolve}> $playerTransitions
     */
    private function personalPrediction(array $prior, PredictionInputSolve $target, array $playerTransitions): TimePredictionResult
    {
        /** @var non-empty-list<int> $times */
        $times = array_map(static fn (PredictionInputSolve $solve): int => $solve->seconds, $prior);
        $last = $prior[count($prior) - 1];

        $transition = TimePredictionCalculator::transitionFor(count($prior));
        $gapBucket = TimePredictionCalculator::classifyGap(
            TimePredictionCalculator::gapDays($target->solvedAt(), $last->solvedAt()),
        );

        // A transition exists from its later attempt on
        $transitionsBefore = [];

        foreach ($playerTransitions as $playerTransition) {
            if ($playerTransition['at']->isBefore($target)) {
                $transitionsBefore[] = $playerTransition['transition'];
            }
        }

        $playerRatio = null;

        foreach ($this->improvementRatioCalculator->playerRatiosFromTransitions($transitionsBefore) as $ratio) {
            if ($ratio['from_attempt'] === $transition) {
                $playerRatio = $ratio['median_ratio'];
            }
        }

        $globalRatios = $this->globalRatiosBefore($target->solvedAt())[$target->piecesCount][$transition] ?? [];

        return $this->calculator->personal(
            $times,
            TimePredictionCalculator::resolveRatio(
                $playerRatio,
                $globalRatios[$gapBucket] ?? null,
                // The "all" bucket is only needed to gap-correct a player ratio (as in GetPlayerPrediction)
                $playerRatio !== null ? ($globalRatios['all'] ?? null) : null,
            ),
        );
    }

    /**
     * @param array<int, array<string, list<PredictionInputSolve>>> $firstAttemptCandidates the player's, pieces count => puzzle => candidates
     * @param array<string, list<PredictionInputSolve>> $puzzleCandidates every solver of the target's puzzle, player => candidates
     */
    private function statisticalPrediction(PredictionInputSolve $target, array $firstAttemptCandidates, array $puzzleCandidates): null|TimePredictionResult
    {
        $baseline = $this->baselineBefore($target, $firstAttemptCandidates);

        if ($baseline === null) {
            return null;
        }

        $firstAttempts = [];

        foreach ($puzzleCandidates as $candidates) {
            $firstAttempt = self::firstBefore($candidates, $target);

            if ($firstAttempt !== null && $firstAttempt->solverBaseline !== null) {
                $firstAttempts[] = ['seconds_to_solve' => $firstAttempt->seconds, 'baseline_seconds' => $firstAttempt->solverBaseline];
            }
        }

        $difficulty = $this->difficultyCalculator->calculateFromSolves($firstAttempts);

        // Same condition as GetPlayerPrediction: a score and not "insufficient" (fewer than 5 indices)
        if ($difficulty['difficulty_score'] === null || $difficulty['confidence'] === MetricConfidence::Insufficient) {
            return null;
        }

        return $this->calculator->statistical(
            baselineSeconds: $baseline,
            difficultyScore: $difficulty['difficulty_score'],
            p25: $difficulty['indices_p25'],
            p75: $difficulty['indices_p75'],
        );
    }

    /**
     * The player's baseline for the target's piece count as the recalculation would have computed it
     * right before the solve: direct from ≥ 5 first attempts (decay measured to the solve), otherwise
     * interpolated/extrapolated from the other piece counts' direct baselines - only when the player
     * had solved that piece count before at all (PlayerBaselineCalculator::findBaselineGaps()).
     *
     * @param array<int, array<string, list<PredictionInputSolve>>> $firstAttemptCandidates
     */
    private function baselineBefore(PredictionInputSolve $target, array $firstAttemptCandidates): null|int
    {
        $firstAttempts = self::firstAttemptsBefore($firstAttemptCandidates[$target->piecesCount] ?? [], $target);

        if (count($firstAttempts) >= PlayerBaselineCalculator::MINIMUM_SOLVE_COUNT) {
            return $this->baselineCalculator->weightedBaselineSeconds($firstAttempts, $target->solvedAt());
        }

        if ($firstAttempts === []) {
            return null;
        }

        $directBaselines = [];

        foreach ($firstAttemptCandidates as $piecesCount => $candidatesByPuzzle) {
            if ($piecesCount === $target->piecesCount) {
                continue;
            }

            $otherFirstAttempts = self::firstAttemptsBefore($candidatesByPuzzle, $target);

            if (count($otherFirstAttempts) >= PlayerBaselineCalculator::MINIMUM_SOLVE_COUNT) {
                $directBaselines[$piecesCount] = $this->baselineCalculator->weightedBaselineSeconds($otherFirstAttempts, $target->solvedAt());
            }
        }

        ksort($directBaselines);

        $this->scalingExponent ??= $this->baselineCalculator->computeScalingExponent();

        return $this->baselineCalculator->gapBaseline($target->piecesCount, $directBaselines, $this->scalingExponent)['baseline_seconds'] ?? null;
    }

    /**
     * @param array<string, list<PredictionInputSolve>> $candidatesByPuzzle
     * @return list<array{seconds: int, solved_at: DateTimeImmutable}>
     */
    private static function firstAttemptsBefore(array $candidatesByPuzzle, PredictionInputSolve $target): array
    {
        $firstAttempts = [];

        foreach ($candidatesByPuzzle as $candidates) {
            $firstAttempt = self::firstBefore($candidates, $target);

            if ($firstAttempt !== null) {
                $firstAttempts[] = ['seconds' => $firstAttempt->seconds, 'solved_at' => $firstAttempt->solvedAt()];
            }
        }

        return $firstAttempts;
    }

    /**
     * The first attempt as of the target: the candidates come in the insights preference order
     * (flagged first_attempt, then the oldest), so it is the first one solved before the target -
     * a later solve flagged first_attempt must not pull the future in.
     *
     * @param list<PredictionInputSolve> $candidates
     */
    private static function firstBefore(array $candidates, PredictionInputSolve $target): null|PredictionInputSolve
    {
        foreach ($candidates as $candidate) {
            if ($candidate->isBefore($target)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * @param list<PredictionInputSolve> $solves
     * @return list<PredictionInputSolve>
     */
    private static function solvesBefore(array $solves, PredictionInputSolve $target): array
    {
        return array_values(array_filter(
            $solves,
            static fn (PredictionInputSolve $solve): bool => $solve->isBefore($target),
        ));
    }

    /**
     * Consecutive attempts of every puzzle, each with the later attempt it exists from.
     *
     * @param array<string, list<PredictionInputSolve>> $qualifyingByPuzzle
     * @return list<array{transition: array{from_attempt: int, ratio: float, gap_days: float}, at: PredictionInputSolve}>
     */
    private function playerTransitions(array $qualifyingByPuzzle): array
    {
        $transitions = [];

        foreach ($qualifyingByPuzzle as $solves) {
            for ($i = 1, $count = count($solves); $i < $count; $i++) {
                $transition = ImprovementRatioCalculator::transition(
                    $solves[$i - 1]->seconds,
                    $solves[$i]->seconds,
                    $i,
                    ($solves[$i]->solved - $solves[$i - 1]->solved) / 86400.0,
                );

                if ($transition !== null) {
                    $transitions[] = ['transition' => $transition, 'at' => $solves[$i]];
                }
            }
        }

        return $transitions;
    }

    /**
     * @param array<string, list<PredictionInputSolve>> $qualifyingByPuzzle
     * @return array<int, array<string, list<PredictionInputSolve>>> pieces count => puzzle => candidates in first-attempt preference order
     */
    private function firstAttemptCandidatesByPiecesCount(array $qualifyingByPuzzle): array
    {
        $candidates = [];

        foreach ($qualifyingByPuzzle as $puzzleId => $solves) {
            if ($solves !== []) {
                $candidates[$solves[0]->piecesCount][$puzzleId] = self::inFirstAttemptOrder($solves);
            }
        }

        return $candidates;
    }

    /**
     * Qualifying solves of every player on the given puzzles, with that player's baseline of
     * today for the piece count (the live difficulty joins player_baseline the same way).
     *
     * @param list<string> $puzzleIds
     * @return array<string, array<string, list<PredictionInputSolve>>> puzzle => player => candidates in first-attempt preference order
     */
    private function puzzleFirstAttemptCandidates(array $puzzleIds): array
    {
        $byPuzzleAndPlayer = [];

        foreach (array_chunk($puzzleIds, self::PUZZLE_CHUNK_SIZE) as $chunk) {
            /** @var list<array{id: string, puzzle_id: string, player_id: string, pieces_count: int|string, seconds_to_solve: int|string, first_attempt: bool, solved_ts: float|string, tracked_ts: float|string, baseline_seconds: int|string}> $rows */
            $rows = $this->connection->fetchAllAssociative(
                <<<'SQL'
                SELECT
                    pst.id,
                    pst.puzzle_id,
                    pst.player_id,
                    p.pieces_count,
                    pst.seconds_to_solve,
                    pst.first_attempt,
                    EXTRACT(EPOCH FROM COALESCE(pst.finished_at, pst.tracked_at)) AS solved_ts,
                    EXTRACT(EPOCH FROM pst.tracked_at) AS tracked_ts,
                    pb.baseline_seconds
                FROM puzzle_solving_time pst
                JOIN puzzle p ON p.id = pst.puzzle_id
                JOIN player_baseline pb ON pb.player_id = pst.player_id AND pb.pieces_count = p.pieces_count
                WHERE pst.puzzle_id IN (:puzzleIds)
                    AND pst.puzzling_type = 'solo'
                    AND pst.suspicious = false
                    AND pst.seconds_to_solve IS NOT NULL
                    AND pst.unboxed = false
                SQL,
                ['puzzleIds' => $chunk],
                ['puzzleIds' => ArrayParameterType::STRING],
            );

            foreach ($rows as $row) {
                $byPuzzleAndPlayer[$row['puzzle_id']][$row['player_id']][] = new PredictionInputSolve(
                    id: $row['id'],
                    puzzleId: $row['puzzle_id'],
                    piecesCount: (int) $row['pieces_count'],
                    seconds: (int) $row['seconds_to_solve'],
                    firstAttempt: (bool) $row['first_attempt'],
                    qualifying: true,
                    solved: (float) $row['solved_ts'],
                    tracked: (float) $row['tracked_ts'],
                    solverBaseline: (int) $row['baseline_seconds'],
                );
            }
        }

        $candidates = [];

        foreach ($byPuzzleAndPlayer as $puzzleId => $byPlayer) {
            foreach ($byPlayer as $playerId => $solves) {
                $candidates[$puzzleId][$playerId] = self::inFirstAttemptOrder($solves);
            }
        }

        return $candidates;
    }

    /**
     * The insights "first attempt" preference: solves flagged first_attempt first, then the oldest.
     *
     * @param list<PredictionInputSolve> $solves
     * @return list<PredictionInputSolve>
     */
    private static function inFirstAttemptOrder(array $solves): array
    {
        usort(
            $solves,
            static fn (PredictionInputSolve $a, PredictionInputSolve $b): int => [$b->firstAttempt, $a->solved, $a->tracked, $a->id] <=> [$a->firstAttempt, $b->solved, $b->tracked, $b->id],
        );

        return $solves;
    }

    /**
     * Every solo time with seconds of the player, chronological in the insights order.
     *
     * @return list<PredictionInputSolve>
     */
    private function fetchPlayerSolves(string $playerId): array
    {
        /** @var list<array{id: string, puzzle_id: string, pieces_count: int|string, seconds_to_solve: int|string, first_attempt: bool, suspicious: bool, unboxed: bool, solved_ts: float|string, tracked_ts: float|string}> $rows */
        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
            SELECT
                pst.id,
                pst.puzzle_id,
                p.pieces_count,
                pst.seconds_to_solve,
                pst.first_attempt,
                pst.suspicious,
                pst.unboxed,
                EXTRACT(EPOCH FROM COALESCE(pst.finished_at, pst.tracked_at)) AS solved_ts,
                EXTRACT(EPOCH FROM pst.tracked_at) AS tracked_ts
            FROM puzzle_solving_time pst
            JOIN puzzle p ON p.id = pst.puzzle_id
            WHERE pst.player_id = :playerId
                AND pst.puzzling_type = 'solo'
                AND pst.seconds_to_solve IS NOT NULL
            ORDER BY COALESCE(pst.finished_at, pst.tracked_at), pst.tracked_at, pst.id
            SQL,
            ['playerId' => $playerId],
        );

        return array_map(static fn (array $row): PredictionInputSolve => new PredictionInputSolve(
            id: $row['id'],
            puzzleId: $row['puzzle_id'],
            piecesCount: (int) $row['pieces_count'],
            seconds: (int) $row['seconds_to_solve'],
            firstAttempt: (bool) $row['first_attempt'],
            qualifying: !$row['suspicious'] && !$row['unboxed'],
            solved: (float) $row['solved_ts'],
            tracked: (float) $row['tracked_ts'],
        ), $rows);
    }

    /**
     * Snapshot of the global ratios from the transitions before the 1st of the solve's month.
     * Cached (not only memoised): the messenger worker resets this service after every message.
     *
     * @return array<int, array<int, array<string, float>>>
     */
    private function globalRatiosBefore(DateTimeImmutable $solvedAt): array
    {
        $monthStart = $solvedAt->modify('first day of this month midnight');
        $month = $monthStart->format('Y-m');

        return $this->globalRatioSnapshots[$month] ??= $this->globalImprovementRatioSnapshotCache->get(
            'global_ratios_before_' . $month,
            function (ItemInterface $item) use ($monthStart): array {
                $item->expiresAfter(self::SNAPSHOT_CACHE_TTL);

                return $this->improvementRatioCalculator->globalRatiosBefore($monthStart);
            },
        );
    }
}
