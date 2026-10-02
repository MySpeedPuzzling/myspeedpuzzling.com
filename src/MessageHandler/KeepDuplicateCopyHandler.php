<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Exceptions\CanNotModifyOtherPlayersTime;
use SpeedPuzzling\Web\Exceptions\DuplicateCaseChanged;
use SpeedPuzzling\Web\Exceptions\DuplicateCaseNotFound;
use SpeedPuzzling\Web\Message\KeepDuplicateCopy;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Repository\PuzzleSolvingTimeRepository;
use SpeedPuzzling\Web\Repository\ResultDuplicateCaseRepository;
use SpeedPuzzling\Web\Services\RoundResults\SolvingTimeRoundResolver;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * "Keep this one" / "Delete my copy" (docs/features/duplicate-results.md, "Review page"): the other copy is
 * deleted - only by its tracker. When the player tracks the kept copy too, it first takes over what only the
 * deleted one had (photo, comment, first-try tag, competition), so nothing is lost with it.
 *
 * Every open case with the deleted copy is closed as copy_deleted - a teammate copy is a case for each member,
 * and the first deletion settles it for both.
 */
#[AsMessageHandler]
readonly final class KeepDuplicateCopyHandler
{
    public function __construct(
        private ResultDuplicateCaseRepository $caseRepository,
        private PuzzleSolvingTimeRepository $puzzleSolvingTimeRepository,
        private PlayerRepository $playerRepository,
        private SolvingTimeRoundResolver $roundResolver,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws DuplicateCaseNotFound
     * @throws DuplicateCaseChanged
     * @throws CanNotModifyOtherPlayersTime
     */
    public function __invoke(KeepDuplicateCopy $message): void
    {
        $case = $this->caseRepository->get($message->caseId);

        if ($case->player->id->toString() !== strtolower($message->playerId) || $case->involves($message->keepTimeId) === false) {
            throw new DuplicateCaseNotFound();
        }

        if ($case->isOpen() === false) {
            throw new DuplicateCaseChanged();
        }

        $kept = $this->puzzleSolvingTimeRepository->findById(Uuid::fromString($message->keepTimeId));
        $copy = $this->puzzleSolvingTimeRepository->findById(Uuid::fromString($case->otherTimeId($message->keepTimeId)));

        // Deleting one copy while the other is gone already would lose the result altogether
        if ($kept === null || $copy === null) {
            throw new DuplicateCaseChanged();
        }

        $player = $this->playerRepository->get($message->playerId);

        if ($copy->player->id->equals($player->id) === false) {
            throw new CanNotModifyOtherPlayersTime();
        }

        if ($kept->player->id->equals($player->id) && $kept->takeOverFrom($copy, $player)) {
            $kept->changeCompetitionRound($this->roundResolver->resolve($kept));
        }

        $now = $this->clock->now();

        foreach ($this->caseRepository->findOpenReferencing([$copy->id->toString()]) as $openCase) {
            $openCase->copyDeleted($now, $message->via);
        }

        $this->puzzleSolvingTimeRepository->delete($copy);
    }
}
