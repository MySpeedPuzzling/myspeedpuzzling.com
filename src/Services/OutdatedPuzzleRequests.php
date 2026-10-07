<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services;

use Psr\Clock\ClockInterface;
use Ramsey\Uuid\UuidInterface;
use SpeedPuzzling\Web\Entity\Puzzle;
use SpeedPuzzling\Web\Entity\PuzzleChangeRequest;
use SpeedPuzzling\Web\Entity\PuzzleMergeRequest;
use SpeedPuzzling\Web\Query\GetCurrentPuzzleIds;
use SpeedPuzzling\Web\Repository\PuzzleChangeRequestRepository;
use SpeedPuzzling\Web\Repository\PuzzleMergeRequestRepository;
use SpeedPuzzling\Web\Repository\PuzzleRepository;
use SpeedPuzzling\Web\Value\MergeRequestPuzzles;
use SpeedPuzzling\Web\Value\PuzzleModerationAction;
use SpeedPuzzling\Web\Value\PuzzleReportOutdatedReason;
use SpeedPuzzling\Web\Value\PuzzleReportStatus;

/**
 * Closes the merge and change requests the catalogue has taken care of already, so the queues hold only work
 * (docs/features/puzzle-approvals.md, "Outdated requests"):
 * - a merge request with fewer than two of its puzzles left (MergeRequestPuzzles) - other merges joined them, or they
 *   are gone;
 * - a change request whose puzzle has every value it proposes (PuzzleChangeRequest::nothingLeftToApply()).
 *
 * What makes a request outdated closes it, in its own transaction: a merge (afterMerge()), a saved puzzle record
 * (afterRecordChange()). closeAll() is the daily safety net for whatever went around them - SQL, a path not wired
 * here. Each closing is a line in the decision log without a decider; the reporter is told nothing - nobody judged
 * the report, and what it asked for happened.
 */
readonly final class OutdatedPuzzleRequests
{
    public function __construct(
        private PuzzleMergeRequestRepository $puzzleMergeRequestRepository,
        private PuzzleChangeRequestRepository $puzzleChangeRequestRepository,
        private PuzzleRepository $puzzleRepository,
        private GetCurrentPuzzleIds $getCurrentPuzzleIds,
        private PuzzleModerationDecisionRecorder $puzzleModerationDecisionRecorder,
        private ClockInterface $clock,
    ) {
    }

    /**
     * Inside the merge's transaction, while the merged puzzles still exist: the other pending merge requests naming one
     * of them, and the pending change requests of the survivor - its own and the ones moved onto it.
     *
     * @param list<Puzzle> $mergedPuzzles
     */
    public function afterMerge(PuzzleMergeRequest $merge, Puzzle $survivor, array $mergedPuzzles): void
    {
        $mergedNow = [];

        foreach ($mergedPuzzles as $mergedPuzzle) {
            $mergedNow[$mergedPuzzle->id->toString()] = $survivor->id->toString();
        }

        $requests = array_filter(
            $this->puzzleMergeRequestRepository->findPendingNamingAny(array_keys($mergedNow)),
            static fn (PuzzleMergeRequest $request): bool => $request->id->equals($merge->id) === false,
        );

        foreach ($requests as $request) {
            // Loaded now, so the database's ON DELETE SET NULL comes too late - a managed request pointing at a deleted
            // puzzle fails the flush
            if ($request->sourcePuzzle !== null && isset($mergedNow[$request->sourcePuzzle->id->toString()])) {
                $request->clearSourcePuzzleReference();
            }
        }

        $this->closeOutdatedMergeRequests($requests, $mergedNow, $merge->id);

        // The moved requests are stored on the merged puzzles until the flush
        $this->closeAppliedChangeRequests($this->puzzleChangeRequestRepository->findPendingForPuzzles([$survivor, ...$mergedPuzzles]));
    }

    /**
     * After a puzzle's record was saved: its other pending change requests may propose nothing new any more.
     */
    public function afterRecordChange(Puzzle $puzzle): void
    {
        $this->closeAppliedChangeRequests($this->puzzleChangeRequestRepository->findPendingForPuzzles([$puzzle]));
    }

    /**
     * Every pending request - the daily safety net.
     *
     * @return array{mergeRequests: int, changeRequests: int} How many were closed
     */
    public function closeAll(): array
    {
        return [
            'mergeRequests' => $this->closeOutdatedMergeRequests($this->puzzleMergeRequestRepository->findAllPending()),
            'changeRequests' => $this->closeAppliedChangeRequests($this->puzzleChangeRequestRepository->findAllPending()),
        ];
    }

    /**
     * @param array<PuzzleMergeRequest> $requests
     * @param array<string, string> $mergedNow Puzzle id => the puzzle the merge running now merges it into
     */
    private function closeOutdatedMergeRequests(array $requests, array $mergedNow = [], null|UuidInterface $byMergeRequestId = null): int
    {
        $reportedIds = [];

        foreach ($requests as $request) {
            array_push($reportedIds, ...array_values($request->reportedDuplicatePuzzleIds));
        }

        $currentIds = $this->getCurrentPuzzleIds->of($reportedIds);
        $closed = 0;

        foreach ($requests as $request) {
            if ($request->status !== PuzzleReportStatus::Pending) {
                continue;
            }

            $puzzles = MergeRequestPuzzles::resolve($request->reportedDuplicatePuzzleIds, $currentIds, $mergedNow);
            $reason = $puzzles->outdatedReason();

            if ($reason === null) {
                continue;
            }

            $onlyCurrentId = $puzzles->onlyCurrentId();
            $currentPuzzle = $onlyCurrentId !== null ? $this->puzzleRepository->get($onlyCurrentId) : null;

            $request->markOutdated($reason, $this->clock->now(), $currentPuzzle?->id, $byMergeRequestId);

            $this->puzzleModerationDecisionRecorder->recordAutomatic(
                action: PuzzleModerationAction::MergeRequestOutdated,
                puzzleId: $currentPuzzle?->id,
                puzzleName: $currentPuzzle?->name,
                mergeRequestId: $request->id,
                details: [
                    'reason' => $reason->value,
                    'reportedDuplicatePuzzleIds' => array_values($request->reportedDuplicatePuzzleIds),
                    'mergedMeanwhile' => $puzzles->mergedMeanwhile(),
                    'gonePuzzleIds' => $puzzles->gone(),
                    'byMergeRequestId' => $byMergeRequestId?->toString(),
                ],
            );

            $closed++;
        }

        return $closed;
    }

    /**
     * @param array<PuzzleChangeRequest> $requests
     */
    private function closeAppliedChangeRequests(array $requests): int
    {
        $closed = 0;

        foreach ($requests as $request) {
            // The request just approved is still pending in the database - not in memory
            if ($request->status !== PuzzleReportStatus::Pending || $request->nothingLeftToApply() === false) {
                continue;
            }

            $request->markOutdated(PuzzleReportOutdatedReason::AlreadyApplied, $this->clock->now());

            $this->puzzleModerationDecisionRecorder->recordAutomatic(
                action: PuzzleModerationAction::ChangeRequestOutdated,
                puzzleId: $request->puzzle->id,
                puzzleName: $request->puzzle->name,
                changeRequestId: $request->id,
                details: ['reason' => PuzzleReportOutdatedReason::AlreadyApplied->value],
            );

            $closed++;
        }

        return $closed;
    }
}
