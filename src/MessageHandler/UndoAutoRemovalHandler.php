<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\PuzzleSolvingTime;
use SpeedPuzzling\Web\Exceptions\AutoRemovalCanNotBeUndone;
use SpeedPuzzling\Web\Exceptions\AutoRemovalNotFound;
use SpeedPuzzling\Web\Exceptions\CompetitionNotFound;
use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Message\RecalculateBadgesForPlayer;
use SpeedPuzzling\Web\Message\RecalculateXpForPlayer;
use SpeedPuzzling\Web\Message\UndoAutoRemoval;
use SpeedPuzzling\Web\Repository\CompetitionRepository;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Repository\PuzzleRepository;
use SpeedPuzzling\Web\Repository\PuzzleSolvingTimeRepository;
use SpeedPuzzling\Web\Repository\ResultAutoRemovalRepository;
use SpeedPuzzling\Web\Repository\ResultDuplicateCaseRepository;
use SpeedPuzzling\Web\Services\DuplicateResults\ResultReviewReactions;
use SpeedPuzzling\Web\Services\PuzzlingTeamResolver;
use SpeedPuzzling\Web\Services\RoundResults\SolvingTimeRoundResolver;
use SpeedPuzzling\Web\Value\RemovedResultSnapshot;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Undo of an automatic removal: the copy comes back with its own id and everything it had, the case becomes
 * `undone` (docs/features/duplicate-results.md). Only the tracker of the copy may bring it back.
 *
 * A competition deleted meanwhile is dropped; the round follows from competition + puzzle + group as on every
 * save, and the pair/team is resolved again from the stored group (the same people = the same team). A member of
 * that group who deleted their account meanwhile refuses the undo (AutoRemovalCanNotBeUndone): the snapshot knows
 * them only by id, so they could come back neither as themselves nor as a named guest - and the kept copy, the very
 * same result, already carries the group as the deletion left it.
 *
 * The cases of the pair are found by the pair, not by the stored case id - a case that is gone is simply skipped.
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
        private ResultReviewReactions $resultReviewReactions,
        private ClockInterface $clock,
        private PlayerRepository $playerRepository,
        private MessageBusInterface $messageBus,
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

        foreach ($snapshot->group()->puzzlers ?? [] as $puzzler) {
            if ($puzzler->playerId === null) {
                continue;
            }

            try {
                $this->playerRepository->get($puzzler->playerId);
            } catch (PlayerNotFound) {
                throw new AutoRemovalCanNotBeUndone();
            }
        }

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

        // The kept copy is the older one of the pair
        foreach ($this->caseRepository->findOfPair($removal->keptTimeId, $removal->removedTimeId) as $pairCase) {
            $pairCase->removalUndone($now);
        }

        $this->resultReviewReactions->recordFor($removal->player->id->toString());

        // The same id comes back: its XP was compensated at the removal, and neither a re-award (the id already
        // has ledger history) nor a chain rebuild (keeps compensations) gives it back - the full rebuild does
        // (docs/features/xp-levels/README.md). Badges follow the restored result too
        foreach ($time->memberPlayerIds() as $memberPlayerId) {
            $this->messageBus->dispatch(new RecalculateXpForPlayer($memberPlayerId));
            $this->messageBus->dispatch(new RecalculateBadgesForPlayer($memberPlayerId));
        }
    }
}
