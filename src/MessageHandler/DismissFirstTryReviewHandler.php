<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\FirstTryReviewDismissal;
use SpeedPuzzling\Web\Exceptions\CanNotModifyOtherPlayersTime;
use SpeedPuzzling\Web\Message\DismissFirstTryReview;
use SpeedPuzzling\Web\Repository\FirstTryReviewDismissalRepository;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Repository\PuzzleSolvingTimeRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class DismissFirstTryReviewHandler
{
    public function __construct(
        private PlayerRepository $playerRepository,
        private PuzzleSolvingTimeRepository $puzzleSolvingTimeRepository,
        private FirstTryReviewDismissalRepository $dismissalRepository,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws CanNotModifyOtherPlayersTime
     */
    public function __invoke(DismissFirstTryReview $message): void
    {
        $player = $this->playerRepository->get($message->playerId);
        $solvingTime = $this->puzzleSolvingTimeRepository->get($message->timeId);

        if ($solvingTime->canBeModifiedBy($player) === false) {
            throw new CanNotModifyOtherPlayersTime();
        }

        if ($this->dismissalRepository->find($player, $solvingTime) !== null) {
            return;
        }

        $this->dismissalRepository->save(new FirstTryReviewDismissal(
            Uuid::uuid7(),
            $player,
            $solvingTime,
            $this->clock->now(),
        ));
    }
}
