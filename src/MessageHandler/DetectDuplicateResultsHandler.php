<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Message\DetectDuplicateResults;
use SpeedPuzzling\Web\Query\GetDuplicateCandidates;
use SpeedPuzzling\Web\Repository\ResultDuplicateCaseRepository;
use SpeedPuzzling\Web\Results\DuplicateDetectionSummary;
use SpeedPuzzling\Web\Services\DuplicateResults\DuplicateCaseRecorder;
use SpeedPuzzling\Web\Value\DuplicateTier;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Stores every new duplicate case, closes open cases that no longer match and re-classifies the open ones that still
 * do (docs/features/duplicate-results.md, "Detection"). A pair that already has a case - in any status - is never
 * raised again, so a decision sticks.
 *
 * Removes nothing: it answers the open Tier A cases, and DailyDuplicateDetection removes each in a message - and a
 * transaction - of its own, so one failing removal never undoes the detection or the other removals.
 */
#[AsMessageHandler]
readonly final class DetectDuplicateResultsHandler
{
    public function __construct(
        private GetDuplicateCandidates $getDuplicateCandidates,
        private DuplicateCaseRecorder $caseRecorder,
        private ResultDuplicateCaseRepository $caseRepository,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(DetectDuplicateResults $message): DuplicateDetectionSummary
    {
        $now = $this->clock->now();
        $candidates = $this->getDuplicateCandidates->all();
        $recorded = $this->caseRecorder->record($candidates, $this->caseRepository->allKeys(), $message->detectedBy);

        foreach ($recorded['new'] as $case) {
            $this->caseRepository->save($case);
        }

        $goneCases = 0;
        $certainCaseIds = [];

        foreach ([...$this->caseRepository->findOpen(), ...$recorded['new']] as $case) {
            $match = $recorded['matching'][$case->pairKey()] ?? null;

            if ($match === null) {
                $case->markGone($now);
                $goneCases++;

                continue;
            }

            // What lies around the pair may have changed since it was stored (a copy in between removed)
            $case->reclassify($match['classification'], $match['candidate']->snapshot());

            if ($case->isOpen() && $case->tier === DuplicateTier::Certain) {
                $certainCaseIds[$case->id->toString()] = true;
            }
        }

        return new DuplicateDetectionSummary(
            candidates: count($candidates),
            newCases: count($recorded['new']),
            goneCases: $goneCases,
            certainCaseIds: array_keys($certainCaseIds),
        );
    }
}
