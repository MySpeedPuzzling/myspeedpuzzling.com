<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use SpeedPuzzling\Web\Exceptions\CanNotModifyOtherPlayersTime;
use SpeedPuzzling\Web\Message\UnmarkFirstAttempt;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Repository\PuzzleSolvingTimeRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class UnmarkFirstAttemptHandler
{
    public function __construct(
        private PlayerRepository $playerRepository,
        private PuzzleSolvingTimeRepository $puzzleSolvingTimeRepository,
    ) {
    }

    /**
     * @throws CanNotModifyOtherPlayersTime
     */
    public function __invoke(UnmarkFirstAttempt $message): void
    {
        $player = $this->playerRepository->get($message->playerId);
        $solvingTime = $this->puzzleSolvingTimeRepository->get($message->timeId);

        if ($solvingTime->canBeModifiedBy($player) === false) {
            throw new CanNotModifyOtherPlayersTime();
        }

        $solvingTime->unmarkFirstAttempt($player);
    }
}
