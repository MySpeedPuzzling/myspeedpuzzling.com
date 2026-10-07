<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\SuspiciousTimes;

use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Entity\PuzzleSolvingTime;
use SpeedPuzzling\Web\Entity\SuspiciousTimeCase;
use SpeedPuzzling\Web\Entity\SuspiciousTimeNotice;
use SpeedPuzzling\Web\Repository\SuspiciousTimeCaseRepository;
use SpeedPuzzling\Web\Repository\SuspiciousTimeNoticeRepository;
use SpeedPuzzling\Web\Services\DuplicateResults\ResultReviewReactions;
use SpeedPuzzling\Web\Value\SolveMoment;
use SpeedPuzzling\Web\Value\SuspicionAssessment;
use SpeedPuzzling\Web\Value\SuspicionCheckOutcome;
use SpeedPuzzling\Web\Value\SuspiciousTimeDecisionKind;
use SpeedPuzzling\Web\Value\SuspiciousTimeResponse;
use Throwable;

/**
 * "When the player edits a marked time" (docs/features/suspicious-time-review.md): a marked time whose entry changed
 * - the time, the puzzle (or its piece count) or the group.
 *
 * - A player's edit (afterEdit()) always removes the label - a changed result is another result (Jan, 2026-10-07): the
 *   flag cleared, the case corrected, every notice of the mark answered "fixed", the decision logged
 *   (unmarked_after_edit). The scan judges the new entry like any other result: still far off → a new pending case,
 *   and a moderator may mark it again (a new mark, told again). The edit form itself asks before saving a time it
 *   would raise, so a one-second tweak does not slip through unnoticed.
 * - Changed without the edit form (afterOutsideChange(): a piece-count fix, a merge, an SQL repair, an edit whose
 *   re-check failed) - judged again with the new values: clear → unmarked automatically (corrected_automatically), for
 *   every mark, also one set by SQL; anything else (still off, could not be judged) → back to the moderators ("Player
 *   replied" tab, player_edited_at). A merge does not change the result, so it must not announce the mark again.
 *
 * Serialized with the moderators' decisions and the scan by the case's row lock, taken before anything is read: a
 * "Looks fine" in flight is either seen here, or sees the edit and refuses ("Changed meanwhile").
 *
 * Never costs the player the edit: the reads run in a savepoint that is always rolled back (they only read - a failing
 * statement must not abort the transaction the edit is saved in), and any failure is logged as a warning while the edit
 * is saved anyway with the time still marked.
 */
