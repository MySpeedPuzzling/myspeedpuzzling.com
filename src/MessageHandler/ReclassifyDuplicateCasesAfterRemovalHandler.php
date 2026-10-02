<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use SpeedPuzzling\Web\Entity\ResultDuplicateCase;
use SpeedPuzzling\Web\Message\ReclassifyDuplicateCasesAfterRemoval;
use SpeedPuzzling\Web\Query\GetDuplicateCandidates;
use SpeedPuzzling\Web\Repository\ResultDuplicateCaseRepository;
use SpeedPuzzling\Web\Services\DuplicateResults\DuplicateClassifier;
use SpeedPuzzling\Web\Value\DuplicateTier;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * A triplet of re-sends T1 < T2 < T3: (T1, T2) and (T2, T3) are certain, (T1, T3) only strong - T2 was saved in
 * between. Once T2 is removed, (T1, T3) is the same form sent again too, and is removed on the same run
 * (docs/features/duplicate-results.md, "Automatic removal").
 *
 * The scoped candidate query of the removal, for the people of the open cases with the kept copy; a pair that no
 * longer matches is left for the daily run to close as gone.
 */
#[AsMessageHandler]
readonly final class ReclassifyDuplicateCasesAfterRemovalHandler
{
    public function __construct(
        private ResultDuplicateCaseRepository $caseRepository,
        private GetDuplicateCandidates $getDuplicateCandidates,
        private DuplicateClassifier $classifier,
    ) {
    }

    /**
     * @return list<string> the open Tier A cases with the kept copy
     */
    public function __invoke(ReclassifyDuplicateCasesAfterRemoval $message): array
    {
        $removedCase = $this->caseRepository->get($message->caseId);
        $puzzleId = $removedCase->snapshot['puzzle_id'] ?? null;
        // The older copy is the one that stays
        $openCases = $this->caseRepository->findOpenReferencing([$removedCase->timeAId->toString()]);

        if (!is_string($puzzleId) || $openCases === []) {
            return [];
        }

        $people = array_values(array_unique(array_map(
            static fn (ResultDuplicateCase $case): string => $case->player->id->toString(),
            $openCases,
        )));

        $candidates = [];

        foreach ($this->getDuplicateCandidates->ofPeopleOnPuzzle($puzzleId, $people) as $candidate) {
            $candidates[ResultDuplicateCase::key($candidate->personId, $candidate->older->timeId, $candidate->newer->timeId)] = $candidate;
        }

        $certainCaseIds = [];

        foreach ($openCases as $case) {
            $candidate = $candidates[$case->pairKey()] ?? null;
            $classification = $candidate !== null ? $this->classifier->classify($candidate) : null;

            if ($candidate === null || $classification === null) {
                continue;
            }

            $case->reclassify($classification, $candidate->snapshot());

            if ($case->tier === DuplicateTier::Certain) {
                $certainCaseIds[] = $case->id->toString();
            }
        }

        return $certainCaseIds;
    }
}
