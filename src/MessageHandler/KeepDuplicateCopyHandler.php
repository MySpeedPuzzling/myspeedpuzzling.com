<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Entity\PuzzleSolvingTime;
use SpeedPuzzling\Web\Exceptions\CanNotModifyOtherPlayersTime;
use SpeedPuzzling\Web\Exceptions\DuplicateCaseChanged;
use SpeedPuzzling\Web\Exceptions\DuplicateCaseNotFound;
use SpeedPuzzling\Web\Message\CompensateXpForDeletedSolve;
use SpeedPuzzling\Web\Message\KeepDuplicateCopy;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Repository\PuzzleSolvingTimeRepository;
use SpeedPuzzling\Web\Repository\ResultDuplicateCaseRepository;
use SpeedPuzzling\Web\Results\DuplicateCopiesDeleted;
use SpeedPuzzling\Web\Services\DuplicateResults\DuplicateCaseSetResolver;
use SpeedPuzzling\Web\Services\DuplicateResults\ResultReviewReactions;
use SpeedPuzzling\Web\Services\FirstTry\FirstTryAssessor;
use SpeedPuzzling\Web\Services\RoundResults\SolvingTimeRoundResolver;
use SpeedPuzzling\Web\Value\FirstTryEntry;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * "Keep this one" / "Delete my copy" on a set of copies (docs/features/duplicate-results.md, "Review page"):
 * every other copy of the set the player tracks is deleted - a copy tracked by somebody else only by them. When the
 * player tracks the kept copy too, it first takes over what only the deleted ones had (photo, comment, competition,
 * and the first-try tag when it may move there), so nothing is lost with them.
 *
 * Every open case with a deleted copy is closed as copy_deleted, for everybody - a teammate copy is a case for each
 * member, and the first deletion settles it for all. A case between two copies that both stay (the kept one and a
 * copy only its tracker may delete, or two such copies) stays open: there are still two results, and their tracker
 * decides about their own copy.
 */
#[AsMessageHandler]
readonly final class KeepDuplicateCopyHandler
{
    public function __construct(
        private DuplicateCaseSetResolver $setResolver,
        private ResultDuplicateCaseRepository $caseRepository,
        private PuzzleSolvingTimeRepository $puzzleSolvingTimeRepository,
        private PlayerRepository $playerRepository,
        private FirstTryAssessor $firstTryAssessor,
        private SolvingTimeRoundResolver $roundResolver,
        private ResultReviewReactions $resultReviewReactions,
        private ClockInterface $clock,
        private MessageBusInterface $messageBus,
    ) {
    }

    /**
     * @throws DuplicateCaseNotFound
     * @throws DuplicateCaseChanged
     * @throws CanNotModifyOtherPlayersTime
     */
    public function __invoke(KeepDuplicateCopy $message): DuplicateCopiesDeleted
    {
        ['copies' => $copies] = $this->setResolver->resolve($message->caseId, $message->playerId, $message->copyTimeIds);

        $kept = $copies[strtolower($message->keepTimeId)] ?? throw new DuplicateCaseNotFound();
        $player = $this->playerRepository->get($message->playerId);

        $deleted = array_values(array_filter(
            $copies,
            static fn (PuzzleSolvingTime $copy): bool => $copy !== $kept && $copy->player->id->equals($player->id),
        ));

        // Nothing of the player's to delete - keeping it would delete somebody else's copy
        if ($deleted === []) {
            throw new CanNotModifyOtherPlayersTime();
        }

        // Oldest first: with several copies to take over from, the original's photo and comment win
        usort($deleted, static fn (PuzzleSolvingTime $a, PuzzleSolvingTime $b): int => $a->trackedAt <=> $b->trackedAt);

        $firstTryLeftOff = false;

        if ($kept->player->id->equals($player->id)) {
            $firstTryMayMove = $this->firstTryMayMove($kept, $deleted, $player);
            $firstTryLeftOff = $firstTryMayMove === false;

            if ($kept->takeOverFrom($deleted, $player, $firstTryMayMove)) {
                $kept->changeCompetitionRound($this->roundResolver->resolve($kept));
            }
        }

        $now = $this->clock->now();
        $deletedIds = array_map(static fn (PuzzleSolvingTime $copy): string => $copy->id->toString(), $deleted);

        foreach ($this->caseRepository->findOpenReferencing($deletedIds) as $openCase) {
            $openCase->copyDeleted($now, $message->via);
        }

        foreach ($deleted as $copy) {
            $copyPuzzleId = $copy->puzzle->id->toString();

            $this->puzzleSolvingTimeRepository->delete($copy);

            // Every member of a pair/team copy earned XP for it (docs/features/xp-levels/README.md) - compensated
            // like a result deleted by hand
            $this->messageBus->dispatch(new CompensateXpForDeletedSolve($copy->id->toString(), $copyPuzzleId));
        }

        $this->resultReviewReactions->recordFor($player->id->toString());

        return new DuplicateCopiesDeleted(deleted: count($deleted), firstTryLeftOff: $firstTryLeftOff);
    }

    /**
     * Whether a deleted copy's first-try tag may move to the kept one. A solo result: always - it was the player's
     * own first try, nobody else is touched. A pair/team result is a first try for everybody in it, so it must not
     * become a second first try of any of them (docs/features/first-try-integrity.md): the first-try rules decide,
     * the copies deleted now do not count.
     *
     * @param list<PuzzleSolvingTime> $deleted
     * @return bool false only when there is a tag to move and it may not
     */
    private function firstTryMayMove(PuzzleSolvingTime $kept, array $deleted, Player $player): bool
    {
        $tagged = array_filter($deleted, static fn (PuzzleSolvingTime $copy): bool => $copy->firstAttempt);

        if ($kept->firstAttempt || $tagged === [] || $kept->team === null) {
            return true;
        }

        $assessment = $this->firstTryAssessor->assess(new FirstTryEntry(
            actorPlayerId: $player->id->toString(),
            puzzleId: $kept->puzzle->id->toString(),
            memberPlayerIds: array_map('strtolower', $kept->memberPlayerIds()),
            solvedAt: $kept->finishedAt ?? $kept->trackedAt,
            editedTimeId: $kept->id->toString(),
        ));

        $deletedIds = array_map(static fn (PuzzleSolvingTime $copy): string => $copy->id->toString(), $deleted);

        foreach ($assessment->holds as $hold) {
            if (in_array($hold->timeId, $deletedIds, true) === false) {
                return false;
            }
        }

        return true;
    }
}
