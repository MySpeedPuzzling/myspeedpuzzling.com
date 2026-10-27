<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use SpeedPuzzling\Web\Exceptions\CanNotModifyOtherPlayersTime;
use SpeedPuzzling\Web\Exceptions\PuzzleSolvingTimeNotFound;
use SpeedPuzzling\Web\Message\CompensateXpForDeletedSolve;
use SpeedPuzzling\Web\Message\DeletePuzzleSolvingTime;
use SpeedPuzzling\Web\Message\RecalculateBadgesForPlayer;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Repository\PuzzleSolvingTimeRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsMessageHandler]
readonly final class DeletePuzzleSolvingTimeHandler
{
    public function __construct(
        private PlayerRepository $playerRepository,
        private PuzzleSolvingTimeRepository $puzzleSolvingTimeRepository,
        private MessageBusInterface $commandBus,
    ) {
    }

    /**
     * @throws PuzzleSolvingTimeNotFound
     * @throws CanNotModifyOtherPlayersTime
     */
    public function __invoke(DeletePuzzleSolvingTime $message): void
    {
        $solvingTime = $this->puzzleSolvingTimeRepository->get($message->puzzleSolvingTimeId);
        $currentPlayer = $this->playerRepository->getByUserIdCreateIfNotExists($message->currentUserId);

        if ($currentPlayer->id->equals($solvingTime->player->id) === false) {
            throw new CanNotModifyOtherPlayersTime();
        }

        $puzzleId = $solvingTime->puzzle->id->toString();
        $solvingTimeId = $solvingTime->id->toString();
        $memberPlayerIds = $solvingTime->memberPlayerIds();

        $this->puzzleSolvingTimeRepository->delete($solvingTime, $message->via);

        // Achievements follow the current results (docs/features/badges.md) - a tier this result was needed for goes,
        // for every registered member of a pair/team result
        foreach ($memberPlayerIds as $memberPlayerId) {
            $this->commandBus->dispatch(new RecalculateBadgesForPlayer($memberPlayerId));
        }

        // The puzzle id and the members travel in the message — they are unrecoverable once the row is gone.
        $this->commandBus->dispatch(new CompensateXpForDeletedSolve($solvingTimeId, $puzzleId, $memberPlayerIds));
    }
}
