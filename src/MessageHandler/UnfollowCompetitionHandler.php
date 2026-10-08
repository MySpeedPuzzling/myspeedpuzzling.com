<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use SpeedPuzzling\Web\Message\UnfollowCompetition;
use SpeedPuzzling\Web\Repository\FollowedCompetitionRepository;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Value\FollowTarget;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Removes a follow when there is one - also for a target that is no longer publicly visible.
 */
#[AsMessageHandler]
readonly final class UnfollowCompetitionHandler
{
    public function __construct(
        private PlayerRepository $playerRepository,
        private FollowedCompetitionRepository $followedCompetitionRepository,
    ) {
    }

    public function __invoke(UnfollowCompetition $message): void
    {
        $target = FollowTarget::tryFromString($message->target);

        if ($target === null) {
            return;
        }

        $player = $this->playerRepository->get($message->playerId);
        $followed = $this->followedCompetitionRepository->find($player, $target);

        if ($followed !== null) {
            $this->followedCompetitionRepository->delete($followed);
        }
    }
}