readonly final class MarkedTimeEditRecheck
{
    public function __construct(
        private SuspiciousTimeCaseRepository $caseRepository,
        private SuspiciousTimeNoticeRepository $noticeRepository,
        private SingleTimeSuspicionCheck $singleTimeSuspicionCheck,
        private SuspiciousTimeDecisionRecorder $decisionRecorder,
        private ResultReviewReactions $resultReviewReactions,
        private Connection $connection,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * The entry of a flagged time before the edit - null for every other time (nothing is read).
     */
    public function entryBeforeEdit(PuzzleSolvingTime $time): null|string
    {
        return $time->suspicious ? SuspicionFingerprint::ofTime($time) : null;
    }

    /**
     * Call once the edit changed the time (modify(), moveToPuzzle()), with what entryBeforeEdit() answered before it.
     */
    public function afterEdit(PuzzleSolvingTime $time, null|string $entryBeforeEdit, Player $editor): void
    {
        if ($entryBeforeEdit === null || $time->suspicious === false) {
            return;
        }

        $entry = SuspicionFingerprint::ofTime($time);

        // Only the comment, the photo, the date ... changed - the same entry the moderator marked
        if ($entry === $entryBeforeEdit) {
            return;
        }

        try {
            $case = $this->lockedMarkedCaseOf($time);

            if ($case !== null) {
                $this->rejudge($case, $time, $entry, $editor);
            } else {
                // Flagged by SQL and not taken over by a scan yet: no case, no notices - the label goes all the same
                $time->clearSuspicion();
                $this->decisionRecorder->recordAboutTime(SuspiciousTimeDecisionKind::UnmarkedAfterEdit, $time, null, null);
            }
        } catch (Throwable $e) {
            $this->logger->warning('Time verification: the re-check of an edited marked time failed - the edit is saved, the time stays marked', [
                'timeId' => $time->id->toString(),
                'editorId' => $editor->id->toString(),
                'exception' => $e,
            ]);
        }
    }

    /**
     * The scan found a marked case whose time is another entry than the one the case is about, changed without the
     * edit form. The caller locked the case and checked that it is still marked, its time still flagged.
     *
     * @return bool true = unmarked automatically, false = back to the moderators
     */
    public function afterOutsideChange(SuspiciousTimeCase $case): bool
    {
        $time = $case->time;

        return $this->rejudge($case, $time, SuspicionFingerprint::ofTime($time), null);
    }

    /**
     * @return bool true = unmarked automatically
     */
    private function rejudge(SuspiciousTimeCase $case, PuzzleSolvingTime $time, string $entry, null|Player $editor): bool
    {
        // A player's edit needs no judgement - the scan judges the new entry
        [$notices, $assessment] = $this->readInSavepoint($case, $time, judge: $editor === null);
        $now = $this->clock->now();
        $editorWasTold = false;
        // A player's edit always removes the label; a change outside the edit form only when the new entry is clear
        $unmarked = $editor !== null || self::passes($assessment);

        if ($unmarked) {
            $time->clearSuspicion();
            $case->markCorrected($entry, $now);
            $this->decisionRecorder->recordAboutTime(
                $editor !== null ? SuspiciousTimeDecisionKind::UnmarkedAfterEdit : SuspiciousTimeDecisionKind::CorrectedAutomatically,
                $time,
                $case,
                null,
            );
        } else {
            $case->playerEdited($assessment, $entry, SuspiciousTimeClassifier::VERSION, $now);
        }

        // Changed outside the edit form: nobody did anything the notices could answer
        if ($editor === null) {
            return $unmarked;
        }

        foreach ($notices as $notice) {
            $byEditor = $notice->player->id->equals($editor->id);
            $editorWasTold = $editorWasTold || $byEditor;

            // Corrected: every notice of the mark is answered; still marked: the editor's only
            if ($byEditor || ($unmarked && $notice->response === null)) {
                $notice->respond(SuspiciousTimeResponse::Fixed, null, $now);
            }
        }

        if ($editorWasTold) {
            $this->resultReviewReactions->recordFor($editor->id->toString());
        }

        return $unmarked;
    }

    /**
     * The time's case with its row locked until the edit commits - null when the time has no case or it is not marked
     * (any more). The lock is taken in a savepoint of its own: a failing statement must not abort the transaction the
     * edit is saved in; a released savepoint keeps the lock.
     */
    private function lockedMarkedCaseOf(PuzzleSolvingTime $time): null|SuspiciousTimeCase
    {
        $this->connection->beginTransaction();

        try {
            $case = $this->caseRepository->findByTime($time->id->toString());

            if ($case !== null) {
                $this->caseRepository->lock($case);
            }

            $this->connection->commit();
        } catch (Throwable $e) {
            $this->connection->rollBack();

            throw $e;
        }

        return $case !== null && $case->isMarked() ? $case : null;
    }

    /**
     * The case's notices of the mark in force and, with $judge, the judgement of the time's entry now (null = not
     * judged).
     *
     * @return array{list<SuspiciousTimeNotice>, null|SuspicionAssessment}
     */
    private function readInSavepoint(SuspiciousTimeCase $case, PuzzleSolvingTime $time, bool $judge): array
    {
        $this->connection->beginTransaction();

        try {
            $notices = array_values(array_filter(
                $this->noticeRepository->findOfCase($case),
                static fn (SuspiciousTimeNotice $notice): bool => $notice->isAbout($case),
            ));

            $assessment = $judge && $time->secondsToSolve !== null && $time->secondsToSolve > 0
                ? $this->singleTimeSuspicionCheck->forEntry(
                    // A solo time is judged against its player - whoever tracked it, whoever edits it
                    $time->player->id->toString(),
                    $time->puzzle->id->toString(),
                    $time->secondsToSolve,
                    $time->puzzlingType,
                    $time->puzzlersCount,
                    $time->id->toString(),
                    SolveMoment::of($time->finishedAt, $time->trackedAt),
                )
                // Without a time nothing can be judged - a person decides
                : null;

            return [$notices, $assessment];
        } finally {
            $this->connection->rollBack();
        }
    }

    /**
     * A change outside the edit form unmarks only a clear new entry - one that could not be judged (no data, a failed
     * check) or still looks off goes back to a person. Any mark: one set by SQL as well as one from the queue.
     */
    private static function passes(null|SuspicionAssessment $assessment): bool
    {
        return $assessment !== null && $assessment->outcome === SuspicionCheckOutcome::Clear;
    }
}
