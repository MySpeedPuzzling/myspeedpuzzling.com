<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\DuplicateResults;

use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Entity\ResultDuplicateCase;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Repository\ResultDuplicateCaseRepository;
use SpeedPuzzling\Web\Repository\ResultDuplicatePreventionRepository;
use SpeedPuzzling\Web\Results\DuplicateCandidate;
use SpeedPuzzling\Web\Value\DuplicateClassification;
use SpeedPuzzling\Web\Value\DuplicateDetectedBy;
use SpeedPuzzling\Web\Value\DuplicateResolvedVia;

/**
 * Turns duplicate candidates into stored cases - one code path for the daily detection and the detection right
 * after a save (docs/features/duplicate-results.md, "Detection"). A pair that already has a case, in any status,
 * is never raised again, so a decision sticks.
 *
 * A case of a result the person saved after the add form had told them the same time was already there
 * ("It's another solve, save it" - result_duplicate_prevention, kind saved_anyway) is confirmed real right away.
 *
 * Reads everything first and persists last: the detection at save time runs inside the save's transaction,
 * and a failing read there must not leave half of its cases in the unit of work.
 */
readonly final class DuplicateCaseRecorder
{
    public function __construct(
        private DuplicateClassifier $classifier,
        private ResultDuplicateCaseRepository $caseRepository,
        private ResultDuplicatePreventionRepository $preventionRepository,
        private PlayerRepository $playerRepository,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @param list<DuplicateCandidate> $candidates
     * @param array<string, true> $knownKeys every pair that already has a case (ResultDuplicateCase::key())
     * @return array{matching: array<string, true>, new: list<ResultDuplicateCase>} matching = the keys of every
     *     candidate that is a case (new or known)
     */
    public function record(array $candidates, array $knownKeys, DuplicateDetectedBy $detectedBy): array
    {
        $matching = [];
        /** @var list<array{candidate: DuplicateCandidate, classification: DuplicateClassification}> $toStore */
        $toStore = [];

        foreach ($candidates as $candidate) {
            $classification = $this->classifier->classify($candidate);

            if ($classification === null) {
                continue;
            }

            $key = ResultDuplicateCase::key($candidate->personId, $candidate->older->timeId, $candidate->newer->timeId);

            if (isset($matching[$key])) {
                continue;
            }

            $matching[$key] = true;

            if (!isset($knownKeys[$key])) {
                $toStore[] = ['candidate' => $candidate, 'classification' => $classification];
            }
        }

        if ($toStore === []) {
            return ['matching' => $matching, 'new' => []];
        }

        $timeIds = [];
        /** @var array<string, Player> $people */
        $people = [];

        foreach ($toStore as ['candidate' => $candidate]) {
            $timeIds[] = $candidate->older->timeId;
            $timeIds[] = $candidate->newer->timeId;
            $people[$candidate->personId] ??= $this->playerRepository->get($candidate->personId);
        }

        $savedAnyway = $this->preventionRepository->savedAnywayKeys(array_values(array_unique($timeIds)));
        $now = $this->clock->now();
        $new = [];

        foreach ($toStore as ['candidate' => $candidate, 'classification' => $classification]) {
            $case = new ResultDuplicateCase(
                id: Uuid::uuid7(),
                player: $people[$candidate->personId],
                timeAId: Uuid::fromString($candidate->older->timeId),
                timeBId: Uuid::fromString($candidate->newer->timeId),
                tier: $classification->tier,
                kind: $classification->kind,
                detectedAt: $now,
                detectedBy: $detectedBy,
                snapshot: $candidate->snapshot(),
            );

            if (
                isset($savedAnyway[$candidate->personId . '|' . $candidate->older->timeId])
                || isset($savedAnyway[$candidate->personId . '|' . $candidate->newer->timeId])
            ) {
                $case->confirmBothReal($now, DuplicateResolvedVia::Form);
            }

            $this->caseRepository->save($case);
            $new[] = $case;
        }

        return ['matching' => $matching, 'new' => $new];
    }
}
