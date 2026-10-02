<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\ResultDuplicateCase;
use SpeedPuzzling\Web\Message\DetectDuplicateResults;
use SpeedPuzzling\Web\Query\GetDuplicateCandidates;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Repository\ResultDuplicateCaseRepository;
use SpeedPuzzling\Web\Results\DuplicateDetectionSummary;
use SpeedPuzzling\Web\Services\DuplicateResults\DuplicateClassifier;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Stores every new duplicate case and closes open cases that no longer match (docs/features/duplicate-results.md,
 * "Detection"). A pair that already has a case - in any status - is never raised again, so a decision sticks.
 * Removes and tells nothing by itself.
 */
#[AsMessageHandler]
readonly final class DetectDuplicateResultsHandler
{
    public function __construct(
        private GetDuplicateCandidates $getDuplicateCandidates,
        private DuplicateClassifier $classifier,
        private ResultDuplicateCaseRepository $caseRepository,
        private PlayerRepository $playerRepository,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(DetectDuplicateResults $message): DuplicateDetectionSummary
    {
        $now = $this->clock->now();
        $candidates = $this->getDuplicateCandidates->all();
        $knownKeys = $this->caseRepository->allKeys();
        $matchingKeys = [];
        $newCases = 0;

        foreach ($candidates as $candidate) {
            $classification = $this->classifier->classify($candidate);

            if ($classification === null) {
                continue;
            }

            $key = ResultDuplicateCase::key($candidate->personId, $candidate->older->timeId, $candidate->newer->timeId);
            $matchingKeys[$key] = true;

            if (isset($knownKeys[$key])) {
                continue;
            }

            $this->caseRepository->save(new ResultDuplicateCase(
                id: Uuid::uuid7(),
                player: $this->playerRepository->get($candidate->personId),
                timeAId: Uuid::fromString($candidate->older->timeId),
                timeBId: Uuid::fromString($candidate->newer->timeId),
                tier: $classification->tier,
                kind: $classification->kind,
                detectedAt: $now,
                detectedBy: $message->detectedBy,
                snapshot: $candidate->snapshot(),
            ));

            $knownKeys[$key] = true;
            $newCases++;
        }

        $goneCases = 0;

        foreach ($this->caseRepository->findOpen() as $case) {
            if (!isset($matchingKeys[$case->pairKey()])) {
                $case->markGone($now);
                $goneCases++;
            }
        }

        return new DuplicateDetectionSummary(
            candidates: count($candidates),
            newCases: $newCases,
            goneCases: $goneCases,
        );
    }
}
