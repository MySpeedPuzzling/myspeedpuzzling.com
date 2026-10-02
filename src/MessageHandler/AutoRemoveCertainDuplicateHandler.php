<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\ResultAutoRemoval;
use SpeedPuzzling\Web\Message\AutoRemoveCertainDuplicate;
use SpeedPuzzling\Web\Query\GetDuplicateCandidates;
use SpeedPuzzling\Web\Repository\PuzzleSolvingTimeRepository;
use SpeedPuzzling\Web\Repository\ResultAutoRemovalRepository;
use SpeedPuzzling\Web\Repository\ResultDuplicateCaseRepository;
use SpeedPuzzling\Web\Services\DuplicateResults\DuplicateClassifier;
use SpeedPuzzling\Web\Value\DuplicateTier;
use SpeedPuzzling\Web\Value\RemovedResultSnapshot;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Tier A - the same form sent again: the newer copy goes, the older one stays (docs/features/duplicate-results.md,
 * "Automatic removal"). Re-checked right before removing: if the pair is not certain any more (edited, another
 * result saved in between meanwhile), the case is left open for the player to decide.
 *
 * Everything needed to bring the copy back is stored first (UndoAutoRemoval). The removal goes the same way as
 * deleting a result by hand, so statistics and insights follow; the open cases of other pairs with that copy are
 * closed as gone by DetectDuplicateResultsOnSave.
 */
#[AsMessageHandler]
readonly final class AutoRemoveCertainDuplicateHandler
{
    public function __construct(
        private ResultDuplicateCaseRepository $caseRepository,
        private ResultAutoRemovalRepository $autoRemovalRepository,
        private PuzzleSolvingTimeRepository $puzzleSolvingTimeRepository,
        private GetDuplicateCandidates $getDuplicateCandidates,
        private DuplicateClassifier $classifier,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @return bool whether the copy was removed
     */
    public function __invoke(AutoRemoveCertainDuplicate $message): bool
    {
        $case = $this->caseRepository->get($message->caseId);

        if ($case->isOpen() === false || $case->tier !== DuplicateTier::Certain || $this->isStillCertain($case->snapshot, $case->player->id->toString(), $case->timeAId->toString(), $case->timeBId->toString()) === false) {
            return false;
        }

        $kept = $this->puzzleSolvingTimeRepository->findById($case->timeAId);
        $copy = $this->puzzleSolvingTimeRepository->findById($case->timeBId);

        if ($kept === null || $copy === null) {
            return false;
        }

        $now = $this->clock->now();

        $this->autoRemovalRepository->save(new ResultAutoRemoval(
            id: Uuid::uuid7(),
            player: $copy->player,
            removedTimeId: $copy->id,
            keptTimeId: $kept->id,
            caseId: $case->id,
            snapshot: RemovedResultSnapshot::of($copy)->toArray(),
            removedAt: $now,
        ));

        // A pair/team copy is a case for each member - the removal closes it for everybody
        foreach ($this->caseRepository->findOfPair($case->timeAId, $case->timeBId) as $pairCase) {
            $pairCase->autoRemoved($now);
        }

        $this->puzzleSolvingTimeRepository->delete($copy);

        return true;
    }

    /**
     * @param array<string, mixed> $snapshot
     */
    private function isStillCertain(array $snapshot, string $personId, string $olderId, string $newerId): bool
    {
        $puzzleId = $snapshot['puzzle_id'] ?? null;

        if (!is_string($puzzleId)) {
            return false;
        }

        foreach ($this->getDuplicateCandidates->ofPeopleOnPuzzle($puzzleId, [$personId]) as $candidate) {
            if ($candidate->older->timeId === $olderId && $candidate->newer->timeId === $newerId) {
                return $this->classifier->classify($candidate)?->tier === DuplicateTier::Certain;
            }
        }

        return false;
    }
}
