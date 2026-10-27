<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\PuzzleIntelligence;

use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\PuzzleSolvingTime;
use SpeedPuzzling\Web\Entity\SolvingTimePredictionRecord;
use SpeedPuzzling\Web\Message\BackfillSolvingTimePredictions;
use SpeedPuzzling\Web\Query\GetPlayerPrediction;
use SpeedPuzzling\Web\Value\PredictorKind;
use SpeedPuzzling\Web\Value\SolveConditions;
use SpeedPuzzling\Web\Value\SolveMoment;
use SpeedPuzzling\Web\Value\SolvingTimePrediction;
use SpeedPuzzling\Web\Value\TimePredictionSource;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DispatchAfterCurrentBusStamp;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;
use Throwable;

/**
 * Stores what we predicted for a solve (docs/features/puzzle-intelligence/prediction-history.md).
 *
 * Live only when a time is added for today or yesterday: the insights tables are then the state the
 * site showed right before the solve - the new row is not flushed yet, so the sync PuzzleSolved
 * recalculation has not folded it in. A back-dated time would be predicted from tables that already
 * hold everything after its date, and an edited time is already in them itself - those two get a
 * reconstructed prediction from the async backfill message instead.
 *
 * Every live prediction is stored twice: as the shown prediction (the columns on the time, without prior-only
 * predictions - SolvingTimePrediction::forDisplay()) and as the statistical predictor's side-by-side row of the
 * current model version (solving_time_prediction). A first try also gets the `image` and `blend` predictors' rows
 * (Insights plan §5.4) - not predictable when the puzzle has no estimate of its current picture (a puzzle added
 * together with its first time never has one; the backfill reconstructs those rows once an out-of-fold estimate
 * exists) and, once the candidate model (v4) is fitted, its `candidate` row - never shown. All of them go in one
 * multi-row INSERT right after the handler's flush (LivePredictionRowsWriter): plain values computed here - the reads
 * that could fail ran inside the savepoint.
 */
readonly final class SolvingTimePredictor
{
    public function __construct(
        private GetPlayerPrediction $getPlayerPrediction,
        private Connection $connection,
        private ClockInterface $clock,
        private MessageBusInterface $messageBus,
        private LoggerInterface $logger,
        private LivePredictionRowsWriter $liveRows,
    ) {
    }

    /**
     * Call before persisting a new time.
     */
    public function predictAddedTime(PuzzleSolvingTime $time): void
    {
        if ($time->isPredictionPending() === false) {
            return;
        }

        if (self::isSolvedRecently($time) === false) {
            $this->scheduleReconstruction($time);

            return;
        }

        // A savepoint: a failing read inside the handler's transaction would otherwise abort it,
        // and storing a prediction must never cost the player their time
        $this->connection->beginTransaction();

        try {
            $predictions = $this->getPlayerPrediction->sideBySide(
                $time->player->id->toString(),
                $time->puzzle->id->toString(),
                excludeTimeId: $time->id->toString(),
                before: SolveMoment::of($time->finishedAt, $time->trackedAt),
                conditions: new SolveConditions(unboxed: $time->unboxed, competition: $time->competition !== null || $time->competitionSeries !== null),
            );

            $this->connection->commit();
        } catch (Throwable $e) {
            $this->connection->rollBack();

            $this->logger->warning('Could not predict an added solving time - left for the backfill', [
                'timeId' => $time->id->toString(),
                'exception' => $e,
            ]);

            $this->scheduleReconstruction($time);

            return;
        }

        $prediction = $predictions->statistical;
        $now = $this->clock->now();

        $time->recordPrediction($prediction->forDisplay(), $now);

        $records = [new SolvingTimePredictionRecord(Uuid::uuid7(), $time, PredictorKind::Statistical, $prediction->modelVersion, $prediction, $now)];

        if ($predictions->firstTry) {
            // Without an estimate: not predictable, and no estimate named on the row - the backfill's cue to
            // reconstruct it later (GetTimesAwaitingImagePredictions)
            $notPredictable = SolvingTimePrediction::notPredictable(TimePredictionSource::Live);
            $estimate = $predictions->imageEstimate;

            $records[] = new SolvingTimePredictionRecord(Uuid::uuid7(), $time, PredictorKind::Image, $prediction->modelVersion, $predictions->image ?? $notPredictable, $now, $estimate);
            $records[] = new SolvingTimePredictionRecord(Uuid::uuid7(), $time, PredictorKind::Blend, $prediction->modelVersion, $predictions->blend ?? $notPredictable, $now, $estimate);
        }

        // The candidate model (v4, never shown) once it is fitted - first tries and repeats, its own model version
        if ($predictions->candidate !== null) {
            $records[] = new SolvingTimePredictionRecord(Uuid::uuid7(), $time, PredictorKind::Candidate, $predictions->candidate->modelVersion, $predictions->candidate, $now, $predictions->firstTry ? $predictions->imageEstimate : null);
        }

        $this->liveRows->add(...$records);
    }

    /**
     * Call after an edit: when the edit made the prediction obsolete (or it was never evaluated),
     * the time is reconstructed in the background - named explicitly, because a reconstruction
     * that was already running for this player may write a prediction for the old data first.
     */
    public function reconstructIfPending(PuzzleSolvingTime $time): void
    {
        if ($time->isPredictionPending()) {
            $this->scheduleReconstruction($time, reevaluate: true);
        }
    }

    /**
     * Solved on the tracking day or the day before (finished_at is a date), or no date given.
     */
    public static function isSolvedRecently(PuzzleSolvingTime $time): bool
    {
        if ($time->finishedAt === null) {
            return true;
        }

        return $time->finishedAt >= $time->trackedAt->setTime(0, 0)->modify('-1 day');
    }

    private function scheduleReconstruction(PuzzleSolvingTime $time, bool $reevaluate = false): void
    {
        // After the current bus: the time must be committed before the worker looks for it.
        // Async explicitly - the backfill command dispatches the same message synchronously
        $this->messageBus->dispatch(
            new BackfillSolvingTimePredictions(
                $time->player->id->toString(),
                $reevaluate ? [$time->id->toString()] : [],
            ),
            [new DispatchAfterCurrentBusStamp(), new TransportNamesStamp(['async'])],
        );
    }
}
