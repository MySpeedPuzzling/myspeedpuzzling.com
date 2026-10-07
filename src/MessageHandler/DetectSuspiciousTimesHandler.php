<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use DateTimeImmutable;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\PuzzleSolvingTime;
use SpeedPuzzling\Web\Entity\SuspiciousTimeCase;
use SpeedPuzzling\Web\Entity\SuspiciousTimeReference;
use SpeedPuzzling\Web\Message\DetectSuspiciousTimes;
use SpeedPuzzling\Web\Query\GetPlayerPaces;
use SpeedPuzzling\Web\Query\GetSuspiciousTimeCandidates;
use SpeedPuzzling\Web\Query\GetSuspiciousTimeEvidence;
use SpeedPuzzling\Web\Query\GetSuspiciousTimeReferences;
use SpeedPuzzling\Web\Repository\PuzzleSolvingTimeRepository;
use SpeedPuzzling\Web\Repository\SuspiciousTimeCaseRepository;
use SpeedPuzzling\Web\Repository\SuspiciousTimeCheckRepository;
use SpeedPuzzling\Web\Repository\SuspiciousTimeReferenceRepository;
use SpeedPuzzling\Web\Results\SuspicionCandidate;
use SpeedPuzzling\Web\Results\SuspiciousTimeRaisedRow;
use SpeedPuzzling\Web\Results\SuspiciousTimeScanSummary;
use SpeedPuzzling\Web\Services\Doctrine\IdLock;
use SpeedPuzzling\Web\Services\SuspiciousTimes\MarkedTimeEditRecheck;
use SpeedPuzzling\Web\Services\SuspiciousTimes\SuspicionFingerprint;
use SpeedPuzzling\Web\Services\SuspiciousTimes\SuspiciousTimeClassifier;
use SpeedPuzzling\Web\Services\SuspiciousTimes\SuspiciousTimeDecisionRecorder;
use SpeedPuzzling\Web\Services\SuspiciousTimes\SuspiciousTimeScanTally;
use SpeedPuzzling\Web\Value\PaceReferences;
use SpeedPuzzling\Web\Value\PaceRequest;
use SpeedPuzzling\Web\Value\PuzzlingType;
use SpeedPuzzling\Web\Value\SuspicionEvidence;
use SpeedPuzzling\Web\Value\SuspicionEvidenceRequest;
use SpeedPuzzling\Web\Value\SuspicionInput;
use SpeedPuzzling\Web\Value\SuspiciousTimeCaseStatus;
use SpeedPuzzling\Web\Value\SuspiciousTimeDecisionKind;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * The suspicious time scan (docs/features/suspicious-time-review.md, "Detection" and "Checks and versions"):
 *
 * 1. the community references are computed again (a reference whose sample fell below the minimum keeps its values);
 * 2. flags changed by SQL are reconciled: a marked case whose time is no longer flagged → trusted
 *    (unmarked_outside_app), a flagged time without a marked case → marked, a new case with origin manual when it had
 *    none (marked_outside_app); statistics follow either way;
 * 3. a marked case whose time is another entry now (a piece count fixed, a merge, an SQL repair) is judged again like
 *    an edit (MarkedTimeEditRecheck): unmarked automatically when the new entry is clear and the mark rested on the
 *    detector's reasons, otherwise back to the moderators;
 * 4. the candidates are checked - streamed, the checks written in batches - and every check is stored with its
 *    outcome, version and fingerprint;
 * 5. raised times get their evidence and explanations: a new case (pending), a pending one refreshed, an ended one
 *    reopened; a pending case whose time is no longer raised is gone.
 *
 * The scan never marks a time - only a person does. A dry run writes nothing and answers the raised rows.
 *
 * A moderator may decide while the scan runs: every case is locked and read again right before the scan writes to it
 * (SuspiciousTimeCaseRepository::lockForDecision() - the moderators' handlers take the same row lock), and written
 * only while it is still in the state the scan read.
 */
#[AsMessageHandler]
readonly final class DetectSuspiciousTimesHandler
{
    private const int BATCH_SIZE = 5000;
    private const int EVIDENCE_BATCH_SIZE = 500;
    // The scan's own advisory lock: a second scan waits for the first to commit and then finds nothing left to do
    private const string SCAN_LOCK_ID = '5a5c1c10-5c5c-4a1e-8e5c-000000000001';

    public function __construct(
        private GetSuspiciousTimeReferences $getReferences,
        private GetSuspiciousTimeCandidates $getCandidates,
        private GetPlayerPaces $getPlayerPaces,
        private GetSuspiciousTimeEvidence $getEvidence,
        private SuspiciousTimeClassifier $classifier,
        private SuspiciousTimeReferenceRepository $referenceRepository,
        private SuspiciousTimeCheckRepository $checkRepository,
        private SuspiciousTimeCaseRepository $caseRepository,
        private PuzzleSolvingTimeRepository $timeRepository,
        private SuspiciousTimeDecisionRecorder $decisionRecorder,
        private MarkedTimeEditRecheck $markedTimeEditRecheck,
        private IdLock $idLock,
        private MessageBusInterface $messageBus,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(DetectSuspiciousTimes $message): SuspiciousTimeScanSummary
    {
        $now = $this->clock->now();
        $tally = new SuspiciousTimeScanTally($message->dryRun);

        if ($message->dryRun === false) {
            $this->idLock->lockUntilCommit(Uuid::fromString(self::SCAN_LOCK_ID));
        }

        $references = $this->references($message->dryRun, $now);
        $tally->references = $references->count();

        $reconciledTimeIds = $message->dryRun ? [] : $this->reconcileFlags($now, $tally);

        if ($message->dryRun === false) {
            $this->judgeChangedMarksAgain($tally);
        }

        $raised = $this->checkCandidates($references, $reconciledTimeIds, $message->dryRun, $now, $tally);
        $this->recordRaised($raised, $message->dryRun, $now, $tally);

        if ($message->dryRun === false) {
            $noLongerEligible = array_map(
                static fn (SuspiciousTimeCase $case): string => $case->id->toString(),
                $this->caseRepository->findPendingNoLongerEligible(),
            );

            foreach ($this->caseRepository->lockForDecision($noLongerEligible) as $case) {
                // markGone() leaves anything but a pending case alone - decided meanwhile
                if ($case->isPending()) {
                    $case->markGone($now);
                    $tally->goneCases++;
                }
            }
        }

        return $tally->summary();
    }

    private function references(bool $dryRun, DateTimeImmutable $now): PaceReferences
    {
        $computed = $this->getReferences->compute();
        $stored = $this->referenceRepository->all();

        if ($dryRun === false) {
            foreach ($computed->all() as $reference) {
                $entity = $this->referenceRepository->findFor($reference);

                if ($entity === null) {
                    $this->referenceRepository->save(SuspiciousTimeReference::of($reference, $now));
                } else {
                    $entity->refresh($reference, $now);
                }
            }
        }

        $storedReferences = new PaceReferences(array_map(
            static fn (SuspiciousTimeReference $reference) => $reference->toPaceReference(),
            $stored,
        ));

        return $storedReferences->overlaidWith($computed);
    }

    /**
     * @return array<string, true> the time ids reconciled - decided in this run, not checked again by it
     */
    private function reconcileFlags(DateTimeImmutable $now, SuspiciousTimeScanTally $tally): array
    {
        $reconciled = [];
        $unflagged = array_map(
            static fn (SuspiciousTimeCase $case): string => $case->id->toString(),
            $this->caseRepository->findMarkedWithoutFlag(),
        );

        foreach ($this->caseRepository->lockForDecision($unflagged, freshTimes: true) as $case) {
            $time = $case->time;

            // A moderator decided meanwhile (an unmark clears the flag too), or the flag is back
            if ($case->isMarked() === false || $time->suspicious) {
                continue;
            }

            $case->trust(null, SuspicionFingerprint::ofTime($time), $now);
            $this->decisionRecorder->recordAboutTime(SuspiciousTimeDecisionKind::UnmarkedOutsideApp, $time, $case, null);
            $this->statisticsFollow($time);

            $reconciled[$time->id->toString()] = true;
            $tally->unmarkedOutsideApp++;
        }

        $flagged = $this->caseRepository->findFlaggedTimesWithoutMarkedCase();
        $flaggedCases = $this->caseRepository->lockByTimes(array_map(
            static fn (PuzzleSolvingTime $time): string => $time->id->toString(),
            $flagged,
        ));

        foreach ($flagged as $time) {
            $fingerprint = SuspicionFingerprint::ofTime($time);
            $case = $flaggedCases[$time->id->toString()] ?? null;

            if ($case === null) {
                $case = SuspiciousTimeCase::flaggedOutsideTheApp(Uuid::uuid7(), $time, $fingerprint, $now);
                $this->caseRepository->save($case);
            } elseif ($case->isMarked()) {
                // A moderator marked it meanwhile - with the reasons they chose
                continue;
            } else {
                $case->markedOutsideTheApp($fingerprint, $now);
            }

            $this->decisionRecorder->recordAboutTime(SuspiciousTimeDecisionKind::MarkedOutsideApp, $time, $case, null);
            $this->statisticsFollow($time);

            $reconciled[$time->id->toString()] = true;
            $tally->markedOutsideApp++;
        }

        return $reconciled;
    }

    /**
     * Step 3: marked cases whose time is another entry now - judged like an edit (MarkedTimeEditRecheck). Locked and
     * read again first: only a case still marked, its time still flagged and still another entry.
     */
    private function judgeChangedMarksAgain(SuspiciousTimeScanTally $tally): void
    {
        foreach ($this->caseRepository->lockForDecision($this->getCandidates->markedWithChangedEntry(), freshTimes: true) as $case) {
            if ($case->isMarked() === false || $case->time->suspicious === false || $case->fingerprint === SuspicionFingerprint::ofTime($case->time)) {
                continue;
            }

            if ($this->markedTimeEditRecheck->afterOutsideChange($case)) {
                $tally->changedMarksUnmarked++;
            } else {
                $tally->changedMarksToModerators++;
            }
        }
    }

    /**
     * The flag was changed by SQL, the entity did not change: Doctrine sees no update, so DomainEventsSubscriber would
     * never dispatch the event - handed to the bus here. Statistics, insights and duplicate detection follow it.
     */
    private function statisticsFollow(PuzzleSolvingTime $time): void
    {
        $time->suspicionChangedOutsideTheApp();

        foreach ($time->popEvents() as $event) {
            $this->messageBus->dispatch($event);
        }
    }

    /**
     * @param array<string, true> $skipTimeIds
     * @return list<array{SuspicionCandidate, SuspicionInput}> the raised ones
     */
    private function checkCandidates(PaceReferences $references, array $skipTimeIds, bool $dryRun, DateTimeImmutable $now, SuspiciousTimeScanTally $tally): array
    {
        $raised = [];
        $noLongerRaisedTimeIds = [];
        $noDataSince = $now->modify(sprintf('-%d days', SuspiciousTimeClassifier::NO_DATA_RECHECK_DAYS));

        foreach ($this->getCandidates->batches(SuspiciousTimeClassifier::VERSION, $noDataSince, self::BATCH_SIZE) as $batch) {
            $paceRequests = [];

            foreach ($batch as $candidate) {
                if ($candidate->puzzlingType === PuzzlingType::Solo && SuspiciousTimeClassifier::needsPace($candidate->predictedSeconds, $candidate->baselineSeconds, $candidate->seconds, $candidate->previousAttemptSeconds)) {
                    $paceRequests[] = new PaceRequest($candidate->timeId, $candidate->playerId, $candidate->timeId, $candidate->solvedAt);
                }
            }

            $paces = $this->getPlayerPaces->forTimes($paceRequests, $references);
            $checks = [];

            foreach ($batch as $candidate) {
                if (isset($skipTimeIds[$candidate->timeId])) {
                    continue;
                }

                $input = new SuspicionInput(
                    piecesCount: $candidate->piecesCount,
                    seconds: $candidate->seconds,
                    puzzlingType: $candidate->puzzlingType,
                    predictedSeconds: $candidate->predictedSeconds,
                    baselineSeconds: $candidate->baselineSeconds,
                    difficultyScore: $candidate->difficultyScore,
                    paceFactor: $paces[$candidate->timeId] ?? null,
                    references: $references,
                    previousAttemptSeconds: $candidate->previousAttemptSeconds,
                    previousAttemptRaisedSlow: $candidate->previousAttemptRaisedSlow,
                );
                $assessment = $this->classifier->classify($input);
                $tally->checked($assessment);

                $checks[] = [
                    'time_id' => $candidate->timeId,
                    'outcome' => $assessment->outcome,
                    'fingerprint' => $candidate->fingerprint,
                ];

                if ($assessment->isRaised()) {
                    $raised[] = [$candidate, $input];
                } elseif ($candidate->caseStatus === SuspiciousTimeCaseStatus::Pending) {
                    $noLongerRaisedTimeIds[] = $candidate->timeId;
                }
            }

            if ($dryRun === false) {
                $this->checkRepository->upsertMany($checks, SuspiciousTimeClassifier::VERSION, $now);
            }
        }

        if ($dryRun) {
            $tally->goneCases += count($noLongerRaisedTimeIds);

            return $raised;
        }

        foreach (array_chunk($noLongerRaisedTimeIds, self::EVIDENCE_BATCH_SIZE) as $timeIds) {
            foreach ($this->caseRepository->lockByTimes($timeIds) as $case) {
                // Still pending, as the scan read it - not decided meanwhile
                if ($case->isPending()) {
                    $case->markGone($now);
                    $tally->goneCases++;
                }
            }
        }

        return $raised;
    }

    /**
     * @param list<array{SuspicionCandidate, SuspicionInput}> $raised
     */
    private function recordRaised(array $raised, bool $dryRun, DateTimeImmutable $now, SuspiciousTimeScanTally $tally): void
    {
        foreach (array_chunk($raised, self::EVIDENCE_BATCH_SIZE) as $chunk) {
            $evidence = $this->getEvidence->forTimes(array_map(
                static fn (array $item): SuspicionEvidenceRequest => new SuspicionEvidenceRequest(
                    key: $item[0]->timeId,
                    timeId: $item[0]->timeId,
                    playerId: $item[0]->playerId,
                    puzzleId: $item[0]->puzzleId,
                    solvedDay: gmdate('Y-m-d', $item[0]->solvedAt),
                ),
                $chunk,
            ));

            $withCase = [];
            $withoutCase = [];

            foreach ($chunk as [$candidate]) {
                if ($candidate->caseId === null) {
                    $withoutCase[] = $candidate->timeId;
                } else {
                    $withCase[] = $candidate->timeId;
                }
            }

            $cases = $dryRun ? [] : $this->caseRepository->lockByTimes($withCase);
            $times = $dryRun ? [] : $this->timeRepository->findByIds($withoutCase);

            foreach ($chunk as [$candidate, $input]) {
                $assessment = $this->classifier->classify($input->withEvidence($evidence[$candidate->timeId] ?? new SuspicionEvidence()));
                $tally->raisedWithEvidence($assessment);

                if ($dryRun) {
                    $tally->raisedRows[] = new SuspiciousTimeRaisedRow(
                        timeId: $candidate->timeId,
                        playerId: $candidate->playerId,
                        puzzleId: $candidate->puzzleId,
                        piecesCount: $candidate->piecesCount,
                        seconds: $candidate->seconds,
                        puzzlingType: $candidate->puzzlingType,
                        solvedAt: $candidate->solvedAt,
                        assessment: $assessment,
                        caseStatus: $candidate->caseStatus,
                    );

                    if ($candidate->caseStatus === null) {
                        $tally->newCases++;
                    } elseif ($candidate->caseStatus === SuspiciousTimeCaseStatus::Pending) {
                        $tally->refreshedCases++;
                    } else {
                        $tally->reopenedCases++;
                    }

                    continue;
                }

                $case = $cases[$candidate->timeId] ?? null;

                if ($case === null) {
                    $time = $times[$candidate->timeId] ?? null;

                    // Deleted while the scan ran
                    if ($time === null) {
                        continue;
                    }

                    $this->caseRepository->save(SuspiciousTimeCase::detected(Uuid::uuid7(), $time, $assessment, $candidate->fingerprint, SuspiciousTimeClassifier::VERSION, $now));
                    $tally->newCases++;
                } elseif ($case->status !== $candidate->caseStatus) {
                    // A moderator decided while the scan ran - their decision stands, the next run looks again
                    continue;
                } elseif ($case->isPending()) {
                    $case->refreshDetection($assessment, $candidate->fingerprint, SuspiciousTimeClassifier::VERSION, $now);
                    $tally->refreshedCases++;
                } else {
                    $case->reopen($assessment, $candidate->fingerprint, SuspiciousTimeClassifier::VERSION, $now);
                    $tally->reopenedCases++;
                }
            }
        }
    }
}
