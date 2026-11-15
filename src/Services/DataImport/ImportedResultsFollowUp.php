<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\DataImport;

use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\PuzzleStatistics;
use SpeedPuzzling\Web\Message\AwardXpForSolvingTime;
use SpeedPuzzling\Web\Message\BackfillSolvingTimePredictions;
use SpeedPuzzling\Web\Message\RecalculateBadgesForPlayer;
use SpeedPuzzling\Web\Repository\PuzzleRepository;
use SpeedPuzzling\Web\Repository\PuzzleStatisticsRepository;
use SpeedPuzzling\Web\Services\PuzzleStatisticsCalculator;
use SpeedPuzzling\Web\Value\DataImport\ImportedResultOutcome;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DispatchAfterCurrentBusStamp;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;

/**
 * What is owed for the results ImportedResultWriter added, done once for many of them
 * (docs/features/data-import/writer.md "Reactions") - the add form's handlers do the same per result:
 *
 * - the statistics of every puzzle touched (RecalculatePuzzleStatisticsOnSolvingTimeChange, per PuzzleSolved);
 * - XP: `AwardXpForSolvingTime` **per result** - the very message the add form queues. It brings the ledger of every
 *   registered person of the result to what a replay of the result's puzzle writes and touches nothing else of
 *   theirs: other puzzles' entries, settlements and the audit entries of deleted solves stay as they are. A full
 *   rebuild per player (RecalculateXpForPlayer) would be one job instead of many, but it wipes exactly those;
 * - achievements of every registered person, once each (RecalculateBadgesForPlayer);
 * - the reconstructed predictions of the players whose solo results wait for one (the message a back-dated add
 *   queues - SolvingTimePredictor::scheduleReconstruction()).
 *
 * Call it in a transaction of its own, after the results are committed: the statistics are counted from the
 * database, and the queued jobs look for the results there. Calling it again repeats nothing harmful.
 */
readonly final class ImportedResultsFollowUp
{
    public function __construct(
        private PuzzleRepository $puzzleRepository,
        private PuzzleStatisticsRepository $statisticsRepository,
        private PuzzleStatisticsCalculator $statisticsCalculator,
        private MessageBusInterface $messageBus,
    ) {
    }

    /**
     * @param iterable<ImportedResultOutcome> $outcomes of one batch - only added results count
     */
    public function afterResultsAdded(iterable $outcomes): void
    {
        $puzzleIds = [];
        $solvingTimeIds = [];
        $memberPlayerIds = [];
        $playersAwaitingPredictions = [];

        foreach ($outcomes as $outcome) {
            if ($outcome->isAdded() === false || $outcome->puzzleId === null) {
                continue;
            }

            $puzzleIds[$outcome->puzzleId] = true;

            if ($outcome->solvingTimeId !== null) {
                $solvingTimeIds[] = $outcome->solvingTimeId;
            }

            foreach ($outcome->memberPlayerIds as $memberPlayerId) {
                $memberPlayerIds[$memberPlayerId] = true;
            }

            // The player comes first among the members (PuzzleSolvingTime::memberPlayerIds())
            if ($outcome->predictionPending && $outcome->memberPlayerIds !== []) {
                $playersAwaitingPredictions[$outcome->memberPlayerIds[0]] = true;
            }
        }

        foreach (array_keys($puzzleIds) as $puzzleId) {
            $this->recalculateStatistics($puzzleId);
        }

        foreach ($solvingTimeIds as $solvingTimeId) {
            $this->messageBus->dispatch(new AwardXpForSolvingTime($solvingTimeId));
        }

        foreach (array_keys($memberPlayerIds) as $playerId) {
            $this->messageBus->dispatch(new RecalculateBadgesForPlayer($playerId));
        }

        foreach (array_keys($playersAwaitingPredictions) as $playerId) {
            // The stamps of SolvingTimePredictor::scheduleReconstruction(): after the current bus, so the results are
            // committed before the worker looks for them, and async by name
            $this->messageBus->dispatch(
                new BackfillSolvingTimePredictions($playerId),
                [new DispatchAfterCurrentBusStamp(), new TransportNamesStamp(['async'])],
            );
        }
    }

    /**
     * RecalculatePuzzleStatisticsOnSolvingTimeChange's own steps.
     */
    private function recalculateStatistics(string $puzzleId): void
    {
        $id = Uuid::fromString($puzzleId);
        $statistics = $this->statisticsRepository->findByPuzzleId($id);

        if ($statistics === null) {
            $statistics = new PuzzleStatistics($this->puzzleRepository->get($puzzleId));
            $this->statisticsRepository->save($statistics);
        }

        $statistics->update($this->statisticsCalculator->calculateForPuzzle($id));
    }
}
