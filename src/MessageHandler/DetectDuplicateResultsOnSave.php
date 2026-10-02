<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use SpeedPuzzling\Web\Entity\PuzzleSolvingTime;
use SpeedPuzzling\Web\Entity\ResultDuplicateCase;
use SpeedPuzzling\Web\Events\PuzzleSolved;
use SpeedPuzzling\Web\Events\PuzzleSolvingTimeDeleted;
use SpeedPuzzling\Web\Events\PuzzleSolvingTimeModified;
use SpeedPuzzling\Web\Query\GetDuplicateCandidates;
use SpeedPuzzling\Web\Repository\PuzzleSolvingTimeRepository;
use SpeedPuzzling\Web\Repository\ResultDuplicateCaseRepository;
use SpeedPuzzling\Web\Services\DuplicateResults\DuplicateCaseRecorder;
use SpeedPuzzling\Web\Value\DuplicateDetectedBy;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Throwable;

/**
 * The detection right after a result was added, edited or deleted (docs/features/duplicate-results.md,
 * "Detection") - so the recap shows a twin straight away and the banner follows an edit or a deletion without
 * waiting for the daily run. The same code as the daily run (DuplicateCaseRecorder), scoped to the result:
 *
 * - added / edited: the pairs of the result's people on its puzzle (one query on the (player_id, puzzle_id)
 *   index), keeping only those with this result in them; an edited result's open cases that no longer match
 *   are closed as gone. Never removes anything - automatic removal is the daily run's alone.
 * - deleted: open cases with that result are closed as gone.
 *
 * The events run synchronously on postFlush, inside the save's transaction, so the new row is visible here.
 * A failure must never cost the player the save: everything runs in a savepoint and every read comes before
 * the first write, so a failing query is rolled back to the savepoint, logged and left for the daily run.
 * Stored here are only pairs that include the result just saved - a row nobody else can see before the commit,
 * so the daily run can never insert the same case at the same time (no unique-key race at the flush).
 */
#[AsMessageHandler]
readonly final class DetectDuplicateResultsOnSave
{
    public function __construct(
        private Connection $connection,
        private PuzzleSolvingTimeRepository $puzzleSolvingTimeRepository,
        private GetDuplicateCandidates $getDuplicateCandidates,
        private DuplicateCaseRecorder $caseRecorder,
        private ResultDuplicateCaseRepository $caseRepository,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(PuzzleSolved|PuzzleSolvingTimeModified|PuzzleSolvingTimeDeleted $event): void
    {
        $timeId = $event->puzzleSolvingTimeId->toString();

        $this->connection->beginTransaction();

        try {
            if ($event instanceof PuzzleSolvingTimeDeleted) {
                $this->closeGone($this->caseRepository->findOpenReferencing([$timeId]), []);
            } else {
                $time = $this->puzzleSolvingTimeRepository->findById($event->puzzleSolvingTimeId);

                if ($time !== null) {
                    $this->detect($time, $event instanceof PuzzleSolvingTimeModified);
                }
            }

            $this->connection->commit();
        } catch (Throwable $e) {
            $this->connection->rollBack();

            $this->logger->warning('Duplicate results: detection after a save failed - left for the daily run', [
                'timeId' => $timeId,
                'exception' => $e,
            ]);
        }
    }

    private function detect(PuzzleSolvingTime $time, bool $edited): void
    {
        $timeId = $time->id->toString();
        $candidates = [];

        if ($time->secondsToSolve !== null) {
            foreach ($this->getDuplicateCandidates->ofPeopleOnPuzzle($time->puzzle->id->toString(), $time->memberPlayerIds()) as $candidate) {
                if ($candidate->older->timeId === $timeId || $candidate->newer->timeId === $timeId) {
                    $candidates[] = $candidate;
                }
            }
        }

        // A new result has no cases yet; an edited one may have open ones that stop matching
        $openCases = $edited ? $this->caseRepository->findOpenReferencing([$timeId]) : [];

        if ($candidates === [] && $openCases === []) {
            return;
        }

        $knownKeys = $candidates === [] ? [] : $this->caseRepository->keysReferencing($timeId);
        $recorded = $this->caseRecorder->record($candidates, $knownKeys, DuplicateDetectedBy::Save);

        $this->closeGone($openCases, $recorded['matching']);
    }

    /**
     * @param list<ResultDuplicateCase> $openCases
     * @param array<string, true> $matchingKeys
     */
    private function closeGone(array $openCases, array $matchingKeys): void
    {
        $now = $this->clock->now();

        foreach ($openCases as $case) {
            if (!isset($matchingKeys[$case->pairKey()])) {
                $case->markGone($now);
            }
        }
    }
}
