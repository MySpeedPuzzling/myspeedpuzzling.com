<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\PuzzleSolvingTime;
use SpeedPuzzling\Web\Exceptions\AutoRemovalCanNotBeUndone;
use SpeedPuzzling\Web\Exceptions\AutoRemovalNotFound;
use SpeedPuzzling\Web\Exceptions\CompetitionNotFound;
use SpeedPuzzling\Web\Message\UndoAutoRemoval;
use SpeedPuzzling\Web\Repository\CompetitionRepository;
use SpeedPuzzling\Web\Repository\PuzzleRepository;
use SpeedPuzzling\Web\Repository\PuzzleSolvingTimeRepository;
use SpeedPuzzling\Web\Repository\ResultAutoRemovalRepository;
use SpeedPuzzling\Web\Repository\ResultDuplicateCaseRepository;
use SpeedPuzzling\Web\Services\PuzzlingTeamResolver;
use SpeedPuzzling\Web\Services\RoundResults\SolvingTimeRoundResolver;
use SpeedPuzzling\Web\Value\RemovedResultSnapshot;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Undo of an automatic removal: the copy comes back with its own id and everything it had, the case becomes
 * `undone` (docs/features/duplicate-results.md). Only the tracker of the copy may bring it back.
 *
 * A competition deleted meanwhile is dropped; the round follows from competition + puzzle + group as on every
 * save, and the pair/team is resolved again from the stored group (the same people = the same team).
 */
#[AsMessageHandler]
readonly final class UndoAutoRemovalHandler
{
    public function __construct(
        private ResultAutoRemovalRepository $autoRemovalRepository,
        private ResultDuplicateCaseRepository $caseRepository,
        private PuzzleSolvingTimeRepository $puzzleSolvingTimeRepository,
        private PuzzleRepository $puzzleRepository,
        private CompetitionRepository $competitionRepository,
        private PuzzlingTeamResolver $puzzlingTeamResolver,
        private SolvingTimeRoundResolver $roundResolver,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws AutoRemovalNotFound
     * @throws AutoRemovalCanNotBeUndone
     */
    public function __invoke(UndoAutoRemoval $message): void
    {
        $removal = $this->autoRemovalRepository->get($message->removalId);

        if ($removal->player->id->toString() !== strtolower($message->playerId)) {
            throw new AutoRemovalNotFound();
        }

        if ($removal->isUndone() || $this->puzzleSolvingTimeRepository->findById($removal->removedTimeId) !== null) {
            throw new AutoRemovalCanNotBeUndone();
        }

        $snapshot = RemovedResultSnapshot::fromArray($removal->snapshot);
        $puzzle = $this->puzzleRepository->findById(Uuid::fromString($snapshot->puzzleId))
            ?? throw new AutoRemovalCanNotBeUndone();

        $competition = null;

        if ($snapshot->competitionId !== null) {
            try {
                $competition = $this->competitionRepository->get($snapshot->competitionId);
            } catch (CompetitionNotFound) {
                // Deleted meanwhile - the result comes back without it
            }
        }

        $time = PuzzleSolvingTime::restore(
            snapshot: $snapshot,
            player: $removal->player,
            puzzle: $puzzle,
            competition: $competition,
            // Last: the team is created outside the unit of work
            puzzlingTeam: $this->puzzlingTeamResolver->resolve($snapshot->group(), usedByPlayerId: $removal->player->id->toString()),
        );
        $time->changeCompetitionRound($this->roundResolver->resolve($time));

        $this->puzzleSolvingTimeRepository->save($time);

        $now = $this->clock->now();
        $removal->undo($now);

        $case = $this->caseRepository->get($removal->caseId->toString());

        foreach ($this->caseRepository->findOfPair($case->timeAId, $case->timeBId) as $pairCase) {
            $pairCase->removalUndone($now);
        }
    }
}
