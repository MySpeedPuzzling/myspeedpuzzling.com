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
 * A failure must never cost the player the save: everything runs in a savepoint, and the new cases are inserted
 * right there (INSERT .. ON CONFLICT DO NOTHING) instead of being persisted - persisted, they would be written by
 * the flush after this handler, outside the savepoint, where a failure (a case the daily run stored meanwhile)
 * fails the save. A failing statement is rolled back to the savepoint, logged and left for the daily run; only the
 * `gone` updates of loaded cases go through the unit of work, and they cannot conflict.
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

        foreach ($recorded['new'] as $case) {
            $this->caseRepository->insertIfAbsent($case);
        }

        $this->closeGone($openCases, $recorded['matching']);
    }

    /**
     * @param list<ResultDuplicateCase> $openCases
     * @param array<string, mixed> $matchingKeys
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
