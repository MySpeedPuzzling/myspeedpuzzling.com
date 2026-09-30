<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\PuzzleIntelligence;

use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use SpeedPuzzling\Web\Entity\PuzzleSolvingTime;
use SpeedPuzzling\Web\Message\BackfillSolvingTimePredictions;
use SpeedPuzzling\Web\Query\GetPlayerPrediction;
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
 */
readonly final class SolvingTimePredictor
{
    public function __construct(
        private GetPlayerPrediction $getPlayerPrediction,
        private Connection $connection,
        private ClockInterface $clock,
        private MessageBusInterface $messageBus,
        private LoggerInterface $logger,
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
            $result = $this->getPlayerPrediction->forPuzzle(
                $time->player->id->toString(),
                $time->puzzle->id->toString(),
                excludeTimeId: $time->id->toString(),
                before: SolveMoment::of($time->finishedAt, $time->trackedAt),
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

        $time->recordPrediction(
            $result !== null
                ? SolvingTimePrediction::predicted($result, TimePredictionSource::Live)
                : SolvingTimePrediction::notPredictable(TimePredictionSource::Live),
            $this->clock->now(),
        );
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
