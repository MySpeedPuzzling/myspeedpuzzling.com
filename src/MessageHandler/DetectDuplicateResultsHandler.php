<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Message\AutoRemoveCertainDuplicate;
use SpeedPuzzling\Web\Message\DetectDuplicateResults;
use SpeedPuzzling\Web\Query\GetDuplicateCandidates;
use SpeedPuzzling\Web\Repository\ResultDuplicateCaseRepository;
use SpeedPuzzling\Web\Results\DuplicateDetectionSummary;
use SpeedPuzzling\Web\Services\DuplicateResults\DuplicateCaseRecorder;
use SpeedPuzzling\Web\Value\DuplicateTier;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

/**
 * Stores every new duplicate case and closes open cases that no longer match (docs/features/duplicate-results.md,
 * "Detection"). A pair that already has a case - in any status - is never raised again, so a decision sticks.
 *
 * Open Tier A cases (the same form sent again) are removed automatically - each re-checked at that moment
 * (AutoRemoveCertainDuplicate). Tells nothing by itself.
 */
#[AsMessageHandler]
readonly final class DetectDuplicateResultsHandler
{
    public function __construct(
        private GetDuplicateCandidates $getDuplicateCandidates,
        private DuplicateCaseRecorder $caseRecorder,
        private ResultDuplicateCaseRepository $caseRepository,
        private MessageBusInterface $messageBus,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(DetectDuplicateResults $message): DuplicateDetectionSummary
    {
        $now = $this->clock->now();
        $candidates = $this->getDuplicateCandidates->all();
        $recorded = $this->caseRecorder->record($candidates, $this->caseRepository->allKeys(), $message->detectedBy);

        $goneCases = 0;
        $certainCaseIds = [];

        foreach ([...$this->caseRepository->findOpen(), ...$recorded['new']] as $case) {
            if (!isset($recorded['matching'][$case->pairKey()])) {
                $case->markGone($now);
                $goneCases++;

                continue;
            }

            if ($case->isOpen() && $case->tier === DuplicateTier::Certain) {
                $certainCaseIds[$case->id->toString()] = true;
            }
        }

        $autoRemoved = 0;

        foreach (array_keys($certainCaseIds) as $caseId) {
            $envelope = $this->messageBus->dispatch(new AutoRemoveCertainDuplicate($caseId));

            if ($envelope->last(HandledStamp::class)?->getResult() === true) {
                $autoRemoved++;
            }
        }

        return new DuplicateDetectionSummary(
            candidates: count($candidates),
            newCases: count($recorded['new']),
            goneCases: $goneCases,
            autoRemoved: $autoRemoved,
        );
    }
}
